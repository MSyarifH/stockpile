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
    -- (created_by, order_date) serves the Sales list query, which filters by
    -- seller and orders by date. A separate KEY on created_by alone would be
    -- redundant: it is the leftmost prefix of this index, and this index also
    -- satisfies the fk_so_creator foreign key, so MySQL needs no other.
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


-- ============================================================
-- Seed data (§7.1 Data Demo Minimum)
-- GENERATED FILE - regenerate with scripts/generate-seed.py, do not hand-edit.
-- Demo password for every account: Password123!
-- ============================================================
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE stock_ledger;
TRUNCATE TABLE sales_order_items;
TRUNCATE TABLE sales_orders;
TRUNCATE TABLE purchase_order_items;
TRUNCATE TABLE purchase_orders;
TRUNCATE TABLE product_stocks;
TRUNCATE TABLE products;
TRUNCATE TABLE categories;
TRUNCATE TABLE suppliers;
TRUNCATE TABLE customers;
TRUNCATE TABLE warehouses;
TRUNCATE TABLE users;
SET FOREIGN_KEY_CHECKS = 1;

INSERT INTO categories (id, name, description) VALUES
  (1, 'Elektronik', 'Perangkat elektronik dan aksesorinya'),
  (2, 'Alat Tulis', 'Kebutuhan kantor dan tulis menulis'),
  (3, 'Perkakas', 'Peralatan bengkel dan pertukangan'),
  (4, 'Rumah Tangga', 'Peralatan kebutuhan rumah tangga'),
  (5, 'Kesehatan', 'Alat kesehatan dan kebersihan'),
  (6, 'Makanan & Minuman', 'Konsumsi kemasan');

INSERT INTO warehouses (id, name, location, is_active) VALUES
  (1, 'Gudang Jakarta', 'Jl. Daan Mogot KM 12, Jakarta Barat', 1),
  (2, 'Gudang Bandung', 'Jl. Soekarno Hatta 405, Bandung', 1),
  (3, 'Gudang Surabaya', 'Jl. Rungkut Industri 8, Surabaya', 1);

INSERT INTO users (id, name, email, password_hash, role, is_active) VALUES
  (1, 'Rizky Admin', 'admin@example.com', '$2y$10$vh5iiFvhaS53oJehaDv9GuBrN3lMIG/uM08JL9rDZZnlua7OePlPi', 'Admin', 1),
  (2, 'Sinta Sales', 'sales1@example.com', '$2y$10$vh5iiFvhaS53oJehaDv9GuBrN3lMIG/uM08JL9rDZZnlua7OePlPi', 'Sales', 1),
  (3, 'Bagus Sales', 'sales2@example.com', '$2y$10$vh5iiFvhaS53oJehaDv9GuBrN3lMIG/uM08JL9rDZZnlua7OePlPi', 'Sales', 1),
  (4, 'Wawan Gudang', 'warehouse1@example.com', '$2y$10$vh5iiFvhaS53oJehaDv9GuBrN3lMIG/uM08JL9rDZZnlua7OePlPi', 'WarehouseStaff', 1),
  (5, 'Dewi Gudang', 'warehouse2@example.com', '$2y$10$vh5iiFvhaS53oJehaDv9GuBrN3lMIG/uM08JL9rDZZnlua7OePlPi', 'WarehouseStaff', 1),
  (6, 'Nonaktif Sales', 'inactive@example.com', '$2y$10$vh5iiFvhaS53oJehaDv9GuBrN3lMIG/uM08JL9rDZZnlua7OePlPi', 'Sales', 0),
  (7, 'Putri Admin', 'admin2@example.com', '$2y$10$vh5iiFvhaS53oJehaDv9GuBrN3lMIG/uM08JL9rDZZnlua7OePlPi', 'Admin', 1);

INSERT INTO suppliers (id, name, contact, address, is_active) VALUES
  (1, 'PT Sinar Elektronik', '021-5550101', 'Jl. Gajah Mada 17, Jakarta', 1),
  (2, 'CV Mitra Kertas', '022-5550202', 'Jl. Braga 88, Bandung', 1),
  (3, 'PT Baja Perkasa', '031-5550303', 'Jl. Kedungdoro 12, Surabaya', 1),
  (4, 'UD Sumber Rejeki', '024-5550404', 'Jl. Pandanaran 45, Semarang', 1),
  (5, 'PT Anugerah Sehat', '021-5550505', 'Jl. Sudirman 90, Jakarta', 1),
  (6, 'CV Boga Nusantara', '0274-555060', 'Jl. Malioboro 21, Yogyakarta', 1);

INSERT INTO customers (id, name, contact, address, is_active) VALUES
  (1, 'Toko Maju Jaya', '0812-1111-001', 'Jl. Pasar Baru 3, Jakarta', 1),
  (2, 'CV Berkah Abadi', '0812-1111-002', 'Jl. Cihampelas 55, Bandung', 1),
  (3, 'PT Karya Mandiri', '0812-1111-003', 'Jl. Basuki Rahmat 9, Surabaya', 1),
  (4, 'Toko Sentosa', '0812-1111-004', 'Jl. Ahmad Yani 71, Semarang', 1),
  (5, 'UD Harapan Baru', '0812-1111-005', 'Jl. Diponegoro 14, Malang', 1),
  (6, 'PT Global Retail', '0812-1111-006', 'Jl. Thamrin 1, Jakarta', 1),
  (7, 'Toko Sahabat', '0812-1111-007', 'Jl. Veteran 33, Bandung', 1),
  (8, 'CV Prima Niaga', '0812-1111-008', 'Jl. Panglima Sudirman 5, Surabaya', 1);

INSERT INTO products (id, sku, name, category_id, unit, purchase_price, selling_price, reorder_point, is_active) VALUES
  (1, 'SKU-ELK-0001', 'Keyboard Mekanik K1', 1, 'pcs', 320000.00, 459000.00, 15, 1),
  (2, 'SKU-ELK-0002', 'Mouse Wireless M2', 1, 'pcs', 95000.00, 149000.00, 25, 1),
  (3, 'SKU-ELK-0003', 'Monitor 24 inch', 1, 'unit', 1450000.00, 1899000.00, 8, 1),
  (4, 'SKU-ELK-0004', 'Headset Gaming H3', 1, 'pcs', 275000.00, 389000.00, 12, 1),
  (5, 'SKU-ELK-0005', 'Webcam HD 1080p', 1, 'pcs', 210000.00, 299000.00, 10, 1),
  (6, 'SKU-ELK-0006', 'Hub USB-C 6 Port', 1, 'pcs', 165000.00, 239000.00, 20, 1),
  (7, 'SKU-ELK-0007', 'Kabel HDMI 2m', 1, 'pcs', 42000.00, 69000.00, 40, 0),
  (8, 'SKU-ELK-0008', 'Power Bank 10000mAh', 1, 'pcs', 180000.00, 259000.00, 18, 1),
  (9, 'SKU-ATK-0009', 'Pulpen Gel 0.5 Hitam', 2, 'lusin', 18000.00, 29000.00, 50, 1),
  (10, 'SKU-ATK-0010', 'Buku Tulis 58 Lembar', 2, 'pak', 32000.00, 48000.00, 40, 1),
  (11, 'SKU-ATK-0011', 'Kertas HVS A4 80gsm', 2, 'rim', 52000.00, 72000.00, 30, 1),
  (12, 'SKU-ATK-0012', 'Spidol Whiteboard', 2, 'lusin', 36000.00, 55000.00, 25, 1),
  (13, 'SKU-ATK-0013', 'Stapler Besar', 2, 'pcs', 45000.00, 68000.00, 15, 1),
  (14, 'SKU-ATK-0014', 'Map Plastik Folder', 2, 'pak', 22000.00, 35000.00, 35, 1),
  (15, 'SKU-ATK-0015', 'Tinta Printer Hitam', 2, 'botol', 68000.00, 95000.00, 20, 1),
  (16, 'SKU-PRK-0016', 'Obeng Set 12 pcs', 3, 'set', 125000.00, 179000.00, 10, 1),
  (17, 'SKU-PRK-0017', 'Tang Kombinasi 8 inch', 3, 'pcs', 68000.00, 99000.00, 15, 1),
  (18, 'SKU-PRK-0018', 'Kunci Inggris 10 inch', 3, 'pcs', 85000.00, 125000.00, 12, 1),
  (19, 'SKU-PRK-0019', 'Meteran 5m', 3, 'pcs', 38000.00, 59000.00, 20, 1),
  (20, 'SKU-PRK-0020', 'Bor Listrik 400W', 3, 'unit', 420000.00, 589000.00, 6, 1),
  (21, 'SKU-PRK-0021', 'Palu Karet', 3, 'pcs', 42000.00, 65000.00, 15, 1),
  (22, 'SKU-RTG-0022', 'Panci Stainless 24cm', 4, 'pcs', 145000.00, 209000.00, 10, 1),
  (23, 'SKU-RTG-0023', 'Wajan Anti Lengket 28cm', 4, 'pcs', 128000.00, 185000.00, 12, 1),
  (24, 'SKU-RTG-0024', 'Set Pisau Dapur 5 pcs', 4, 'set', 98000.00, 145000.00, 14, 1),
  (25, 'SKU-RTG-0025', 'Rak Piring 2 Susun', 4, 'unit', 175000.00, 249000.00, 8, 1),
  (26, 'SKU-RTG-0026', 'Termos Air 1.8L', 4, 'pcs', 88000.00, 129000.00, 16, 1),
  (27, 'SKU-KES-0027', 'Masker Medis 3 Ply', 5, 'box', 28000.00, 45000.00, 60, 1),
  (28, 'SKU-KES-0028', 'Hand Sanitizer 500ml', 5, 'botol', 32000.00, 52000.00, 45, 1),
  (29, 'SKU-KES-0029', 'Termometer Digital', 5, 'pcs', 65000.00, 98000.00, 20, 1),
  (30, 'SKU-KES-0030', 'Sarung Tangan Latex', 5, 'box', 48000.00, 72000.00, 30, 1),
  (31, 'SKU-FNB-0031', 'Kopi Bubuk 200g', 6, 'pak', 24000.00, 38000.00, 50, 1),
  (32, 'SKU-FNB-0032', 'Teh Celup 25 Sachet', 6, 'box', 15000.00, 25000.00, 55, 1),
  (33, 'SKU-FNB-0033', 'Air Mineral 600ml', 6, 'karton', 38000.00, 55000.00, 40, 1),
  (34, 'SKU-FNB-0034', 'Biskuit Kaleng 700g', 6, 'kaleng', 62000.00, 89000.00, 25, 0);

INSERT INTO purchase_orders (id, po_number, supplier_id, warehouse_id, status, order_date, created_by) VALUES
  (1, 'PO-2026-0001', 6, 1, 'Received', '2026-06-06', 4),
  (2, 'PO-2026-0002', 1, 2, 'Received', '2026-06-08', 4),
  (3, 'PO-2026-0003', 3, 2, 'Received', '2026-06-10', 4),
  (4, 'PO-2026-0004', 6, 2, 'Received', '2026-06-13', 1),
  (5, 'PO-2026-0005', 4, 1, 'Received', '2026-06-14', 5),
  (6, 'PO-2026-0006', 5, 1, 'PartiallyReceived', '2026-06-15', 4),
  (7, 'PO-2026-0007', 3, 2, 'PartiallyReceived', '2026-06-16', 5),
  (8, 'PO-2026-0008', 3, 3, 'PartiallyReceived', '2026-06-18', 4),
  (9, 'PO-2026-0009', 6, 2, 'Ordered', '2026-06-20', 1),
  (10, 'PO-2026-0010', 3, 1, 'Ordered', '2026-06-22', 1),
  (11, 'PO-2026-0011', 5, 1, 'Ordered', '2026-06-25', 4),
  (12, 'PO-2026-0012', 1, 1, 'Draft', '2026-06-26', 4),
  (13, 'PO-2026-0013', 6, 2, 'Draft', '2026-06-29', 5),
  (14, 'PO-2026-0014', 3, 2, 'Cancelled', '2026-07-01', 4);

INSERT INTO purchase_order_items (id, purchase_order_id, product_id, quantity, received_quantity, purchase_price) VALUES
  (1, 1, 7, 30, 30, 42000.00),
  (2, 1, 26, 39, 39, 88000.00),
  (3, 2, 12, 34, 34, 36000.00),
  (4, 3, 31, 36, 36, 24000.00),
  (5, 3, 33, 32, 32, 38000.00),
  (6, 4, 11, 42, 42, 52000.00),
  (7, 4, 32, 39, 39, 15000.00),
  (8, 4, 2, 27, 27, 95000.00),
  (9, 5, 8, 54, 54, 180000.00),
  (10, 5, 10, 36, 36, 32000.00),
  (11, 5, 8, 37, 37, 180000.00),
  (12, 6, 1, 60, 54, 320000.00),
  (13, 6, 30, 41, 8, 48000.00),
  (14, 6, 20, 58, 24, 420000.00),
  (15, 7, 21, 23, 10, 42000.00),
  (16, 7, 27, 59, 41, 28000.00),
  (17, 8, 4, 15, 13, 275000.00),
  (18, 8, 11, 59, 23, 52000.00),
  (19, 9, 18, 35, 0, 85000.00),
  (20, 9, 32, 46, 0, 15000.00),
  (21, 10, 15, 54, 0, 68000.00),
  (22, 10, 17, 29, 0, 68000.00),
  (23, 10, 28, 35, 0, 32000.00),
  (24, 11, 20, 11, 0, 420000.00),
  (25, 12, 21, 39, 0, 42000.00),
  (26, 13, 15, 42, 0, 68000.00),
  (27, 13, 33, 46, 0, 38000.00),
  (28, 13, 28, 49, 0, 32000.00),
  (29, 14, 34, 10, 0, 62000.00),
  (30, 14, 32, 26, 0, 15000.00);

INSERT INTO sales_orders (id, so_number, customer_id, warehouse_id, status, order_date, created_by, approved_by, approved_at) VALUES
  (1, 'SO-2026-0001', 5, 2, 'Fulfilled', '2026-06-10', 3, 1, '2026-06-10 09:30:00'),
  (2, 'SO-2026-0002', 7, 2, 'Fulfilled', '2026-06-12', 3, 7, '2026-06-12 09:30:00'),
  (3, 'SO-2026-0003', 8, 2, 'Fulfilled', '2026-06-13', 2, 7, '2026-06-13 09:30:00'),
  (4, 'SO-2026-0004', 7, 1, 'Fulfilled', '2026-06-15', 3, 1, '2026-06-15 09:30:00'),
  (5, 'SO-2026-0005', 6, 2, 'Fulfilled', '2026-06-16', 3, 1, '2026-06-16 09:30:00'),
  (6, 'SO-2026-0006', 4, 1, 'Approved', '2026-06-17', 2, 7, '2026-06-17 09:30:00'),
  (7, 'SO-2026-0007', 8, 2, 'Approved', '2026-06-18', 3, 7, '2026-06-18 09:30:00'),
  (8, 'SO-2026-0008', 8, 1, 'Approved', '2026-06-19', 2, 1, '2026-06-19 09:30:00'),
  (9, 'SO-2026-0009', 6, 2, 'PendingApproval', '2026-06-20', 3, NULL, NULL),
  (10, 'SO-2026-0010', 7, 1, 'PendingApproval', '2026-06-22', 3, NULL, NULL),
  (11, 'SO-2026-0011', 1, 2, 'PendingApproval', '2026-06-23', 2, NULL, NULL),
  (12, 'SO-2026-0012', 7, 1, 'PendingApproval', '2026-06-25', 2, NULL, NULL),
  (13, 'SO-2026-0013', 3, 1, 'Draft', '2026-06-26', 3, NULL, NULL),
  (14, 'SO-2026-0014', 8, 1, 'Draft', '2026-06-28', 2, NULL, NULL),
  (15, 'SO-2026-0015', 1, 1, 'Cancelled', '2026-06-29', 2, NULL, NULL),
  (16, 'SO-2026-0016', 3, 2, 'Cancelled', '2026-06-30', 3, NULL, NULL);

INSERT INTO sales_order_items (id, sales_order_id, product_id, quantity, selling_price) VALUES
  (1, 1, 23, 13, 185000.00),
  (2, 2, 2, 20, 149000.00),
  (3, 3, 1, 13, 459000.00),
  (4, 3, 24, 4, 145000.00),
  (5, 4, 18, 16, 125000.00),
  (6, 4, 5, 6, 299000.00),
  (7, 4, 22, 16, 209000.00),
  (8, 5, 30, 2, 72000.00),
  (9, 6, 2, 12, 149000.00),
  (10, 6, 28, 6, 52000.00),
  (11, 7, 13, 8, 68000.00),
  (12, 7, 23, 7, 185000.00),
  (13, 7, 2, 5, 149000.00),
  (14, 8, 33, 8, 55000.00),
  (15, 8, 32, 10, 25000.00),
  (16, 8, 25, 1, 249000.00),
  (17, 9, 26, 5, 129000.00),
  (18, 10, 29, 12, 98000.00),
  (19, 11, 33, 6, 55000.00),
  (20, 11, 2, 12, 149000.00),
  (21, 12, 14, 1, 35000.00),
  (22, 12, 30, 4, 72000.00),
  (23, 12, 34, 8, 89000.00),
  (24, 13, 24, 10, 145000.00),
  (25, 13, 23, 5, 185000.00),
  (26, 14, 5, 7, 299000.00),
  (27, 14, 18, 7, 125000.00),
  (28, 14, 27, 9, 45000.00),
  (29, 15, 32, 3, 25000.00),
  (30, 16, 3, 10, 1899000.00);

-- product_stocks and stock_ledger below are BOTH derived from the same
-- simulation, so SUM(stock_ledger.quantity) == product_stocks.quantity
-- holds for every (product, warehouse) pair in this seed.
INSERT INTO product_stocks (id, product_id, warehouse_id, quantity) VALUES
  (1, 1, 1, 99),
  (2, 1, 2, 30),
  (3, 1, 3, 94),
  (4, 2, 1, 82),
  (5, 2, 2, 93),
  (6, 3, 1, 63),
  (7, 3, 2, 29),
  (8, 3, 3, 81),
  (9, 4, 1, 62),
  (10, 4, 2, 56),
  (11, 4, 3, 13),
  (12, 5, 1, 7),
  (13, 5, 2, 14),
  (14, 5, 3, 15),
  (15, 6, 1, 108),
  (16, 6, 2, 64),
  (17, 7, 1, 30),
  (18, 7, 2, 10),
  (19, 7, 3, 7),
  (20, 8, 1, 145),
  (21, 8, 2, 64),
  (22, 9, 1, 108),
  (23, 9, 2, 126),
  (24, 9, 3, 70),
  (25, 10, 1, 80),
  (26, 10, 2, 45),
  (27, 11, 1, 54),
  (28, 11, 2, 84),
  (29, 11, 3, 90),
  (30, 12, 1, 49),
  (31, 12, 2, 85),
  (32, 13, 1, 68),
  (33, 13, 2, 34),
  (34, 13, 3, 31),
  (35, 14, 1, 2),
  (36, 14, 2, 9),
  (37, 15, 1, 23),
  (38, 15, 2, 23),
  (39, 15, 3, 22),
  (40, 16, 1, 61),
  (41, 16, 2, 81),
  (42, 17, 1, 100),
  (43, 17, 2, 55),
  (44, 17, 3, 87),
  (45, 18, 1, 56),
  (46, 18, 2, 72),
  (47, 19, 1, 53),
  (48, 19, 2, 79),
  (49, 19, 3, 54),
  (50, 20, 1, 30),
  (51, 20, 2, 10),
  (52, 21, 1, 1),
  (53, 21, 2, 13),
  (54, 21, 3, 2),
  (55, 22, 1, 5),
  (56, 22, 2, 52),
  (57, 23, 1, 40),
  (58, 23, 2, 60),
  (59, 23, 3, 27),
  (60, 24, 1, 68),
  (61, 24, 2, 93),
  (62, 25, 1, 12),
  (63, 25, 2, 9),
  (64, 25, 3, 8),
  (65, 26, 1, 95),
  (66, 26, 2, 60),
  (67, 27, 1, 113),
  (68, 27, 2, 182),
  (69, 27, 3, 107),
  (70, 28, 1, 13),
  (71, 28, 2, 14),
  (72, 29, 1, 53),
  (73, 29, 2, 84),
  (74, 29, 3, 90),
  (75, 30, 1, 38),
  (76, 30, 2, 29),
  (77, 31, 1, 69),
  (78, 31, 2, 106),
  (79, 31, 3, 77),
  (80, 32, 1, 69),
  (81, 32, 2, 172),
  (82, 33, 1, 57),
  (83, 33, 2, 98),
  (84, 33, 3, 81),
  (85, 34, 1, 42),
  (86, 34, 2, 100);

INSERT INTO stock_ledger (id, product_id, warehouse_id, movement_type, quantity, reference_type, reference_id, performed_by, created_at) VALUES
  (1, 1, 1, 'Adjustment', 45, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (2, 1, 2, 'Adjustment', 43, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (3, 1, 3, 'Adjustment', 94, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (4, 2, 1, 'Adjustment', 82, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (5, 2, 2, 'Adjustment', 86, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (6, 3, 1, 'Adjustment', 63, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (7, 3, 2, 'Adjustment', 29, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (8, 3, 3, 'Adjustment', 81, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (9, 4, 1, 'Adjustment', 62, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (10, 4, 2, 'Adjustment', 56, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (11, 5, 1, 'Adjustment', 13, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (12, 5, 2, 'Adjustment', 14, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (13, 5, 3, 'Adjustment', 15, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (14, 6, 1, 'Adjustment', 108, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (15, 6, 2, 'Adjustment', 64, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (16, 7, 2, 'Adjustment', 10, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (17, 7, 3, 'Adjustment', 7, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (18, 8, 1, 'Adjustment', 54, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (19, 8, 2, 'Adjustment', 64, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (20, 9, 1, 'Adjustment', 108, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (21, 9, 2, 'Adjustment', 126, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (22, 9, 3, 'Adjustment', 70, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (23, 10, 1, 'Adjustment', 44, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (24, 10, 2, 'Adjustment', 45, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (25, 11, 1, 'Adjustment', 54, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (26, 11, 2, 'Adjustment', 42, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (27, 11, 3, 'Adjustment', 67, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (28, 12, 1, 'Adjustment', 49, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (29, 12, 2, 'Adjustment', 51, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (30, 13, 1, 'Adjustment', 68, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (31, 13, 2, 'Adjustment', 34, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (32, 13, 3, 'Adjustment', 31, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (33, 14, 1, 'Adjustment', 2, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (34, 14, 2, 'Adjustment', 9, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (35, 15, 1, 'Adjustment', 23, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (36, 15, 2, 'Adjustment', 23, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (37, 15, 3, 'Adjustment', 22, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (38, 16, 1, 'Adjustment', 61, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (39, 16, 2, 'Adjustment', 81, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (40, 17, 1, 'Adjustment', 100, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (41, 17, 2, 'Adjustment', 55, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (42, 17, 3, 'Adjustment', 87, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (43, 18, 1, 'Adjustment', 72, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (44, 18, 2, 'Adjustment', 72, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (45, 19, 1, 'Adjustment', 53, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (46, 19, 2, 'Adjustment', 79, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (47, 19, 3, 'Adjustment', 54, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (48, 20, 1, 'Adjustment', 6, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (49, 20, 2, 'Adjustment', 10, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (50, 21, 1, 'Adjustment', 1, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (51, 21, 2, 'Adjustment', 3, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (52, 21, 3, 'Adjustment', 2, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (53, 22, 1, 'Adjustment', 21, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (54, 22, 2, 'Adjustment', 52, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (55, 23, 1, 'Adjustment', 40, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (56, 23, 2, 'Adjustment', 73, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (57, 23, 3, 'Adjustment', 27, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (58, 24, 1, 'Adjustment', 68, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (59, 24, 2, 'Adjustment', 97, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (60, 25, 1, 'Adjustment', 12, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (61, 25, 2, 'Adjustment', 9, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (62, 25, 3, 'Adjustment', 8, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (63, 26, 1, 'Adjustment', 56, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (64, 26, 2, 'Adjustment', 60, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (65, 27, 1, 'Adjustment', 113, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (66, 27, 2, 'Adjustment', 141, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (67, 27, 3, 'Adjustment', 107, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (68, 28, 1, 'Adjustment', 13, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (69, 28, 2, 'Adjustment', 14, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (70, 29, 1, 'Adjustment', 53, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (71, 29, 2, 'Adjustment', 84, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (72, 29, 3, 'Adjustment', 90, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (73, 30, 1, 'Adjustment', 30, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (74, 30, 2, 'Adjustment', 31, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (75, 31, 1, 'Adjustment', 69, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (76, 31, 2, 'Adjustment', 70, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (77, 31, 3, 'Adjustment', 77, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (78, 32, 1, 'Adjustment', 69, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (79, 32, 2, 'Adjustment', 133, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (80, 33, 1, 'Adjustment', 57, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (81, 33, 2, 'Adjustment', 66, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (82, 33, 3, 'Adjustment', 81, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (83, 34, 1, 'Adjustment', 42, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (84, 34, 2, 'Adjustment', 100, 'Manual', NULL, 1, '2026-06-01 08:00:00'),
  (85, 7, 1, 'Receipt', 30, 'PurchaseOrder', 1, 4, '2026-06-07 10:00:00'),
  (86, 26, 1, 'Receipt', 39, 'PurchaseOrder', 1, 4, '2026-06-07 10:00:00'),
  (87, 12, 2, 'Receipt', 34, 'PurchaseOrder', 2, 4, '2026-06-09 10:00:00'),
  (88, 23, 2, 'Issue', -13, 'SalesOrder', 1, 5, '2026-06-10 14:00:00'),
  (89, 31, 2, 'Receipt', 36, 'PurchaseOrder', 3, 4, '2026-06-11 10:00:00'),
  (90, 33, 2, 'Receipt', 32, 'PurchaseOrder', 3, 4, '2026-06-11 10:00:00'),
  (91, 2, 2, 'Issue', -20, 'SalesOrder', 2, 4, '2026-06-12 14:00:00'),
  (92, 1, 2, 'Issue', -13, 'SalesOrder', 3, 5, '2026-06-13 14:00:00'),
  (93, 24, 2, 'Issue', -4, 'SalesOrder', 3, 4, '2026-06-13 14:00:00'),
  (94, 11, 2, 'Receipt', 42, 'PurchaseOrder', 4, 1, '2026-06-14 10:00:00'),
  (95, 32, 2, 'Receipt', 39, 'PurchaseOrder', 4, 1, '2026-06-14 10:00:00'),
  (96, 2, 2, 'Receipt', 27, 'PurchaseOrder', 4, 1, '2026-06-14 10:00:00'),
  (97, 8, 1, 'Receipt', 54, 'PurchaseOrder', 5, 5, '2026-06-15 10:00:00'),
  (98, 10, 1, 'Receipt', 36, 'PurchaseOrder', 5, 5, '2026-06-15 10:00:00'),
  (99, 8, 1, 'Receipt', 37, 'PurchaseOrder', 5, 5, '2026-06-15 10:00:00'),
  (100, 18, 1, 'Issue', -16, 'SalesOrder', 4, 5, '2026-06-15 14:00:00'),
  (101, 5, 1, 'Issue', -6, 'SalesOrder', 4, 4, '2026-06-15 14:00:00'),
  (102, 22, 1, 'Issue', -16, 'SalesOrder', 4, 4, '2026-06-15 14:00:00'),
  (103, 1, 1, 'Receipt', 54, 'PurchaseOrder', 6, 4, '2026-06-16 10:00:00'),
  (104, 30, 1, 'Receipt', 8, 'PurchaseOrder', 6, 4, '2026-06-16 10:00:00'),
  (105, 20, 1, 'Receipt', 24, 'PurchaseOrder', 6, 4, '2026-06-16 10:00:00'),
  (106, 30, 2, 'Issue', -2, 'SalesOrder', 5, 5, '2026-06-16 14:00:00'),
  (107, 21, 2, 'Receipt', 10, 'PurchaseOrder', 7, 5, '2026-06-17 10:00:00'),
  (108, 27, 2, 'Receipt', 41, 'PurchaseOrder', 7, 5, '2026-06-17 10:00:00'),
  (109, 4, 3, 'Receipt', 13, 'PurchaseOrder', 8, 4, '2026-06-19 10:00:00'),
  (110, 11, 3, 'Receipt', 23, 'PurchaseOrder', 8, 4, '2026-06-19 10:00:00');
