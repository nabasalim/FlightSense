CREATE DATABASE IF NOT EXISTS flight_delay_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE flight_delay_db;
CREATE TABLE IF NOT EXISTS flights (
 id INT AUTO_INCREMENT PRIMARY KEY,
 flight_number VARCHAR(20) NOT NULL UNIQUE,
 airline VARCHAR(100) NOT NULL,
 origin VARCHAR(10) NOT NULL,
 destination VARCHAR(10) NOT NULL,
 departure_time VARCHAR(10) NOT NULL,
 arrival_time VARCHAR(10) NOT NULL,
 weather VARCHAR(30) NOT NULL DEFAULT 'Clear',
 traffic VARCHAR(30) NOT NULL DEFAULT 'Low',
 historical_delays INT NOT NULL DEFAULT 0,
 status VARCHAR(30) NOT NULL DEFAULT 'On time',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_flight_number (flight_number), INDEX idx_origin (origin), INDEX idx_status (status)
);
INSERT IGNORE INTO flights (flight_number,airline,origin,destination,departure_time,arrival_time,weather,traffic,historical_delays,status) VALUES
('BA2490','British Airways','LHR','JFK','08:40','11:20','Clear','Medium',4,'On time'),
('EK017','Emirates','DXB','MAN','14:10','18:35','Rain','High',12,'Delayed'),
('QR328','Qatar Airways','DOH','LHR','22:15','03:10','Clear','Low',2,'On time'),
('KQ101','Kenya Airways','NBO','LHR','09:30','16:20','Storm','High',18,'Delayed'),
('ET701','Ethiopian Airlines','ADD','DXB','16:45','21:15','Cloudy','Medium',7,'On time');
CREATE TABLE IF NOT EXISTS predictions (id INT AUTO_INCREMENT PRIMARY KEY, flight_id INT NOT NULL, algorithm VARCHAR(50) NOT NULL, delay_level VARCHAR(20) NOT NULL, probability DECIMAL(5,2) NOT NULL, reasons TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (flight_id) REFERENCES flights(id) ON DELETE CASCADE, INDEX idx_prediction_flight (flight_id));

-- Kaggle Flight Data 2024: 7,079,081 records in the full CSV.
-- Use database/import_kaggle.sql after creating this table to load the CSV.
CREATE TABLE IF NOT EXISTS flight_data_2024 (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 year SMALLINT NOT NULL, month TINYINT NOT NULL, day_of_month TINYINT NOT NULL, day_of_week TINYINT NOT NULL,
 fl_date DATE NOT NULL, op_unique_carrier VARCHAR(8) NOT NULL, op_carrier_fl_num DECIMAL(10,1) NOT NULL,
 origin VARCHAR(8) NOT NULL, origin_city_name VARCHAR(100), origin_state_nm VARCHAR(60), dest VARCHAR(8) NOT NULL,
 dest_city_name VARCHAR(100), dest_state_nm VARCHAR(60), crs_dep_time INT, dep_time DECIMAL(10,1), dep_delay DECIMAL(10,1),
 taxi_out DECIMAL(10,1), wheels_off DECIMAL(10,1), wheels_on DECIMAL(10,1), taxi_in DECIMAL(10,1), crs_arr_time INT,
 arr_time DECIMAL(10,1), arr_delay DECIMAL(10,1), cancelled TINYINT NOT NULL DEFAULT 0, cancellation_code VARCHAR(4),
 diverted TINYINT NOT NULL DEFAULT 0, crs_elapsed_time DECIMAL(10,1), actual_elapsed_time DECIMAL(10,1), air_time DECIMAL(10,1),
 distance DECIMAL(10,1), carrier_delay INT NOT NULL DEFAULT 0, weather_delay INT NOT NULL DEFAULT 0, nas_delay INT NOT NULL DEFAULT 0,
 security_delay INT NOT NULL DEFAULT 0, late_aircraft_delay INT NOT NULL DEFAULT 0,
 INDEX idx_dataset_flight (op_unique_carrier, op_carrier_fl_num), INDEX idx_dataset_date (fl_date), INDEX idx_dataset_route (origin, dest), INDEX idx_dataset_delay (arr_delay)
) ENGINE=InnoDB;
