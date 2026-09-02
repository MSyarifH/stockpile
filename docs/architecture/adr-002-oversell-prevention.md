# ADR-002 — Pessimistic row locking to prevent oversell

**Status:** Accepted · **Date:** 2026-09-02 · **Requirement:** ARCH-02, DB-01

## Context

Two goods issues for the same product and warehouse, processed at nearly the same moment, must
not drive stock negative, and neither may overwrite the other's update. §8.2 makes a
reproducible oversell a critical failure.

The naive implementation is unsafe, and importantly it is unsafe *by default*:

```php
$qty = SELECT quantity FROM product_stocks WHERE ...;   // A and B both read 5
if ($qty < $requested) throw ...;                       // both pass the check
UPDATE product_stocks SET quantity = quantity - $requested;  // 5 - 3 - 3 = -1
```

MySQL defaults to the `REPEATABLE READ` isolation level. Under it a plain `SELECT` inside a
transaction reads from a consistent snapshot taken at the transaction's first read, so it
cannot see another transaction's uncommitted work. The snapshot is exactly the wrong tool for
deciding whether a finite resource is still available.

## Decision

Every stock movement runs inside one explicit transaction, and the balance is obtained with a
**locking read**:

```sql
SELECT quantity FROM product_stocks
 WHERE product_id = ? AND warehouse_id = ? FOR UPDATE;
```

`FOR UPDATE` bypasses the snapshot, reads the latest committed value, and holds an exclusive
lock on the matching index record until the transaction ends. The second transaction blocks at
this statement; when the first commits, the second reads the updated balance and correctly
rejects the issue.

Three supporting decisions:

1. **`UNIQUE (product_id, warehouse_id)` on `product_stocks`.** InnoDB locks index records. With
   this unique index the lookup takes a single-record lock; without it MySQL would scan and take
   gap locks over a range. The locking strategy is only correct because the index exists.
2. **Line items are locked in a deterministic order** (sorted by `product_id`). Two multi-line
   orders touching the same products in opposite orders would otherwise deadlock.
3. **`CHECK (quantity >= 0)` on `product_stocks`** as a second line of defence. If the locking
   is ever wrong, the write fails and rolls back rather than persisting negative stock.

The transaction boundary reaches the service as an injected `TransactionManager` rather than a
PDO handle, so `StockService` can state that its work is atomic without depending on PDO
(ARCH-01). Rollback happens on any thrown exception, so it cannot be forgotten.

## Consequences

**Good.** Oversell is prevented at the database level, not by application timing. The failure
mode is a *blocked* transaction rather than corrupt data. The mechanism is provable by a
controlled test using two connections, which is what TEST-02 requires.

**Bad.** Locks are held for the duration of the transaction, so transactions must stay short —
no file I/O, no HTTP calls, no email between `beginTransaction` and `commit`. Under heavy
concurrency on a single hot product, throughput is serialised for that row.

**Risk accepted.** Deadlock remains theoretically possible if some future code path locks rows
in a different order. Mitigated by the deterministic sort, and it would surface as a caught
exception and rollback, not as data corruption.

## Alternatives considered

**Conditional atomic update.**

```sql
UPDATE product_stocks SET quantity = quantity - ?
 WHERE product_id = ? AND warehouse_id = ? AND quantity >= ?;
-- rowCount() === 0  ⇒  insufficient stock
```

Genuinely elegant: check and write are inseparable, so no window exists between them. Rejected
for three reasons. A sales order has multiple line items and must be validated in full before
any line is written, but this writes as it goes — failing on line 3 means manually undoing lines
1 and 2. It returns only a row count, while the ledger row and the error message both need the
resulting balance. And it expresses the rule as a `WHERE` clause rather than as a business
decision in the service, which weakens the layering the project is graded on.

**Optimistic locking with a version column.** Read version, update `WHERE version = ?`, retry on
mismatch. Appropriate when conflicts are rare and retries are cheap. Rejected here: stock rows
for popular products are precisely where conflicts are *not* rare, so this degrades into a retry
loop, and retry logic under contention is harder to reason about and to demonstrate than a lock.

**Application-level mutex (file lock or `GET_LOCK`).** Rejected: it puts correctness outside the
database, so anything writing stock without taking the lock silently breaks the guarantee.
