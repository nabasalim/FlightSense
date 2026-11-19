USE flight_delay_db;

-- 1) Download the full Kaggle file and unzip it to:
-- C:/xampp/htdocs/flight-delay-dashboard/data/flight_data_2024.csv
-- The project also includes data/flight_data_2024_sample.csv for a quick 10,000-row test.
-- 2) Change the filename below to the full file or sample file.
-- 3) Run this script after database/schema.sql.
-- If MySQL reports that LOCAL INFILE is disabled, enable local_infile in XAMPP MySQL.

LOAD DATA LOCAL INFILE 'C:/xampp/htdocs/flight-delay-dashboard/data/flight_data_2024_sample.csv'
INTO TABLE flight_data_2024
FIELDS TERMINATED BY ',' ENCLOSED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(year,month,day_of_month,day_of_week,fl_date,op_unique_carrier,op_carrier_fl_num,origin,origin_city_name,origin_state_nm,dest,dest_city_name,dest_state_nm,crs_dep_time,dep_time,dep_delay,taxi_out,wheels_off,wheels_on,taxi_in,crs_arr_time,arr_time,arr_delay,cancelled,cancellation_code,diverted,crs_elapsed_time,actual_elapsed_time,air_time,distance,carrier_delay,weather_delay,nas_delay,security_delay,late_aircraft_delay);

-- For the full dataset, replace the filename with:
-- C:/xampp/htdocs/flight-delay-dashboard/data/flight_data_2024.csv
