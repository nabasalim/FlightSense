<?php
// XAMPP defaults: host=localhost, user=root, password is empty.
$DB_HOST = '127.0.0.1';
$DB_NAME = 'flight_delay_db';
$DB_USER = 'root';
$DB_PASS = '';

function db(): ?PDO {
    static $pdo = null;
    global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS;
    if ($pdo instanceof PDO) return $pdo;
    try {
        $pdo = new PDO("mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4", $DB_USER, $DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    } catch (Throwable $e) { return null; }
}

function demo_flights(): array {
    return [
        ['id'=>1,'flight_number'=>'BA2490','airline'=>'British Airways','origin'=>'LHR','destination'=>'JFK','departure_time'=>'08:40','arrival_time'=>'11:20','weather'=>'Clear','traffic'=>'Medium','historical_delays'=>4,'status'=>'On time'],
        ['id'=>2,'flight_number'=>'EK017','airline'=>'Emirates','origin'=>'DXB','destination'=>'MAN','departure_time'=>'14:10','arrival_time'=>'18:35','weather'=>'Rain','traffic'=>'High','historical_delays'=>12,'status'=>'Delayed'],
        ['id'=>3,'flight_number'=>'QR328','airline'=>'Qatar Airways','origin'=>'DOH','destination'=>'LHR','departure_time'=>'22:15','arrival_time'=>'03:10','weather'=>'Clear','traffic'=>'Low','historical_delays'=>2,'status'=>'On time'],
        ['id'=>4,'flight_number'=>'KQ101','airline'=>'Kenya Airways','origin'=>'NBO','destination'=>'LHR','departure_time'=>'09:30','arrival_time'=>'16:20','weather'=>'Storm','traffic'=>'High','historical_delays'=>18,'status'=>'Delayed'],
        ['id'=>5,'flight_number'=>'ET701','airline'=>'Ethiopian Airlines','origin'=>'ADD','destination'=>'DXB','departure_time'=>'16:45','arrival_time'=>'21:15','weather'=>'Cloudy','traffic'=>'Medium','historical_delays'=>7,'status'=>'On time'],
    ];
}
function all_flights(): array {
    $pdo=db();
    if (!$pdo) return demo_flights();
    try { return $pdo->query('SELECT * FROM flights ORDER BY id DESC')->fetchAll(); } catch(Throwable $e) { return demo_flights(); }
}
function find_flight(string $number): ?array {
    $pdo=db();
    if($pdo) {
        try {
            $stmt=$pdo->prepare("SELECT *, CAST(op_carrier_fl_num AS UNSIGNED) AS flight_num, (SELECT COUNT(*) FROM flight_data_2024 h WHERE h.op_unique_carrier=d.op_unique_carrier AND h.op_carrier_fl_num=d.op_carrier_fl_num AND h.arr_delay>15) AS historical_delays FROM flight_data_2024 d WHERE CONCAT(d.op_unique_carrier, CAST(d.op_carrier_fl_num AS UNSIGNED))=? ORDER BY d.fl_date DESC LIMIT 1");
            $stmt->execute([strtoupper(trim($number))]);
            $r=$stmt->fetch();
            if($r) {
                $weather=((float)$r['weather_delay']>0)?'Storm':(((float)$r['arr_delay']>15)?'Rain':'Clear');
                $traffic=((float)$r['nas_delay']>30)?'High':(((float)$r['nas_delay']>0)?'Medium':'Low');
                $status=((int)$r['cancelled']===1)?'Cancelled':(((float)$r['arr_delay']>15)?'Delayed':'On time');
                return ['id'=>$r['id'],'flight_number'=>strtoupper($r['op_unique_carrier']).(int)$r['flight_num'],'airline'=>$r['op_unique_carrier'],'origin'=>$r['origin'],'destination'=>$r['dest'],'departure_time'=>str_pad((string)(int)$r['crs_dep_time'],4,'0',STR_PAD_LEFT),'arrival_time'=>str_pad((string)(int)$r['crs_arr_time'],4,'0',STR_PAD_LEFT),'weather'=>$weather,'traffic'=>$traffic,'historical_delays'=>(int)$r['historical_delays'],'status'=>$status,'flight_date'=>$r['fl_date'],'delay_minutes'=>$r['arr_delay'],'distance'=>$r['distance'],'dataset_source'=>'Kaggle Flight Data 2024'];
            }
        } catch(Throwable $e) { /* Dataset table is not imported yet; use manual records below. */ }
    }
    foreach (all_flights() as $f) if (strcasecmp($f['flight_number'],$number)===0) return $f;
    return null;
}
function add_flight(array $input): array {
    $required=['flight_number','airline','origin','destination','departure_time','arrival_time','weather','traffic','historical_delays','status'];
    foreach($required as $key) if(trim((string)($input[$key]??''))==='') return ['ok'=>false,'message'=>'Please complete every flight field before saving.'];
    $pdo=db();
    if(!$pdo) return ['ok'=>false,'message'=>'MySQL is not connected. Import database/schema.sql and start MySQL in XAMPP first.'];
    try {
        $stmt=$pdo->prepare('INSERT INTO flights (flight_number,airline,origin,destination,departure_time,arrival_time,weather,traffic,historical_delays,status) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([trim($input['flight_number']),trim($input['airline']),strtoupper(trim($input['origin'])),strtoupper(trim($input['destination'])),trim($input['departure_time']),trim($input['arrival_time']),trim($input['weather']),trim($input['traffic']),max(0,(int)$input['historical_delays']),trim($input['status'])]);
        return ['ok'=>true,'message'=>'Flight record saved successfully.'];
    } catch(Throwable $e) { return ['ok'=>false,'message'=>'Could not save this flight. The flight number may already exist.']; }
}
function predict_flight(array $f): array {
    $weather=strtolower($f['weather']??'clear'); $traffic=strtolower($f['traffic']??'low');
    $history=(int)($f['historical_delays']??0);
    $score=0; $reasons=[];
    if (in_array($weather,['storm','rain','bad','snow'])) { $score+=45; $reasons[]='Adverse weather conditions'; }
    elseif ($weather==='cloudy') { $score+=15; $reasons[]='Cloud cover'; }
    if ($traffic==='high') { $score+=30; $reasons[]='High air traffic'; }
    elseif ($traffic==='medium') { $score+=12; $reasons[]='Moderate air traffic'; }
    if ($history>=10) { $score+=25; $reasons[]='Frequent historical delays'; }
    elseif ($history>=5) { $score+=12; $reasons[]='Some historical delays'; }
    $score=min(98,max(8,$score+8));
    $level=$score>=65?'High':($score>=35?'Medium':'Low');
    return ['score'=>$score,'level'=>$level,'reasons'=>$reasons?:['No significant risk factors found'],'expected'=>$level==='High'?'45–90 min':($level==='Medium'?'20–45 min':'Under 20 min')];
}

/** Scheduled-only historical lookup, used to prefill (not score) the flight form. */
function lookup_predeparture_schedule(string $number): ?array {
    $pdo = db();
    if (!$pdo) return null;
    try {
        $query = 'SELECT fl_date, op_unique_carrier, op_carrier_fl_num, origin, dest, crs_dep_time, crs_elapsed_time, distance FROM flight_data_2024 WHERE CONCAT(op_unique_carrier, CAST(op_carrier_fl_num AS UNSIGNED)) = ? ORDER BY fl_date DESC LIMIT 1';
        $stmt = $pdo->prepare($query);
        $stmt->execute([$number]);
        $r = $stmt->fetch();
        if (!$r) return null;
        $clock = str_pad((string)(int)$r['crs_dep_time'], 4, '0', STR_PAD_LEFT);
        if ($clock === '2400') $clock = '0000';
        return [
          'flight_number' => $number,
          'op_unique_carrier' => $r['op_unique_carrier'], 'origin' => $r['origin'], 'dest' => $r['dest'],
          'flight_date' => $r['fl_date'],
          'departure_time' => substr($clock, 0, 2) . ':' . substr($clock, 2, 2),
          'crs_elapsed_time' => (string)(float)$r['crs_elapsed_time'],
          'distance' => (string)(float)$r['distance'],
        ];
    } catch (Throwable $e) { return null; }
}
