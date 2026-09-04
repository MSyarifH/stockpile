-- =====================================================================
-- Inventory & Order Management System - schema (DB-01)
-- MySQL 8.0 / InnoDB / utf8mb4
-- =====================================================================
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS stock_ledger;
DROP TABLE IF EXISTS sales_order_items;
DROP TABLE IF EXISTS sales_orders;
DROP TABLE IF EXISTS purchase_order_items;
DROP TABLE IF EXISTS purchase_orders;
DROP TABLE IF EXISTS product_stocks;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS suppliers;
DROP TABLE IF EXISTS customers;
DROP TABLE IF EXISTS warehouses;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- users
-- ---------------------------------------------------------------------
CREATE TABLE users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(120) NOT NULL,
    email         VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('Admin','Sales','WarehouseStaff') NOT NULL,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- Uniqueness is enforced by the DATABASE, not only by a PHP "check first" query.
    -- A pre-check alone is a race: two concurrent signups can both pass it.
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- warehouses / categories / suppliers / customers
-- ---------------------------------------------------------------------
CREATE TABLE warehouses (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(120) NOT NULL,
    location   VARCHAR(190) NOT NULL,
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_warehouses_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categories (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(120) NOT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE suppliers (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(150) NOT NULL,
    contact    VARCHAR(120) NOT NULL DEFAULT '',
    address    VARCHAR(255) NOT NULL DEFAULT '',
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_suppliers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customers (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(150) NOT NULL,
    contact    VARCHAR(120) NOT NULL DEFAULT '',
    address    VARCHAR(255) NOT NULL DEFAULT '',
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_customers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- products
-- ---------------------------------------------------------------------
CREATE TABLE products (
    id             INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    sku            VARCHAR(64)    NOT NULL,
    name           VARCHAR(190)   NOT NULL,
    category_id    INT UNSIGNED   NOT NULL,
    unit           VARCHAR(20)    NOT NULL DEFAULT 'pcs',
    -- DECIMAL, never FLOAT: money in binary floating point silently loses cents.
    purchase_price DECIMAL(14,2)  NOT NULL DEFAULT 0.00,
    selling_price  DECIMAL(14,2)  NOT NULL DEFAULT 0.00,
    reorder_point  INT UNSIGNED   NOT NULL DEFAULT 0,
    image_path     VARCHAR(255)   NULL DEFAULT NULL,
    is_active      TINYINT(1)     NOT NULL DEFAULT 1,
    created_at     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_products_sku (sku),
    KEY idx_products_name (name),
    KEY idx_products_category (category_id),
    KEY idx_products_active (is_active),
    CONSTRAINT fk_products_category FOREIGN KEY (category_id)
        REFERENCES categories (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT ck_products_prices CHECK (purchase_price >= 0 AND selling_price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- product_stocks : one row per (product, warehouse)  -- WH-01
-- ---------------------------------------------------------------------
CREATE TABLE product_stocks (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id   INT UNSIGNED NOT NULL,
    warehouse_id INT UNSIGNED NOT NULL,
    quantity     INT          NOT NULL DEFAULT 0,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- This unique key is what makes "SELECT ... FOR UPDATE" lock exactly ONE row
    -- instead of a range. It is a correctness requirement for ARCH-02, not a nicety.
    UNIQUE KEY uq_stock_product_warehouse (product_id, warehouse_id),
    KEY idx_stock_warehouse (warehouse_id),
    CONSTRAINT fk_stock_product FOREIGN KEY (product_id)
        REFERENCES products (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_stock_warehouse FOREIGN KEY (warehouse_id)
        REFERENCES warehouses (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    -- Last line of defence: even a bug in the service cannot persist negative stock.
    CONSTRAINT ck_stock_non_negative CHECK (quantity >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- purchase orders  -- PO-01
-- ---------------------------------------------------------------------
CREATE TABLE purchase_orders (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    po_number    VARCHAR(32)  NOT NULL,
    supplier_id  INT UNSIGNED NOT NULL,
    warehouse_id INT UNSIGNED NOT NULL,
    status       ENUM('Draft','Ordered','PartiallyReceived','Received','Cancelled') NOT NULL DEFAULT 'Draft',
    order_date   DATE         NOT NULL,
    created_by   INT UNSIGNED NOT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_po_number (po_number),
    KEY idx_po_status (status),
    KEY idx_po_order_date (order_date),
    KEY idx_po_supplier (supplier_id),
    CONSTRAINT fk_po_supplier  FOREIGN KEY (supplier_id)  REFERENCES suppliers (id)  ON DELETE RESTRICT,
    CONSTRAINT fk_po_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses (id) ON DELETE RESTRICT,
    CONSTRAINT fk_po_creator   FOREIGN KEY (created_by)   REFERENCES users (id)      ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchase_order_items (
    id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    purchase_order_id INT UNSIGNED  NOT NULL,
    product_id        INT UNSIGNED  NOT NULL,
    quantity          INT UNSIGNED  NOT NULL,
    received_quantity INT UNSIGNED  NOT NULL DEFAULT 0,
    purchase_price    DECIMAL(14,2) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_poi_order (purchase_order_id),
    KEY idx_poi_product (product_id),
    -- Deleting a PO removes its lines; the ledger keeps the historical trace.
    CONSTRAINT fk_poi_order   FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_poi_product FOREIGN KEY (product_id)        REFERENCES products (id)        ON DELETE RESTRICT,
    CONSTRAINT ck_poi_quantity CHECK (quantity > 0 AND received_quantity <= quantity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- sales orders  -- SO-01
-- ---------------------------------------------------------------------
CREATE TABLE sales_orders (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    so_number    VARCHAR(32)  NOT NULL,
    customer_id  INT UNSIGNED NOT NULL,
    warehouse_id INT UNSIGNED NOT NULL,
    status       ENUM('Draft','PendingApproval','Approved','Fulfilled','Cancelled') NOT NULL DEFAULT 'Draft',
    order_date   DATE         NOT NULL,
    created_by   INT UNSIGNED NOT NULL,
    -- NULL until an Admin approves. Together with created_by this column is the
    -- audit trail that proves segregation of duties was respected (§1.2).
    approved_by  INT UNSIGNED NULL DEFAULT NULL,
    approved_at  DATETIME     NULL DEFAULT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_so_number (so_number),
    KEY idx_so_status (status),
    KEY idx_so_order_date (order_date),
    KEY idx_so_created_by (created_by),
    KEY idx_so_seller_date (created_by, order_date),
    CONSTRAINT fk_so_customer  FOREIGN KEY (customer_id)  REFERENCES customers (id)  ON DELETE RESTRICT,
    CONSTRAINT fk_so_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses (id) ON DELETE RESTRICT,
    CONSTRAINT fk_so_creator   FOREIGN KEY (created_by)   REFERENCES users (id)      ON DELETE RESTRICT,
    CONSTRAINT fk_so_approver  FOREIGN KEY (approved_by)  REFERENCES users (id)      ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sales_order_items (
    id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    sales_order_id INT UNSIGNED  NOT NULL,
    product_id     INT UNSIGNED  NOT NULL,
    quantity       INT UNSIGNED  NOT NULL,
    selling_price  DECIMAL(14,2) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_soi_order (sales_order_id),
    KEY idx_soi_product (product_id),
    CONSTRAINT fk_soi_order   FOREIGN KEY (sales_order_id) REFERENCES sales_orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_soi_product FOREIGN KEY (product_id)     REFERENCES products (id)     ON DELETE RESTRICT,
    CONSTRAINT ck_soi_quantity CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- stock_ledger : append-only history. Every change to product_stocks.quantity
-- must have exactly one matching row here (§1.3 "Keputusan data").
-- ---------------------------------------------------------------------
CREATE TABLE stock_ledger (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id     INT UNSIGNED NOT NULL,
    warehouse_id   INT UNSIGNED NOT NULL,
    movement_type  ENUM('Receipt','Issue','Adjustment') NOT NULL,
    -- Signed: +n for Receipt, -n for Issue. SUM(quantity) must equal the
    -- current product_stocks.quantity -- that is the reconciliation invariant.
    quantity       INT          NOT NULL,
    reference_type ENUM('PurchaseOrder','SalesOrder','Manual') NOT NULL,
    reference_id   INT UNSIGNED NULL DEFAULT NULL,
    performed_by   INT UNSIGNED NOT NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- Composite index ordered for the dominant query: "movements of product P
    -- in warehouse W over a date range" (REPORT-01). Leftmost-prefix rule means
    -- it also serves "all movements of product P".
    KEY idx_ledger_product_warehouse_date (product_id, warehouse_id, created_at),
    KEY idx_ledger_created_at (created_at),
    KEY idx_ledger_reference (reference_type, reference_id),
    CONSTRAINT fk_ledger_product   FOREIGN KEY (product_id)   REFERENCES products (id)   ON DELETE RESTRICT,
    CONSTRAINT fk_ledger_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses (id) ON DELETE RESTRICT,
    CONSTRAINT fk_ledger_user      FOREIGN KEY (performed_by) REFERENCES users (id)      ON DELETE RESTRICT,
    CONSTRAINT ck_ledger_quantity_nonzero CHECK (quantity <> 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
