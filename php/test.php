<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

$db = new SQLite3('../GVD2022.sqlite');

$time = microtime(true);

if (isset($_GET['date'])) {
    $date = $_GET['date'];
} else {
    $date = date("Ymd");
    header('Location: ?date='.$date.$rrid);
}
$pdate = date("d.m.Y",strtotime($date));

if (isset($_GET['stop_id'])) {
    $sid = $_GET['stop_id'];
    $cur = $db->query("SELECT * FROM stops WHERE stop_id = '$sid'");
    if ($row = $cur->fetchArray()) {
        $stop_name = $row['stop_name'];
    }
} else {
    $sql = 'SELECT s.stop_id,stop_name FROM stop_times st LEFT JOIN stops s ON st.stop_id = s.stop_id WHERE pickup_type != 1 ORDER BY RANDOM() LIMIT 1';
    $cur = $db->query($sql);
    if (  $row = $cur->fetchArray() ) {
        $sid = $row[0];
        $stop_name = $row[1];
    }
}

$tat = array("0026" => "Celní a pasové odbavení", "0028" => "Zastavení jen pro nástup", "0029" => "Zastavení jen pro výstup", "0030" => "Zastavení jen na znamení", "0031" => "Odjezd v čase příjezdu", "0032" => "Odjezd ihned po výstupu", "0033" => "Nečeká na žádné přípoje");

function ta($str) {
    GLOBAL $tat;
    $r = array();
    foreach ($tat as $k => $v) {
        if (strpos($str, $k) !== FALSE) {
                $r[] = $v;
            }
    }
    return join('<br/>', $r);
}

function serid($date) {
    $den =  strtolower(date('l', strtotime($date)));
    return "(SELECT service_id FROM calendar WHERE start_date <= $date AND end_date >= $date AND $den = 1 UNION SELECT service_id FROM calendar_dates WHERE date = $date AND exception_type = 1 EXCEPT SELECT service_id FROM calendar_dates WHERE date = $date AND exception_type = 2)";
}

function compare($a, $b) {
    if ($a['departure_time']==$b['departure_time']) return 0;
    return ($a['departure_time']>$b['departure_time'])?1:-1;
}

function poz($tid) {
    global $db;
    $sql = "SELECT * FROM nspec AS ns
    LEFT JOIN PoznamkyKJR AS po ON ns.Kod = po.Kod 
    WHERE trip_id = '$tid'";
    $cur = $db->query($sql);
    $poz = array();
    while ($row = $cur->fetchArray(true)) {
        if ($row['Nazev'] != '') {
            $poz[] = $row['Nazev'];
        }
    }
    if (count($poz) > 0) {
        return "pozn: ".join(", ", $poz);
    } else {
        return '';
    }
}

$title = $stop_name;
$tid = null;
$trow = null;
if (isset($_GET['trip_id'])) {
    $tid = $_GET['trip_id'];
    $cur = $db->query("SELECT * FROM trips LEFT JOIN agency ON trips.company = agency.agency_id  WHERE trip_id = '$tid'");
    if ($trow = $cur->fetchArray()) {
        //print_r($trow);
        $title = $trow['tln'].' - '. $trow['agency_name'];
    } else {
        echo "Nenalezen";
    }
}

echo '<html><head><title>'." $title - $pdate".'</title><style>
body {
    font-family:sans-serif;
         }
.dopr {
    color: gray;
 }
 
table, th, td {
border: 1px solid black;
border-collapse: collapse;
}

td {
padding: 6px;
border-spacing: 8px;
}
</style></head>';

if ($trow != null) {
    $cur = $db->query("SELECT * FROM stop_times st INNER JOIN stops s ON st.stop_id = s.stop_id WHERE trip_id = '$tid' AND (pickup_type != 1 AND drop_off_type != 1) ORDER BY stop_sequence ASC");
    echo '<center><h2>'.$trow['tln'].'</h2>'.$trow['agency_name'].'<table>';
    echo '<tr> <th><small>prij.</small></th> <th> odjezd </th> <th> stanice </th> <th> </th>   </tr>';
    while ($row = $cur->fetchArray()) {
        $pr = ($row['departure_time'] == $row['arrival_time']) ? "": $row['arrival_time'];
        echo "<tr><td>$pr</td><td><b>{$row['departure_time']}</b></td> <td><b><a href=\"?stop_id={$row['stop_id']}&amp;date=$date\">".$row['stop_name']."</a></b></td>";
        echo '<td>'. ta($row['tat']) .'</td>';
        echo '</tr>';
    }
    echo '</table>';
    echo poz($tid);
    echo "<br/><br/>";
} else {

    echo '<body>';
    echo '<center>';
    echo "<h2>$stop_name</h2>\n";
    echo "<h4>$pdate</h4>\n";
    
    
    $sql = "WITH sq AS
        (SELECT tr.service_id,st.trip_id,st.departure_time,st.arrival_time prijezd,st.tat,st.tt,st.pickup_type,s.stop_name,lst.arrival_time,lsts.stop_name dostan,tr.tln,a.agency_short_name,a.agency_name,l.Znacka,l.Nazev FROM stop_times st
        INNER JOIN trips AS tr ON tr.trip_id = st.trip_id
        INNER JOIN stop_times AS lst ON tr.trip_id = lst.trip_id
        LEFT JOIN stops s ON st.stop_id = s.stop_id
        LEFT JOIN stops lsts ON lst.stop_id = lsts.stop_id
        LEFT JOIN agency a ON a.agency_id = tr.company
        LEFT JOIN Linky l ON st.psn = l.Kod
        WHERE st.stop_id = '$sid'
        AND lst.rowid = (SELECT MAX(rowid) FROM stop_times WHERE tr.trip_id = trip_id AND drop_off_type = 0))
        SELECT * FROM sq
        
        WHERE (service_id IN  ".serid($date)."  AND departure_time < '24:00:00')
        OR service_id IN  ".serid(date("Ymd",strtotime($date."- 1 days")))."  AND departure_time >= '24:00:00'
        
        LIMIT 3000";


    //print_r($sql);
    $cur = $db->query($sql);
    $arr = array();
    while ($row = $cur->fetchArray(true)) {
        //print_r($row);
        if ($row['departure_time'] >= '24:00:00') {
            $ex = explode(':', $row['departure_time']);
            $ex[0] -= 24;
            $row['departure_time'] = str_pad(implode(':', $ex),8,'0',STR_PAD_LEFT);
        }
        $arr[$row['trip_id']] = $row;
    }

    uasort($arr, 'compare');

    #print_r($arr);

    $rc = array('R' => 'color:#f11', 'Sp' => 'color:green', 'Ex' => 'color:green', 'Os' => 'color:#11f', 'C4' => 'color:gray;');
    echo '<table>
        <tr> <th>prijezd</th> <th>odjezd</th> <th> Vlak </th> <th>linka</th>  <th>  do stanice</th> <th><small>prj.</small> </th> <th> </th> </tr>';

    foreach ($arr as $i => $v) {
        if ($v['pickup_type'] != '1') {
            $st = " style=\"".$rc[ $v['tt'] ].'"';
            $prij = ($v['prijezd'] != $v['departure_time']) ? $v['prijezd'] : '';
            echo ' <tr> 
                <td> '.$prij.'</td>
                <td><b>'.$v['departure_time'].'</b></td> <td '.$st.'> <b><a '.$st.' href="test.php?trip_id='.$i.'&amp;date='.$date.'&amp;stop_id='.$sid.'">'.$v['tln'].'</a> </b><span class="dopr" title="'.$v['agency_name'].'">'.$v['agency_short_name'].' </span></td> <td title="'.$v['Nazev'].'"><b> '.$v['Znacka'].'</b></td>  <td>  '.$v['dostan'].'</td>
                
                <td><small>'.$v['arrival_time'].'</small></td><td><small>'.ta($v['tat']).'</small></td></tr>'."\n";
        }
    }
    echo '</table></center>';

}
$t = microtime(true) - $time;
echo $t;
echo '</body> </html>';
?>
