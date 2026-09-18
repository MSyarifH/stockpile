# Entity Relationship Diagram (initial)

Drawn before implementation, from §1.3 of the brief. Reflects `database/schema.sql`.

**StarUML version:** [`erd.mdj`](erd.mdj) holds the same 12 entities as a StarUML ER data model
(12 entities, 90 columns, 17 relationships), for opening in a UML tool rather than reading as
Mermaid. It is **generated** from `database/schema.sql` by
[`scripts/export-staruml.py`](../../scripts/export-staruml.py) — regenerate it after any schema
change rather than editing it, or the two will drift:

```bash
python3 scripts/export-staruml.py
```

> **`schema.sql` stays authoritative.** StarUML's ER model has no vocabulary for CHECK
> constraints, secondary indexes, `ON DELETE`/`ON UPDATE` actions, storage engine or charset, so
> the `.mdj` does not carry them and DDL generated from it would silently omit them. This
> project depends on several: `CHECK (quantity >= 0)` is the last line of defence against
> negative stock, and `UNIQUE (product_id, warehouse_id)` is what makes `SELECT … FOR UPDATE`
> lock exactly one row instead of a range (ARCH-02). Create the database from
> `database/schema-and-seed.sql`; use the `.mdj` to read and present the structure.

```mermaid
erDiagram
    USERS ||--o{ PURCHASE_ORDERS : "creates"
    USERS ||--o{ SALES_ORDERS : "creates"
    USERS ||--o{ SALES_ORDERS : "approves"
    USERS ||--o{ STOCK_LEDGER : "performs"

    CATEGORIES ||--o{ PRODUCTS : "classifies"
    PRODUCTS ||--o{ PRODUCT_STOCKS : "stocked as"
    WAREHOUSES ||--o{ PRODUCT_STOCKS : "holds"

    SUPPLIERS ||--o{ PURCHASE_ORDERS : "supplies"
    WAREHOUSES ||--o{ PURCHASE_ORDERS : "receives into"
    PURCHASE_ORDERS ||--|{ PURCHASE_ORDER_ITEMS : "contains"
    PRODUCTS ||--o{ PURCHASE_ORDER_ITEMS : "ordered as"

    CUSTOMERS ||--o{ SALES_ORDERS : "buys"
    WAREHOUSES ||--o{ SALES_ORDERS : "ships from"
    SALES_ORDERS ||--|{ SALES_ORDER_ITEMS : "contains"
    PRODUCTS ||--o{ SALES_ORDER_ITEMS : "sold as"

    PRODUCTS ||--o{ STOCK_LEDGER : "moves"
    WAREHOUSES ||--o{ STOCK_LEDGER : "location of"

    USERS {
        int id PK
        string name
        string email UK
        string password_hash
        enum role "Admin|Sales|WarehouseStaff"
        bool is_active
    }
    WAREHOUSES {
        int id PK
        string name UK
        string location
        bool is_active
    }
    CATEGORIES {
        int id PK
        string name UK
        string description
    }
    PRODUCTS {
        int id PK
        string sku UK
        string name
        int category_id FK
        string unit
        decimal purchase_price
        decimal selling_price
        int reorder_point
        string image_path "nullable"
        bool is_active
    }
    PRODUCT_STOCKS {
        int id PK
        int product_id FK
        int warehouse_id FK
        int quantity "CHECK >= 0"
        datetime updated_at
    }
    SUPPLIERS {
        int id PK
        string name
        string contact
        string address
        bool is_active
    }
    CUSTOMERS {
        int id PK
        string name
        string contact
        string address
        bool is_active
    }
    PURCHASE_ORDERS {
        int id PK
        string po_number UK
        int supplier_id FK
        int warehouse_id FK
        enum status "Draft|Ordered|PartiallyReceived|Received|Cancelled"
        date order_date
        int created_by FK
    }
    PURCHASE_ORDER_ITEMS {
        int id PK
        int purchase_order_id FK
        int product_id FK
        int quantity
        int received_quantity
        decimal purchase_price
    }
    SALES_ORDERS {
        int id PK
        string so_number UK
        int customer_id FK
        int warehouse_id FK
        enum status "Draft|PendingApproval|Approved|Fulfilled|Cancelled"
        date order_date
        int created_by FK
        int approved_by FK "nullable"
    }
    SALES_ORDER_ITEMS {
        int id PK
        int sales_order_id FK
        int product_id FK
        int quantity
        decimal selling_price
    }
    STOCK_LEDGER {
        int id PK
        int product_id FK
        int warehouse_id FK
        enum movement_type "Receipt|Issue|Adjustment"
        int quantity "signed: + receipt, - issue"
        enum reference_type "PurchaseOrder|SalesOrder|Manual"
        int reference_id "nullable"
        int performed_by FK
        datetime created_at
    }
```

## Three decisions this diagram encodes

**1. `PRODUCT_STOCKS` is derived state; `STOCK_LEDGER` is the truth.**
The ledger is append-only history. `product_stocks` is a running balance kept alongside it so
list pages need not aggregate the whole ledger on every request. Both are written in the same
transaction, so `SUM(stock_ledger.quantity)` per (product, warehouse) must always equal
`product_stocks.quantity`. That equality is the system's core invariant and is cheap to assert.

**2. `USERS` relates to `SALES_ORDERS` twice.**
`created_by` and `approved_by` are separate columns precisely so segregation of duties (§1.2) is
recorded in the data, not merely enforced in code. `approved_by = created_by` should never exist
in this database, and that is a query anyone can run to audit it.

**3. Ledger quantity is signed rather than always-positive.**
`movement_type` already names the direction, so the sign is technically redundant — but it makes
reconciliation a single `SUM()` instead of a `CASE` expression. The invariant that is easiest to
check is the one that actually gets checked.
