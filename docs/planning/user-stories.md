# User Stories

Derived from §1.1 (main flow) and §1.2 (segregation of duties). Each story names the
requirement it satisfies and the condition that proves it done.

## Admin

| # | Story | Req | Done when |
|---|---|---|---|
| A1 | Log in and land on an admin dashboard | AUTH-01 | Valid credentials reach the admin dashboard; invalid ones show a message that does not reveal which field was wrong |
| A2 | Create and deactivate Sales / Warehouse accounts | USR-01 | Email uniqueness enforced; deactivated user cannot log in; no public registration exists |
| A3 | Manage categories, products, warehouses, suppliers, customers | PRD-01, WH-01 | SKU unique; a product used on an order can be deactivated but not deleted |
| A4 | Review a submitted sales order and approve or reject it | SO-01 | Status moves `PendingApproval → Approved`; `approved_by` is recorded |
| A5 | Raise a purchase order when stock is low | PO-01 | PO created with supplier, destination warehouse and line items |
| A6 | See total inventory value, items below reorder point, pending orders | DASH-01 | Every figure comes from an aggregation query, none hardcoded |
| A7 | Export stock movements and order status as CSV for a date range | REPORT-01 | Two different date ranges produce correspondingly different files |

## Sales

| # | Story | Req | Done when |
|---|---|---|---|
| S1 | Browse the catalogue with current availability | WH-01, FIND-01 | Search by name/SKU, filter by category, 10 rows per page |
| S2 | Create a draft sales order for a customer | SO-01 | Order saved as `Draft` with the creator recorded |
| S3 | Submit my draft for approval | SO-01 | Status moves `Draft → PendingApproval` |
| S4 | See only my own orders and their status | §1.2, DASH-01 | Another Sales user's orders are not listed and are not reachable by direct URL |
| S5 | **Must not** be able to approve any order, including my own | §1.2, SO-01 | A direct POST to the approve endpoint returns 403, not merely a hidden button |

## Warehouse Staff

| # | Story | Req | Done when |
|---|---|---|---|
| W1 | See stock per warehouse and which products are low | WH-01, DASH-01 | Totals plus per-warehouse breakdown |
| W2 | Record goods receipt against a purchase order | PO-01 | Stock increases and a `Receipt` ledger row is written in one transaction; partial receipt supported |
| W3 | Issue goods for an approved sales order | SO-01 | Only legal from `Approved`; stock decreases and an `Issue` ledger row is written atomically |
| W4 | Be prevented from issuing more than is in stock | SO-01, ARCH-02 | Request rejected with a clear message; no partial write remains |
| W5 | Propose a purchase order when stock runs low | §1.2 | Can create a PO; cannot approve sales orders |

## Cross-role

| # | Story | Req | Done when |
|---|---|---|---|
| X1 | Log out and be unable to reopen protected pages | AUTH-02 | Session cleared; direct URL and back button both redirect to login |
| X2 | Query availability of a SKU as JSON | API-01 | `application/json` with 200 / 401 / 404 as appropriate, never an HTML error page |
| X3 | Every stock change is traceable to one ledger row | §1.3, DB-01 | `SUM(stock_ledger)` reconciles to `product_stocks` for every pair |

## Explicit non-stories (§4.3)

Not built, by instruction: microservices, message queues, cloud deployment, CI/CD, Kubernetes,
real-time notifications, mobile apps, automatic cron scheduling, automated end-to-end tests.
