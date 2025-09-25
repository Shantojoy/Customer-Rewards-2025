CREATE DATABASE IF NOT EXISTS rewards_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE rewards_system;

CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password CHAR(64) NOT NULL,
    role ENUM('superadmin', 'cashier') NOT NULL DEFAULT 'cashier',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    phone VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(120),
    join_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS customer_visits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    admin_id INT,
    visit_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS points_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    admin_id INT,
    transaction_type ENUM('add', 'redeem', 'subtract', 'edit') NOT NULL,
    points INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
);

INSERT INTO admins (username, password, role)
VALUES ('admin', SHA2('admin123', 256), 'superadmin')
ON DUPLICATE KEY UPDATE username = VALUES(username);
