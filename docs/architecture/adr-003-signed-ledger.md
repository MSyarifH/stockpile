# ADR-003 — The ledger is the source of truth; `product_stocks` is a maintained balance

**Status:** Accepted · **Date:** 2026-09-02 · **Requirement:** §1.3, DB-01, DASH-01

## Context

§1.3 requires both a `StockLedger` of movements and a `ProductStock` quantity per warehouse, and
states that stock is changed only by a service that writes a ledger row and updates the balance
in one transaction. Holding the same fact in two places invites drift, and §8.2 makes
`StockLedger` disagreeing with `ProductStock` a critical failure.

Deriving stock from the ledger on demand (`SELECT SUM(quantity) … GROUP BY`) removes the
duplication entirely, but every product list, dashboard tile and availability check would then
aggregate the full movement history — a table that grows without bound.

## Decision

`stock_ledger` is the **authoritative append-only history**. `product_stocks` is a **derived
running balance**, maintained in the same transaction as the ledger write, kept so that reads
are cheap.

`stock_ledger.quantity` is stored **signed**: positive for `Receipt`, negative for `Issue`. The
`movement_type` column already names the direction, so the sign is technically redundant — it is
there so that reconciliation is a single `SUM()`:

```sql
SELECT COUNT(*) FROM product_stocks ps
LEFT JOIN (SELECT product_id, warehouse_id, SUM(quantity) s
             FROM stock_ledger GROUP BY product_id, warehouse_id) l
  ON l.product_id = ps.product_id AND l.warehouse_id = ps.warehouse_id
WHERE COALESCE(l.s, 0) <> ps.quantity;      -- must always be 0
```

Ledger rows are never updated or deleted. A correction is a new `Adjustment` row.

## Consequences

**Good.** Reads stay O(1) per product/warehouse. The invariant is one cheap query, so it is
actually run — it gates the seed generator and is asserted in integration tests. Every change to
a stock figure is traceable to one ledger row with an actor, a timestamp and a reference to the
originating order, which is what makes the numbers defensible. If the balance is ever corrupted,
it can be rebuilt from history.

**Bad.** The duplication is real, and its safety depends entirely on the discipline that only
`StockService` writes either table. That discipline is a convention, not something the database
enforces — a future developer could write `UPDATE product_stocks` directly.

**Mitigation.** A single choke point (`StockService`) is the only writer; the reconciliation
query is asserted in tests; and the seed data is generated from a simulation that emits both
tables, so it cannot be seeded inconsistently.

## Alternatives considered

**Derive stock from the ledger on every read.** No duplication and no drift possible. Rejected:
every list page and dashboard tile would aggregate an ever-growing table, and DASH-01 requires
figures from aggregation queries on pages that must stay responsive.

**Unsigned quantity plus a direction column only.** Rejected: reconciliation becomes a `CASE`
expression inside `SUM()`. Marginal, but the invariant that is easiest to check is the one that
actually gets checked, and this one is checked often.

**Materialised view or trigger-maintained balance.** Rejected: it moves business logic into the
database, away from the service layer the project is graded on, and would make the stock update
invisible to the transaction the service believes it controls.
