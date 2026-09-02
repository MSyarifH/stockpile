# Scope

## In scope — mandatory (§2, §3)

**Authentication & users** — AUTH-01 login/session, AUTH-02 logout, USR-01 user management.
**Master data** — PRD-01 products/categories/reorder point/image upload, WH-01 warehouses and
per-warehouse stock, plus supplier and customer management.
**Transactions** — PO-01 purchase orders with full and partial goods receipt; SO-01 sales orders
with submit, approve, cancel and goods issue.
**Read surface** — VIEW-01 lists/detail/empty states, FIND-01 search/filter/sort/pagination,
DASH-01 role-specific dashboards, REPORT-01 CSV export, API-01 JSON endpoint, JOB-01 low-stock
script.
**Quality** — VAL-01, ERR-01, UI-01, DB-01, ARCH-01 layering with a repository interface and two
implementations, ARCH-02 concurrency-safe stock.
**Evidence** — DESIGN-01 both class diagrams, DESIGN-02 ADRs, DESIGN-03 refactor log / SRP audit
/ tech-debt register, DESIGN-04 critique, TEST-01/02/03.

## Out of scope — by instruction (§4.3)

Microservices · message queues · cloud deployment · CI/CD · Kubernetes · real-time notification ·
mobile application · automatic cron scheduling · automated end-to-end tests.

## Out of scope — by choice

Recorded so the omissions read as decisions rather than oversights.

| Not built | Reason |
|---|---|
| Password reset / email verification | No mail requirement; accounts are Admin-created (§ USR-01) |
| Stock transfer between warehouses | Not in §1.1; the ledger's `Adjustment` type leaves room to add it |
| Multi-currency, tax, discounts | Not in §1.3's minimum fields |
| Soft-delete for orders | Orders are cancelled, never deleted — the status enum covers it |
| Role or permission editing | Roles are a fixed enum (§1.3) |
| Audit trail on master data | Listed as a §4.4 bonus; only after mandatory work is stable |

## Bonus — only if all mandatory work is stable (§4.4)

Simulated email via Mailhog · master-data audit trail · hand-built SVG dashboard charts ·
additional integration tests. Bonus cannot compensate for an unmet requirement.

## Deliberate limits

Per §0, depth of design is graded but unnecessary complexity is penalised. Therefore:
no DI container, no ORM, no event bus, no CQRS, no repository interface where no business rule
depends on it. Three layers, manual constructor injection, wired in one composition root.
