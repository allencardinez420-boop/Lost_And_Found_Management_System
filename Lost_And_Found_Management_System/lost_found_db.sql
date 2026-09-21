CREATE DATABASE IF NOT EXISTS lost_found_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE lost_found_db;

CREATE TABLE IF NOT EXISTS users (
  user_id VARCHAR(20) PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  role ENUM('Student','Instructor','Employee','Security Guard','Admin','Outsider') NOT NULL DEFAULT 'Student',
  department VARCHAR(120) NULL,
  contact VARCHAR(40) NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_users_role (role)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS categories (
  category_id VARCHAR(20) PRIMARY KEY,
  category_name VARCHAR(80) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS lost_records (
  lost_id VARCHAR(20) PRIMARY KEY,
  user_id VARCHAR(20) NOT NULL,
  category_id VARCHAR(20) NOT NULL,
  item_name VARCHAR(120) NOT NULL,
  color VARCHAR(60) NULL,
  date_lost DATE NOT NULL,
  location_lost VARCHAR(150) NOT NULL,
  status ENUM('Pending','Matched','Returned') NOT NULL DEFAULT 'Pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_lost_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON UPDATE CASCADE,
  CONSTRAINT fk_lost_category FOREIGN KEY (category_id) REFERENCES categories(category_id) ON UPDATE CASCADE,
  INDEX idx_lost_status (status),
  INDEX idx_lost_match (category_id, item_name, color)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS found_item_records (
  found_id VARCHAR(20) PRIMARY KEY,
  user_id VARCHAR(20) NOT NULL,
  category_id VARCHAR(20) NOT NULL,
  item_name VARCHAR(120) NOT NULL,
  color VARCHAR(60) NULL,
  date_found DATE NOT NULL,
  location_found VARCHAR(150) NOT NULL,
  status ENUM('Unclaimed','Claimed') NOT NULL DEFAULT 'Unclaimed',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_found_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON UPDATE CASCADE,
  CONSTRAINT fk_found_category FOREIGN KEY (category_id) REFERENCES categories(category_id) ON UPDATE CASCADE,
  INDEX idx_found_status (status),
  INDEX idx_found_match (category_id, item_name, color)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS claim_records (
  claim_id VARCHAR(20) PRIMARY KEY,
  found_id VARCHAR(20) NOT NULL,
  user_id VARCHAR(20) NOT NULL,
  date_claimed DATE NOT NULL,
  verified_by VARCHAR(20) NULL,
  status ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_claim_found FOREIGN KEY (found_id) REFERENCES found_item_records(found_id) ON UPDATE CASCADE,
  CONSTRAINT fk_claim_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON UPDATE CASCADE,
  CONSTRAINT fk_claim_verifier FOREIGN KEY (verified_by) REFERENCES users(user_id) ON DELETE SET NULL ON UPDATE CASCADE,
  UNIQUE KEY uq_claim_user_found (found_id, user_id),
  INDEX idx_claim_status (status)
) ENGINE=InnoDB;

INSERT IGNORE INTO categories (category_id, category_name) VALUES
('CAT001','Wallet'),
('CAT002','ID Card'),
('CAT003','Calculator'),
('CAT004','Notebook'),
('CAT005','Umbrella'),
('CAT006','Cellphone'),
('CAT007','Backpack'),
('CAT008','Watch'),
('CAT009','Keys'),
('CAT010','Earphones');

INSERT IGNORE INTO users (user_id, name, role, department, contact, password_hash) VALUES
('U001','Allen Dela Cruz Cardinez','Student','BSIS','09123456789','$2y$10$sYunvmLEWu0IjEKA4ET5heSo6tVahCGHy5Jfb5E5n7f5HHgfb2qIy'),
('U002','Jenifer Santos','Student','BSIT','09112223344','$2y$12$iOKBXtlQB0nFa3TiBTzr4unaol2tuWgx2jYwiZiObhtF0VwFBpxuO'),
('U003','John Reyes','Security Guard','Security','09998887777','$2y$12$iOKBXtlQB0nFa3TiBTzr4unaol2tuWgx2jYwiZiObhtF0VwFBpxuO'),
('U004','Anna Gomez','Admin','Guidance Office','09887776655','$2y$12$iOKBXtlQB0nFa3TiBTzr4unaol2tuWgx2jYwiZiObhtF0VwFBpxuO'),
('U005','Mark Flores','Student','BSHM','09776665544','$2y$12$iOKBXtlQB0nFa3TiBTzr4unaol2tuWgx2jYwiZiObhtF0VwFBpxuO'),
('U006','Maria Lopez','Student','BSA','09665554433','$2y$12$iOKBXtlQB0nFa3TiBTzr4unaol2tuWgx2jYwiZiObhtF0VwFBpxuO'),
('U007','Carlo Ramos','Student','BSBA','09554443322','$2y$12$iOKBXtlQB0nFa3TiBTzr4unaol2tuWgx2jYwiZiObhtF0VwFBpxuO'),
('U008','Angelica Cruz','Student','BSCS','09443332211','$2y$12$iOKBXtlQB0nFa3TiBTzr4unaol2tuWgx2jYwiZiObhtF0VwFBpxuO'),
('U009','Joshua Lim','Student','BSED','09332221100','$2y$12$iOKBXtlQB0nFa3TiBTzr4unaol2tuWgx2jYwiZiObhtF0VwFBpxuO'),
('U010','Patricia Diaz','Student','BSN','09221110099','$2y$12$iOKBXtlQB0nFa3TiBTzr4unaol2tuWgx2jYwiZiObhtF0VwFBpxuO');

INSERT IGNORE INTO lost_records (lost_id,user_id,category_id,item_name,color,date_lost,location_lost,status) VALUES
('L001','U001','CAT001','Wallet','Black','2026-01-07','Library','Pending'),
('L002','U002','CAT002','ID Card','White','2026-03-07','Cafeteria','Returned'),
('L003','U005','CAT003','Calculator','Gray','2026-05-07','Computer Lab','Pending'),
('L004','U001','CAT005','Umbrella','Blue','2026-06-07','Gym','Matched'),
('L005','U002','CAT004','Notebook','Green','2026-09-07','Room 203','Pending'),
('L006','U006','CAT006','Cellphone','Black','2026-10-07','Library','Returned'),
('L007','U007','CAT007','Backpack','Red','2026-11-07','Canteen','Returned'),
('L008','U008','CAT008','Watch','Silver','2026-12-07','Covered Court','Matched'),
('L009','U009','CAT009','Keys','Silver','2026-07-13','Parking Area','Returned'),
('L010','U010','CAT010','Earphones','White','2026-07-14','Room 105','Returned');

INSERT IGNORE INTO found_item_records (found_id,user_id,category_id,item_name,color,date_found,location_found,status) VALUES
('F001','U003','CAT001','Wallet','Black','2026-02-07','Library','Claimed'),
('F002','U003','CAT002','ID Card','White','2026-04-07','Hallway','Claimed'),
('F003','U003','CAT003','Calculator','Gray','2026-06-07','Computer Lab','Unclaimed'),
('F004','U003','CAT005','Umbrella','Blue','2026-07-07','Gym','Claimed'),
('F005','U003','CAT004','Notebook','Green','2026-09-07','Room 203','Unclaimed'),
('F006','U003','CAT006','Cellphone','Black','2026-10-07','Library','Claimed'),
('F007','U003','CAT007','Backpack','Red','2026-11-07','Canteen','Claimed'),
('F008','U003','CAT008','Watch','Silver','2026-12-07','Covered Court','Unclaimed'),
('F009','U003','CAT009','Keys','Silver','2026-07-13','Parking Area','Claimed'),
('F010','U003','CAT010','Earphones','White','2026-07-14','Room 105','Claimed');

INSERT IGNORE INTO claim_records (claim_id,found_id,user_id,date_claimed,verified_by,status) VALUES
('C001','F001','U001','2026-05-07','U004','Approved'),
('C002','F002','U002','2026-06-07','U004','Approved'),
('C003','F004','U001','2026-08-07','U004','Approved'),
('C004','F007','U007','2026-12-07','U004','Approved'),
('C005','F009','U009','2026-07-14','U004','Approved'),
('C006','F003','U005','2026-07-15','U004','Pending'),
('C007','F005','U002','2026-07-16','U004','Pending'),
('C008','F006','U006','2026-07-17','U004','Approved'),
('C009','F008','U008','2026-07-18','U004','Pending'),
('C010','F010','U010','2026-07-19','U004','Approved');
