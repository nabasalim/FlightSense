<?php
/**
 * XAMPP-safe Kaggle CSV importer.
 * Usage from Command Prompt:
 *   C:\xampp\php\php.exe database\import_kaggle.php data\flight_data_2024_sample.csv
 * Or open locally:
 *   http://localhost/flight-delay-dashboard/database/import_kaggle.php?file=sample
 */
// Import may modify millions of database records: never expose it to anonymous HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Web import disabled for security. Use PHP CLI as described in README_XAMPP.md.');
}
set_time_limit(0);
ini_set('memory_limit','512M');
require_once __DIR__.'/../config/database.php';
$pdo=db();
if(!$pdo) die("Database connection failed. Start MySQL and check config/database.php.\n");
$path=$argv[1]??($_GET['file']??'');
if($path==='sample') $path=__DIR__.'/../data/flight_data_2024_sample.csv';
elseif($path==='full') $path=__DIR__.'/../data/flight_data_2024.csv';
elseif($path && !preg_match('/^([A-Za-z]:[\\\/]|\/)/',$path)) $path=__DIR__.'/../'.$path;
if(!$path || !is_file($path)) die("CSV file not found. Use sample, full, or provide a CSV path.\n");
$fh=fopen($path,'rb');
$header=fgetcsv($fh);
if(!$header || count($header)!==35) die("Unexpected CSV header. Expected 35 columns.\n");
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
$sql='INSERT INTO flight_data_2024 (year,month,day_of_month,day_of_week,fl_date,op_unique_carrier,op_carrier_fl_num,origin,origin_city_name,origin_state_nm,dest,dest_city_name,dest_state_nm,crs_dep_time,dep_time,dep_delay,taxi_out,wheels_off,wheels_on,taxi_in,crs_arr_time,arr_time,arr_delay,cancelled,cancellation_code,diverted,crs_elapsed_time,actual_elapsed_time,air_time,distance,carrier_delay,weather_delay,nas_delay,security_delay,late_aircraft_delay) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
$stmt=$pdo->prepare($sql); $count=0; $batch=0; $started=microtime(true); $pdo->beginTransaction();
while(($row=fgetcsv($fh))!==false){
    if(count($row)!==35) continue;
    foreach($row as $i=>$v) if($v==='') $row[$i]=null;
    $stmt->execute($row); $count++; $batch++;
    if($batch>=500){$pdo->commit(); $pdo->beginTransaction(); $batch=0; if($count%5000===0) echo "Imported {$count} rows\n";}
}
if($pdo->inTransaction()) $pdo->commit(); $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); fclose($fh);
echo "Completed: {$count} rows imported in ".round(microtime(true)-$started,1)." seconds.\n";
