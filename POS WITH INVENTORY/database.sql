CREATE DATABASE IF NOT EXISTS smartstock_pos
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE smartstock_pos;

-- Avatar image bytes are seeded from images/avatars by the authenticated API.
CREATE TABLE IF NOT EXISTS profile_avatars (
    id TINYINT UNSIGNED NOT NULL,
    label VARCHAR(60) NOT NULL,
    mime_type VARCHAR(30) NOT NULL,
    image_size INT UNSIGNED NOT NULL DEFAULT 0,
    image_data MEDIUMBLOB NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS profile_avatar_chunks (
    avatar_id TINYINT UNSIGNED NOT NULL,
    chunk_index SMALLINT UNSIGNED NOT NULL,
    image_data VARBINARY(32768) NOT NULL,
    PRIMARY KEY (avatar_id, chunk_index),
    CONSTRAINT profile_avatar_chunks_avatar_fk
        FOREIGN KEY (avatar_id) REFERENCES profile_avatars (id) ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    display_name VARCHAR(100) NOT NULL,
    username VARCHAR(50) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'employee', 'owner') NOT NULL DEFAULT 'admin',
    avatar_id TINYINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY users_username_unique (username),
    CONSTRAINT users_avatar_fk
        FOREIGN KEY (avatar_id) REFERENCES profile_avatars (id) ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_account_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    display_name VARCHAR(100) NOT NULL,
    username VARCHAR(50) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY admin_account_requests_username_unique (username)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

ALTER TABLE users
    MODIFY role ENUM('admin', 'employee', 'owner') NOT NULL DEFAULT 'admin';

UPDATE users AS owner_account
SET role = 'owner'
WHERE BINARY owner_account.display_name = BINARY 'Paul Andrei Silvestre'
  AND (
      SELECT COUNT(*)
      FROM (SELECT id FROM users WHERE BINARY display_name = BINARY 'Paul Andrei Silvestre') AS owner_matches
  ) = 1;

CREATE TABLE IF NOT EXISTS products (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_name VARCHAR(150) NOT NULL,
    sku VARCHAR(50) NOT NULL,
    price DECIMAL(10, 2) UNSIGNED NOT NULL,
    stock INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('active', 'discontinued') NOT NULL DEFAULT 'active',
    image_url VARCHAR(500) NULL COMMENT 'Relative path to an imported product image stored under images/products',
    category VARCHAR(80) NOT NULL DEFAULT 'Uncategorized',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY products_sku_unique (sku),
    KEY products_name_idx (product_name)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    subtotal DECIMAL(12, 2) UNSIGNED NOT NULL,
  discount_type ENUM('pwd', 'senior') NULL,
  discount_percent DECIMAL(5, 2) UNSIGNED NOT NULL DEFAULT 0.00,
  discount_amount DECIMAL(12, 2) UNSIGNED NOT NULL DEFAULT 0.00,
    tax_rate DECIMAL(5, 2) UNSIGNED NOT NULL,
    tax_amount DECIMAL(12, 2) UNSIGNED NOT NULL,
    total DECIMAL(12, 2) UNSIGNED NOT NULL,
    payment_method ENUM('cash', 'card') NOT NULL,
    cash_received DECIMAL(12, 2) UNSIGNED NULL,
    change_given DECIMAL(12, 2) UNSIGNED NULL,
    card_type VARCHAR(30) NULL,
    card_last_four CHAR(4) NULL,
    authorization_reference VARCHAR(100) NULL,
    cashier_name VARCHAR(100) NULL,
    cashier_avatar_id TINYINT UNSIGNED NULL,
    cashier_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY sales_created_at_idx (created_at),
    CONSTRAINT sales_cashier_fk
        FOREIGN KEY (cashier_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sale_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sale_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NULL,
    product_name VARCHAR(150) NOT NULL,
    sku VARCHAR(50) NOT NULL,
    category VARCHAR(80) NOT NULL DEFAULT 'Uncategorized',
    quantity INT UNSIGNED NOT NULL,
    unit_price DECIMAL(10, 2) UNSIGNED NOT NULL,
    line_total DECIMAL(12, 2) UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY sale_items_sale_idx (sale_id),
    KEY sale_items_product_idx (product_id),
    CONSTRAINT sale_items_sale_fk
        FOREIGN KEY (sale_id) REFERENCES sales (id) ON DELETE CASCADE,
    CONSTRAINT sale_items_product_fk
        FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_name VARCHAR(150) NOT NULL,
    order_date DATE NOT NULL,
    status ENUM('in_transit', 'received', 'cancelled') NOT NULL DEFAULT 'in_transit',
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY purchase_orders_date_idx (order_date),
    KEY purchase_orders_status_idx (status),
    CONSTRAINT purchase_orders_created_by_fk
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_order_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    purchase_order_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NULL,
    product_name VARCHAR(150) NOT NULL,
    sku VARCHAR(50) NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    unit_cost DECIMAL(10, 2) UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY purchase_order_items_order_idx (purchase_order_id),
    KEY purchase_order_items_product_idx (product_id),
    CONSTRAINT purchase_order_items_order_fk
        FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id) ON DELETE CASCADE,
    CONSTRAINT purchase_order_items_product_fk
        FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;