-- Run this once in phpMyAdmin (Import or SQL tab) on your database.
CREATE TABLE IF NOT EXISTS orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  phone CHAR(10) NOT NULL,
  address VARCHAR(500) NOT NULL,
  pincode CHAR(6) NOT NULL,
  qty TINYINT UNSIGNED NOT NULL DEFAULT 1,
  total INT UNSIGNED NOT NULL,
  note VARCHAR(300) NULL,
  status ENUM('New','Confirmed','Shipped','Delivered','Cancelled') NOT NULL DEFAULT 'New',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_status (status),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



CREATE TABLE IF NOT EXISTS admins (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO admins (username, password_hash)
VALUES ('admin', '$2y$10$A5xbRx.MRkXXQH5Yh5Riyu2BiOSEfY6KP3nVbXTNTUcnSzam6G9GW');