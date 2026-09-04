# Index analysis (DB-01)

> **Resolved after this analysis was written.** This document found that
> `idx_so_seller_date (created_by, order_date)` was declared in `schema.sql` but absent from the
> running database, because MySQL only executes the seed file when the data volume is created and
> the volume predated that revision. The database has since been rebuilt from an empty volume and
> the index is present. The redundant `idx_so_created_by` was dropped at the same time: it is the
> leftmost prefix of the composite index, which also satisfies the `fk_so_creator` foreign key,
> so MySQL needs no separate index — verified after the rebuild, and the Sales list query now
> plans as `key=idx_so_seller_date … Extra: Backward index scan; Using index`, a covering scan.
> The `git add -A` habit that let the unreviewed schema change into a commit is recorded as TD-09.

Verification that the indexes declared in `database/schema.sql` are actually chosen by the MySQL
optimiser for the queries the application really runs.

Method: every query below was taken from the repository classes under `app/Repository/`, the
placeholders replaced with literal values that exist in the seeded data, and run through
`EXPLAIN` (and `EXPLAIN ANALYZE` where the join order was in doubt) against the live container.

```
docker compose exec -T db mysql -uioms -p... ioms -e "EXPLAIN <query>"
```

Environment: MySQL 8.0 in Docker, database `ioms`, seed data as shipped.

Row counts at the time of measurement:

| Table | Rows | | Table | Rows |
|---|---|---|---|---|
| products | 34 | | sales_orders | 16 |
| product_stocks | 86 | | sales_order_items | 30 |
| stock_ledger | 114 | | purchase_orders | 15 |
| customers | 8 | | purchase_order_items | 32 |
| suppliers | 6 | | users | 7 |
| categories | 6 | | warehouses | 3 |

Thirty-three distinct queries were explained. The plans below are the ones that matter; the
verdict table covers every index in the schema.

---

## Finding first: `idx_so_seller_date` does not exist in the running database

`database/schema.sql` line 196 declares:

```sql
KEY idx_so_seller_date (created_by, order_date),
```

`SHOW CREATE TABLE sales_orders` on the live database does not list it:

```
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_so_number` (`so_number`),
  KEY `idx_so_status` (`status`),
  KEY `idx_so_order_date` (`order_date`),
  KEY `idx_so_created_by` (`created_by`),
  KEY `fk_so_customer` (`customer_id`),
  KEY `fk_so_warehouse` (`warehouse_id`),
  KEY `fk_so_approver` (`approved_by`),
```

The data volume was created from an earlier revision of the schema, and
`database/schema-and-seed.sql` is only executed when the volume is created (see CLAUDE.md).
The index therefore exists in the source of truth but not in the instance an assessor would
query today unless they run `docker compose down -v` first. Anyone claiming this index is in
use should re-create the volume before saying so.

A second, smaller point: if `idx_so_seller_date (created_by, order_date)` is created, then
`idx_so_created_by (created_by)` becomes redundant — the composite's leftmost prefix serves
every query the single-column index serves, including the foreign-key requirement for
`fk_so_creator`. Two indexes are being maintained where one would do. Neither is expensive at
this row count, but the duplication is unnecessary and worth removing from `schema.sql`.

---

## Verdict table

"Query served" names the repository method the index was added for. The verdict is what
`EXPLAIN` actually reported, not what the schema comment claims.

| Index | Table | Columns | Query it serves | Verdict |
|---|---|---|---|---|
| `uq_stock_product_warehouse` | `product_stocks` | `(product_id, warehouse_id)` | `MySqlStockRepository::readBalanceForUpdate` — the `FOR UPDATE` locking read | **Used**, `type=const`, `rows=1`. Also used as a `ref`/`eq_ref` join key by the product list, the low-stock query, the dashboard value aggregate and the order-item queries. The single most exercised index in the schema. |
| `idx_stock_warehouse` | `product_stocks` | `(warehouse_id)` | required by `fk_stock_warehouse`; no application query filters on warehouse alone | **Not exercised** by application SQL. Keep — it is the foreign-key index. |
| `PRIMARY` | all tables | `(id)` | every `findById`, every join back to a parent row | **Used**, `type=const` or `eq_ref` throughout. |
| `uq_users_email` | `users` | `(email)` | `MySqlUserRepository` login lookup | **Used**, `type=const`, `Using index`. |
| `uq_products_sku` | `products` | `(sku)` | `findBySku`, `skuExists` | **Used**, `type=const`, `Using index`. |
| `idx_products_name` | `products` | `(name)` | `ORDER BY p.name` on the product list | **Not used by the real query.** Used when `products` is queried on its own (`type=index`, `Using index`), but the actual list query groups by `p.id` and sorts in a temporary table. See the filesort section. |
| `idx_products_category` | `products` | `(category_id)` | category filter on the product list | **Used**, `type=ref`. Also chosen as the driving join key for the unfiltered product list. |
| `idx_products_active` | `products` | `(is_active)` | `activeProductCount`, `activeOnly` filter | **Used** for `COUNT(*) WHERE is_active = 1` (`type=ref`, `Using index`). Considered but rejected when the predicate is combined with a category filter, which is the better choice. |
| `uq_categories_name` | `categories` | `(name)` | uniqueness | **Used incidentally** as a covering index when `categories` drives the product-list join. |
| `uq_warehouses_name` | `warehouses` | `(name)` | uniqueness; `ORDER BY w.name` in `stockLevels` | **Used**, including as the driving covering scan in the ledger report. |
| `idx_customers_name` | `customers` | `(name)` | customer lookup by name | **Used** for an anchored `LIKE 'A%'` (`type=range`). Not used by the order-list search, which is `LIKE '%term%'` and cannot use it. |
| `idx_suppliers_name` | `suppliers` | `(name)` | as above | **Used** for anchored `LIKE` only; same caveat. |
| `uq_po_number` | `purchase_orders` | `(po_number)` | `nextNumber()` | **Used**, backward index scan, `rows=1`. |
| `idx_po_status` | `purchase_orders` | `(status)` | PO list status filter, dashboard receipt queue, PO-by-status counts | **Used** in three forms: `ref` for a single status, `range` for `IN ('Ordered','PartiallyReceived')`, and a covering `index` scan for `GROUP BY status`. |
| `idx_po_order_date` | `purchase_orders` | `(order_date)` | `between()` date range | **Used**, `type=range`, `Using index`. Not used by the paginated list, which sorts but does not filter on the date. |
| `idx_po_supplier` | `purchase_orders` | `(supplier_id)` | FK index; supplier drill-down | **Used** when filtering by supplier (`ref`, `Using index`). No current screen does so — see the unexercised section. |
| `uq_so_number` | `sales_orders` | `(so_number)` | `nextNumber()` | **Used**, `Backward index scan; Using index`. |
| `idx_so_status` | `sales_orders` | `(status)` | SO list status filter, issue queue, dashboard counts | **Used**, and chosen in preference to the seller index when both predicates are present. |
| `idx_so_order_date` | `sales_orders` | `(order_date)` | `between()` date range | **Used** for an explicit date range. Not used for `ORDER BY order_date DESC LIMIT 10`. |
| `idx_so_created_by` | `sales_orders` | `(created_by)` | Sales-user ownership restriction | **Used** intermittently — see below; the plan flips between this index and a full scan. |
| `idx_so_seller_date` | `sales_orders` | `(created_by, order_date)` | seller's orders in a date range | **Absent from the live database.** Cannot be exercised. |
| `idx_poi_order` | `purchase_order_items` | `(purchase_order_id)` | `itemsFor()` | **Used**, `type=ref`. |
| `idx_poi_product` | `purchase_order_items` | `(product_id)` | `isUsedOnAnyOrder()` | **Used**, `ref`, `Using index`. |
| `idx_soi_order` | `sales_order_items` | `(sales_order_id)` | `itemsFor()`, dashboard order-value aggregate | **Used**, `type=ref`. |
| `idx_soi_product` | `sales_order_items` | `(product_id)` | `isUsedOnAnyOrder()` | **Used**, `ref`, `Using index`. |
| `idx_ledger_product_warehouse_date` | `stock_ledger` | `(product_id, warehouse_id, created_at)` | `forProduct()`, `balanceFromLedger()` | **Used** by both, via the leftmost prefix (`product_id`) and the two-column prefix respectively. **Not** used by the date-range report. |
| `idx_ledger_created_at` | `stock_ledger` | `(created_at)` | `between()` date-range report | **Not used by the real report query** — the optimiser drives the join from `warehouses` instead. It *is* used when `stock_ledger` is filtered on its own. See below. |
| `idx_ledger_reference` | `stock_ledger` | `(reference_type, reference_id)` | tracing a movement back to its PO/SO | **Used**, `ref`, `Using index`. No screen currently issues this query. |
| `fk_ledger_warehouse` | `stock_ledger` | `(warehouse_id)` | auto-created FK index | **Used** — and it is the index the date-range report actually uses, not `idx_ledger_created_at`. |
| `fk_ledger_user` | `stock_ledger` | `(performed_by)` | auto-created FK index | Not chosen in any measured plan; `users` is always the inner table of an `eq_ref` on its own primary key. Required for the FK regardless. |
| `fk_po_creator`, `fk_po_warehouse`, `fk_so_customer`, `fk_so_warehouse`, `fk_so_approver` | | | auto-created FK indexes | Not chosen as access paths; the parent tables are always reached by primary key. Required for the FKs. |

---

## Raw EXPLAIN output

### 1. The locking read — `MySqlStockRepository::readBalanceForUpdate` (ARCH-02)

```sql
EXPLAIN SELECT quantity FROM product_stocks
 WHERE product_id = 7 AND warehouse_id = 2 FOR UPDATE;
```

```
           id: 1
  select_type: SIMPLE
        table: product_stocks
   partitions: NULL
         type: const
possible_keys: uq_stock_product_warehouse,idx_stock_warehouse
          key: uq_stock_product_warehouse
      key_len: 8
          ref: const,const
         rows: 1
     filtered: 100.00
        Extra: NULL
```

`type=const` with `ref: const,const` and `key_len: 8` (two four-byte unsigned integers) is the
strongest access type MySQL reports: both parts of the unique key are pinned to constants, so
exactly one index record can match, and `rows=1` is a certainty rather than an estimate.

### 2. Contrast — the same table with no usable index

```sql
EXPLAIN SELECT quantity FROM product_stocks WHERE quantity = 40 FOR UPDATE;
```

```
           id: 1
  select_type: SIMPLE
        table: product_stocks
   partitions: NULL
         type: ALL
possible_keys: NULL
          key: NULL
      key_len: NULL
          ref: NULL
         rows: 86
     filtered: 10.00
        Extra: Using where
```

`EXPLAIN ANALYZE` of the two, side by side:

```
-- unique lookup
-> Rows fetched before execution  (cost=0..0 rows=1) (actual time=167e-6..209e-6 rows=1 loops=1)

-- non-indexed predicate
-> Filter: (product_stocks.quantity = 40)  (cost=8.85 rows=8.6) (actual time=0.0235..0.0277 rows=1 loops=1)
    -> Table scan on product_stocks  (cost=8.85 rows=86) (actual time=0.0165..0.0225 rows=86 loops=1)
```

The second plan visits all 86 rows to return one. Under a `FOR UPDATE` in REPEATABLE READ that
is the whole table's worth of records — every row it examines is locked, not only the row it
returns.

### 3. Ledger date-range report — `MySqlStockLedgerRepository::between()` (REPORT-01)

```sql
EXPLAIN SELECT l.id, l.product_id, l.quantity, p.name, w.name, u.name
  FROM stock_ledger l
  JOIN products p ON p.id = l.product_id
  JOIN warehouses w ON w.id = l.warehouse_id
  JOIN users u ON u.id = l.performed_by
 WHERE l.created_at >= '2026-01-01'
   AND l.created_at < DATE_ADD('2026-09-04', INTERVAL 1 DAY)
 ORDER BY l.created_at DESC, l.id DESC;
```

```
table  type    key                   rows  Extra
w      index   uq_warehouses_name    3     Using index; Using temporary; Using filesort
l      ref     fk_ledger_warehouse   38    Using where
u      eq_ref  PRIMARY               1     NULL
p      eq_ref  PRIMARY               1     NULL
```

```
-> Sort: l.created_at DESC, l.id DESC  (actual time=0.661..0.667 rows=114 loops=1)
    -> Stream results  (cost=94 rows=114) (actual time=0.284..0.597 rows=114 loops=1)
        -> Nested loop inner join  (cost=94 rows=114) (actual time=0.273..0.548 rows=114 loops=1)
            -> Nested loop inner join  (cost=54.1 rows=114) (actual time=0.246..0.382 rows=114 loops=1)
                -> Nested loop inner join  (cost=14.2 rows=114) (actual time=0.234..0.334 rows=114 loops=1)
                    -> Covering index scan on w using uq_warehouses_name  (cost=0.55 rows=3) (actual time=0.0407..0.0416 rows=3 loops=1)
                    -> Filter: ((l.created_at >= TIMESTAMP'2026-01-01 00:00:00') and (l.created_at < <cache>(('2026-09-04' + interval 1 day))))  (cost=2.02 rows=38) (actual time=0.0749..0.0873 rows=38 loops=3)
                        -> Index lookup on l using fk_ledger_warehouse (warehouse_id=w.id)  (cost=2.02 rows=38) (actual time=0.0729..0.0873 rows=38 loops=3)
                -> Single-row index lookup on u using PRIMARY (id=l.performed_by)  (cost=0.251 rows=1) (actual time=0.00132..0.00134 rows=1 loops=114)
            -> Single-row index lookup on p using PRIMARY (id=l.product_id)  (cost=0.251 rows=1) (actual time=0.00132..0.00134 rows=1 loops=114)
```

`idx_ledger_created_at` was not used. The optimiser drove the join from the three-row
`warehouses` table and reached the ledger by `warehouse_id`, applying the date predicate as a
filter afterwards. Filtered on its own the ledger does use the date index:

```sql
EXPLAIN SELECT id FROM stock_ledger
 WHERE created_at >= '2026-01-01' AND created_at < '2026-09-05';
```

```
table         type   key                     rows  Extra
stock_ledger  index  idx_ledger_created_at   114   Using where; Using index
```

The date range currently selects every row in the table, so no index could narrow it and the
optimiser's choice is reasonable. This does not tell us what will happen at a realistic volume;
it only tells us the date index is not load-bearing today.

### 4. Product list with search, category and pagination — `MySqlProductRepository::paginate`

```sql
EXPLAIN SELECT p.id, p.sku, p.name, ..., COALESCE(SUM(ps.quantity), 0) AS total_stock
  FROM products p
  JOIN categories c ON c.id = p.category_id
  LEFT JOIN product_stocks ps ON ps.product_id = p.id
 WHERE p.is_active = 1 AND (p.name LIKE '%a%' OR p.sku LIKE '%a%') AND p.category_id = 2
 GROUP BY p.id ORDER BY p.name LIMIT 10 OFFSET 0;
```

```
table  type   key                          rows  filtered  Extra
c      const  PRIMARY                      1     100.00    Using temporary; Using filesort
p      ref    idx_products_category        7     19.75     Using where
ps     ref    uq_stock_product_warehouse   2     100.00    NULL
```

The category filter resolves `categories` to a constant row and narrows `products` to seven
candidates. `LIKE '%a%'` is unanchored and cannot use `idx_products_name`; it is applied as a
`Using where` filter, which is the only thing a leading wildcard permits. The stock aggregate
reaches `product_stocks` through the unique index.

### 5. Low-stock list — `MySqlProductRepository::lowStock()`

```sql
EXPLAIN SELECT p.id, p.name, p.reorder_point, COALESCE(SUM(ps.quantity), 0) AS total_stock
  FROM products p
  JOIN categories c ON c.id = p.category_id
  LEFT JOIN product_stocks ps ON ps.product_id = p.id
 WHERE p.is_active = 1
 GROUP BY p.id HAVING total_stock <= p.reorder_point
 ORDER BY (p.reorder_point - total_stock) DESC, p.name;
```

```
table  type    key                          rows  Extra
p      index   PRIMARY                      34    Using where; Using temporary; Using filesort
c      eq_ref  PRIMARY                      1     Using index
ps     ref     uq_stock_product_warehouse   2     NULL
```

All 34 products are scanned in primary-key order. That is unavoidable: the low-stock test
compares an aggregate against a per-row column, so it can only be evaluated after grouping.

### 6. Sales-order list, filtered by status, restricted to one seller — `MySqlSalesOrderRepository::paginate`

```sql
EXPLAIN SELECT so.id, ..., c.name, w.name, cu.name, au.name
  FROM sales_orders so
  JOIN customers c ON c.id = so.customer_id
  JOIN warehouses w ON w.id = so.warehouse_id
  JOIN users cu ON cu.id = so.created_by
  LEFT JOIN users au ON au.id = so.approved_by
 WHERE so.created_by = 2 AND so.status = 'Approved'
 ORDER BY so.order_date DESC, so.id DESC LIMIT 10 OFFSET 0;
```

```
table  type    key            rows  filtered  Extra
cu     const   PRIMARY        1     100.00    Using filesort
so     ref     idx_so_status  3     43.75     Using where
w      eq_ref  PRIMARY        1     100.00    NULL
au     eq_ref  PRIMARY        1     100.00    NULL
c      eq_ref  PRIMARY        1     100.00    NULL
```

The status index wins over the seller index; `created_by` becomes a `Using where` filter on
three candidate rows. Every joined lookup is `eq_ref` on a primary key, so the join itself costs
nothing. The `ORDER BY` is a filesort — see the last section.

The matching count query is equally cheap:

```sql
EXPLAIN SELECT COUNT(*) FROM sales_orders so
  JOIN customers c ON c.id = so.customer_id WHERE so.status = 'Approved';
```

```
table  type    key            rows  Extra
so     ref     idx_so_status  3     NULL
c      eq_ref  PRIMARY        1     Using index
```

### 7. Dashboard aggregations — `DashboardRepository`

```sql
EXPLAIN SELECT status, COUNT(*) FROM purchase_orders GROUP BY status;
EXPLAIN SELECT COUNT(*) FROM purchase_orders WHERE status IN ('Ordered', 'PartiallyReceived');
EXPLAIN SELECT COUNT(*) FROM sales_orders WHERE status = 'Approved';
EXPLAIN SELECT COUNT(*) FROM products WHERE is_active = 1;
```

```
purchase_orders  index  idx_po_status        15  Using index
purchase_orders  range  idx_po_status        6   Using where; Using index
sales_orders     ref    idx_so_status        3   Using index
products         ref    idx_products_active  32  Using index
```

All four are index-only: `Using index` means no table rows were read at all. The status indexes
earn their keep here.

The two value aggregates cannot avoid touching every row, because they sum over the whole
population by definition:

```sql
EXPLAIN SELECT COALESCE(SUM(ps.quantity * p.purchase_price), 0)
  FROM product_stocks ps JOIN products p ON p.id = ps.product_id;
```

```
table  type  key                          rows  Extra
p      ALL   NULL                         34    NULL
ps     ref   uq_stock_product_warehouse   2     NULL
```

```sql
EXPLAIN SELECT COALESCE(SUM(i.quantity * i.selling_price), 0)
  FROM sales_order_items i JOIN sales_orders so ON so.id = i.sales_order_id
 WHERE so.status <> 'Cancelled';
```

```
table  type    key            rows  Extra
so     index   idx_so_status  16    Using where; Using index
i      ref     idx_soi_order  1     NULL
```

### 8. Ledger by product and ledger balance

```sql
EXPLAIN SELECT l.id, l.quantity FROM stock_ledger l
  JOIN products p ON p.id = l.product_id
 WHERE l.product_id = 7 ORDER BY l.created_at DESC, l.id DESC LIMIT 50;

EXPLAIN SELECT COALESCE(SUM(quantity), 0) FROM stock_ledger
 WHERE product_id = 7 AND warehouse_id = 2;
```

```
p             const  PRIMARY                             1  Using index; Using filesort
l             ref    idx_ledger_product_warehouse_date   3  NULL

stock_ledger  ref    idx_ledger_product_warehouse_date   1  NULL
```

This is the composite index doing what its schema comment claims: the first query uses the
one-column prefix `(product_id)`, the second the two-column prefix `(product_id, warehouse_id)`.
The leftmost-prefix rule is demonstrably working.

---

## The index that matters most (DB-01)

`UNIQUE KEY uq_stock_product_warehouse (product_id, warehouse_id)` on `product_stocks`.

In plain English: the table holds one row per product per warehouse — "how many of product 7 are
in warehouse 2". The unique key says that combination may appear at most once, and it gives
MySQL a direct route to that one row rather than a search through the table.

Its first job is correctness of the data shape. Without it, two rows could exist for the same
product and warehouse, and the total in one place would depend on which row you read. The
service layer could try to prevent this by checking before inserting, but a check followed by an
insert is a race: two concurrent requests can both pass the check. Only the database can settle
it, which is why `ensureRow()` can safely use `INSERT IGNORE` — the constraint, not the
application, is what makes the operation idempotent.

Its second job — the one ADR-002 depends on — is lock granularity. Goods issue reads the current
balance with

```sql
SELECT quantity FROM product_stocks WHERE product_id = ? AND warehouse_id = ? FOR UPDATE;
```

InnoDB does not lock rows; it locks *index records*. What gets locked is therefore decided by
the access path the optimiser picks, which is exactly what `EXPLAIN` tells us. Plan 1 above
reports `type=const`, `key=uq_stock_product_warehouse`, `ref=const,const`, `key_len=8`,
`rows=1`. Both columns of a unique index are fixed to constants, so MySQL knows before it
starts that at most one index record can satisfy the predicate. That is the case in which InnoDB
takes a single record lock and no gap lock: there is no range to protect, because a unique index
with every part specified cannot acquire a new matching row later.

Compare plan 2, the same table filtered on the unindexed `quantity` column: `type=ALL`,
`key=NULL`, `rows=86`. A locking read down that path examines every record it scans and locks
each one, together with the gaps between them under REPEATABLE READ. Two goods issues for
*different* products would then block each other, and the "lock exactly what you are about to
change" property that makes the design safe would be gone. So the difference the index makes is
not a matter of speed — at 86 rows the scan takes 28 microseconds — it is the difference between
locking one row and locking the table.

That is why the schema comment calls it a correctness requirement rather than a nicety, and the
claim survives inspection. The `CHECK (quantity >= 0)` constraint is the backstop: if the
locking were ever wrong, the write fails and the transaction rolls back instead of persisting
negative stock.

One caveat, stated plainly because it is the honest limit of this evidence. `EXPLAIN` proves
which index is used and that the lookup is unique; it does not itself show the locks. Direct
observation would need `performance_schema.data_locks`, and the application's `ioms` user is
not granted access to it:

```
ERROR 1142 (42000): SELECT command denied to user 'ioms'@'localhost' for table 'data_locks'
```

The lock-granularity argument therefore rests on the documented InnoDB behaviour for a unique
`const` lookup plus the access path confirmed above, not on a direct reading of the lock table.
Behavioural proof that oversell does not occur belongs to the concurrency integration test, not
to this document.

### One point where ADR-002 overstates the case

ADR-002 says: "without it MySQL would scan and take gap locks over a range". The direction is
right and the measurement supports it, but "would" is stronger than the situation warrants —
`product_stocks` also has `idx_stock_warehouse`, so a hypothetical schema lacking the unique key
would probably reach the row through `warehouse_id` and lock the whole warehouse's worth of
records rather than the entire table. Either way the lock is far wider than one row, which is
the point ADR-002 is making; the specific mechanism it names is not the only one available to
the optimiser. No correction to the code or the schema follows from this — only a note that the
ADR's phrasing is more definite than the evidence.

---

## Indexes that are NOT currently exercised

Honest accounting. "Not exercised" means no query the application issues today causes the
optimiser to choose it.

**Declared but missing from the running database**

- `idx_so_seller_date (created_by, order_date)` — present in `schema.sql`, absent from the live
  instance (see the opening section). Cannot be exercised until the volume is re-created. When
  it is created it will make `idx_so_created_by` redundant, and one of the two should be dropped
  from `schema.sql`. Recommendation: keep the composite, drop the single-column index — the
  composite still satisfies the `fk_so_creator` foreign key through its leftmost prefix.

**Present but never chosen by any current query**

- `idx_ledger_created_at (created_at)` — the report query it was added for drives the join from
  `warehouses` instead. **Keep.** The date range currently covers the whole 114-row table, so no
  index could help; once the ledger holds enough history for a month's range to be a small
  fraction of the table, the optimiser's cost estimate will change and this index is the one it
  will want. Dropping it would be optimising for the seed data.
- `idx_ledger_reference (reference_type, reference_id)` — verified to work
  (`ref`, `Using index`) but nothing calls it: no repository method looks a movement up by its
  source document. **Keep**, with the caveat that it is currently speculative. "Which ledger
  rows came from this PO?" is a natural next question for an audit screen, and the index is two
  small columns on an append-only table. If the assessment penalises anything unused, this is
  the one index in the schema that is defensible only on foreseeable use rather than measured
  use.
- `idx_po_supplier (supplier_id)` — works, but no screen filters purchase orders by supplier.
  **Keep**: it is also the index the `fk_po_supplier` foreign key needs, so it is not optional.
- `idx_stock_warehouse (warehouse_id)` — no application query filters `product_stocks` by
  warehouse alone. **Keep**: required by `fk_stock_warehouse`.
- `fk_ledger_user`, `fk_po_creator`, `fk_po_warehouse`, `fk_so_customer`, `fk_so_warehouse`,
  `fk_so_approver` — MySQL created these automatically for the foreign keys. None is chosen as
  an access path, because those parent tables are always reached by primary key from the child
  row. They are not droppable while the constraints exist. The exception is
  `fk_ledger_warehouse`, which turned out to be the index the date-range report actually uses.
- `idx_products_name (name)` — used when `products` is queried alone, but not by the product
  list it was added for. See the next section. **Keep**: `forSelect()` and any future
  name-ordered listing without the stock aggregate can use it, and it is one column.
- `idx_customers_name`, `idx_suppliers_name` — used for anchored `LIKE 'A%'` only. The
  application's own search is `LIKE '%term%'`, which no B-tree index on `name` can serve. They
  are exercised by nothing the UI does today. **Keep** for typeahead-style prefix lookup; note
  in `tech-debt.md` that if substring search over names ever needs to be fast, the answer is a
  `FULLTEXT` index, not these.

Nothing in the schema should be dropped on the evidence here except the redundant pair on
`sales_orders`.

---

## Queries with a full scan or filesort, and whether that is acceptable

At 34 products, 86 stock rows, 31 orders and 114 ledger rows, every table in this database fits
in a single InnoDB page range and is certain to be in the buffer pool. A full scan of the
largest table reads 114 rows. The measured cost of the worst plan on this page is 0.67 ms. What
follows is therefore an assessment of whether each plan would still be defensible at a
plausible production volume, not a claim that anything needs work now.

**Acceptable, and would remain acceptable**

- **`SELECT ... FROM products` ordered by name (`Using temporary; Using filesort`).** The list
  aggregates `SUM(ps.quantity)` per product, so it must group before it can sort, and a grouped
  result cannot be delivered in index order by `name`. The filesort sorts at most 34 rows. This
  is inherent to asking for "products with their total stock, in name order" in one query; the
  alternative is two queries and a join in PHP, which is worse. No change.
- **Low-stock list (`type=index`, `rows=34`, filesort).** The predicate compares an aggregate
  with a per-row column, which is only knowable after grouping. There is no index that can
  answer it. If products ever reached hundreds of thousands, the fix would be a maintained
  `total_stock` column on `products` updated inside the same stock transaction — a denormalisation
  with a real cost in complexity, and unjustified here. Recorded in `tech-debt.md` territory, not
  a defect.
- **Dashboard `SUM(ps.quantity * p.purchase_price)` (`p` scanned, `rows=34`).** The query's
  meaning is "every stock row", so a scan is the correct plan; an index would only add a level
  of indirection. Likewise `SUM(quantity)` over `product_stocks` and the sales-order value
  aggregate. These are the only queries in the application whose cost grows linearly with the
  whole dataset by definition, which is the usual argument for caching a dashboard rather than
  indexing it. Not needed at this size.
- **Order list `ORDER BY order_date DESC, id DESC LIMIT 10` (filesort).** With a status filter
  the optimiser uses `idx_so_status` for the filter and sorts the handful of matches. Without a
  filter it scans all 16 rows and sorts them. `idx_so_order_date` cannot be used together with a
  status filter, because MySQL uses one index per table reference here. Sorting 16 rows is free.
  At scale the right index would be a composite `(status, order_date)`, which would let MySQL
  read the filtered rows already in date order and stop after ten. Worth noting as the specific
  next index to add if the order tables grow; adding it now would be an index maintained for no
  measurable benefit, which the brief penalises.
- **Ledger date-range report (`w` covering scan, `l` by `fk_ledger_warehouse`, filesort of 114
  rows).** Discussed above. The range covers the whole table, so nothing better exists.
- **Product count subquery for pagination (`DERIVED` over a full products scan).** Counting the
  low-stock population requires evaluating the aggregate for every product, so the derived table
  is unavoidable given the `HAVING` filter. 34 rows.
- **`SELECT COUNT(*) FROM products WHERE is_active = 1` reports `rows=32` on an index with
  cardinality 2.** A two-value column is normally a poor index candidate, but here the plan is
  `Using index`, so the count is answered from the index without touching a row. The index is
  cheap and the query is on the dashboard. Fine.

**Unstable, worth knowing about**

- **`sales_orders WHERE created_by = ? AND order_date BETWEEN ...`** produced two different
  plans across consecutive runs: once `ref` on `idx_so_created_by` estimating 7 rows, once
  `type=ALL` estimating 16. That is what a cost-based optimiser does when an index would return
  nearly half the table — the scan genuinely is cheaper, and the estimate sits on the boundary.
  It is a symptom of the data volume, not of a schema problem, and it is the reason no
  conclusion about index usefulness should be drawn from a 16-row table without saying so.

**Nothing found that requires a schema change for performance.** The one concrete
recommendation from this exercise is the redundant `sales_orders` index pair, which is a
tidiness matter, and the correction to ADR-002's phrasing.
