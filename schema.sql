-- ============================================
-- Restaurant RBAC System - Database Schema
-- Import in phpMyAdmin, then visit index.php
-- ============================================

CREATE DATABASE IF NOT EXISTS restaurant_rbac CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE restaurant_rbac;

-- Permissions
CREATE TABLE permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    label VARCHAR(150) NOT NULL,
    description VARCHAR(255)
);

-- Roles
CREATE TABLE roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    label VARCHAR(100) NOT NULL,
    description VARCHAR(255)
);

-- Role <-> Permission
CREATE TABLE role_permissions (
    role_id INT NOT NULL,
    permission_id INT NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
);

-- Users
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    username VARCHAR(80) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role_id INT NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    first_login TINYINT(1) DEFAULT 1,
    phone_number VARCHAR(20),
    email VARCHAR(120),
    failed_login_attempts INT DEFAULT 0,
    lockout_until DATETIME NULL,
    lockout_level INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id)
);

-- Menu Items
CREATE TABLE menu_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    category VARCHAR(80) NOT NULL,
    price DECIMAL(8,2) NOT NULL,
    is_available TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Orders
CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cashier_id INT NOT NULL,
    table_number INT NOT NULL,
    total DECIMAL(10,2) DEFAULT 0,
    status ENUM('open','paid','refunded','void') DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cashier_id) REFERENCES users(id)
);

-- Order Items
CREATE TABLE order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    menu_item_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(8,2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(id)
);

-- System Logs (for voided orders/items)
CREATE TABLE system_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    action VARCHAR(50) NOT NULL,
    order_id INT NOT NULL,
    order_item_id INT,
    staff_id INT NOT NULL,
    reason TEXT,
    amount_voided DECIMAL(10,2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id),
    FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE SET NULL,
    FOREIGN KEY (staff_id) REFERENCES users(id)
);

-- ============================================
-- Seed: Permissions
-- ============================================
INSERT INTO permissions (name, label, description) VALUES
('view_dashboard',    'View Dashboard',       'Access the main dashboard'),
('manage_menu',       'Manage Menu',          'Add, edit, remove menu items'),
('view_all_orders',   'View All Orders',      'See orders from all cashiers'),
('process_refund',    'Process Refund',       'Mark an order as refunded'),
('manage_cashiers',   'Manage Cashiers',      'Add or deactivate cashier accounts'),
('view_sales_report', 'View Sales Report',    'Access revenue and sales analytics'),
('take_order',        'Take Orders',          'Create new orders for tables'),
('process_payment',   'Process Payment',      'Mark orders as paid'),
('view_own_orders',   'View Own Orders',      'View only their own orders');

-- ============================================
-- Seed: Roles
-- ============================================
INSERT INTO roles (name, label, description) VALUES
('owner',   'Owner',   'Full access — manages everything'),
('cashier', 'Cashier', 'Takes orders and processes payments');

-- Assign permissions to Owner (all)
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p WHERE r.name = 'owner';

-- Assign permissions to Cashier
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r
JOIN permissions p ON p.name IN ('view_dashboard','take_order','process_payment','view_own_orders')
WHERE r.name = 'cashier';

-- ============================================
-- Seed: Users (password = "password" for all)
-- ============================================
INSERT INTO users (name, username, password, role_id, first_login) VALUES
('Maria Santos',  'owner1',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', (SELECT id FROM roles WHERE name='owner'), 0),
('Juan dela Cruz','cashier1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', (SELECT id FROM roles WHERE name='cashier'), 0),
('Ana Reyes',     'cashier2', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', (SELECT id FROM roles WHERE name='cashier'), 0);

-- ============================================
-- Seed: Menu Items
-- ============================================
INSERT INTO menu_items (name, category, price) VALUES
('Chicken Adobo',      'Main Course', 185.00),
('Pork Sinigang',      'Main Course', 210.00),
('Beef Kare-Kare',     'Main Course', 250.00),
('Lechon Kawali',      'Main Course', 220.00),
('Pancit Canton',      'Noodles',     150.00),
('Lomi',               'Noodles',     130.00),
('Garlic Rice',        'Side',         60.00),
('Steamed Rice',       'Side',         40.00),
('Halo-Halo',          'Dessert',     120.00),
('Leche Flan',         'Dessert',      90.00),
('Calamansi Juice',    'Drinks',       65.00),
('Iced Tea',           'Drinks',       55.00);

-- ============================================
-- Seed: Sample Orders
-- ============================================
INSERT INTO orders (cashier_id, table_number, total, status, created_at) VALUES
(2, 3, 395.00, 'paid',     NOW() - INTERVAL 2 HOUR),
(2, 7, 280.00, 'paid',     NOW() - INTERVAL 1 HOUR),
(3, 1, 460.00, 'paid',     NOW() - INTERVAL 3 HOUR),
(2, 5, 345.00, 'open',     NOW() - INTERVAL 20 MINUTE),
(3, 2, 210.00, 'refunded', NOW() - INTERVAL 4 HOUR);


-- Audit Log Table
CREATE TABLE IF NOT EXISTS audit_logs (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT          NULL,                      -- NULL for failed/unknown logins
    username      VARCHAR(80)  NOT NULL DEFAULT '',       -- capture attempted username even if user doesn't exist
    role          VARCHAR(50)  NOT NULL DEFAULT 'unknown',
    action        VARCHAR(100) NOT NULL,                  -- e.g. login_success, login_failed, logout, order_paid ...
    target_type   VARCHAR(80)  NULL,                      -- e.g. 'order', 'menu_item', 'cashier_account'
    target_id     INT          NULL,                      -- ID of affected record
    details       TEXT         NULL,                      -- human-readable description
    ip_address    VARCHAR(45)  NOT NULL DEFAULT '',       -- supports IPv6
    created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Add view_audit_log permission
INSERT IGNORE INTO permissions (name, label, description)
VALUES ('view_audit_log', 'View Audit Log', 'Access the audit log page (owner only)');

-- Grant it to owner role
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.name = 'view_audit_log'
WHERE r.name = 'owner';