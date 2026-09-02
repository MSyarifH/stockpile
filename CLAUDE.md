# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Inventory & Order Management System built to a fixed assessment brief (PT Neuronworks,
Intermediate Programmer Final Project). The brief's constraints are **binding** — a feature
that works but uses forbidden technology counts as not implemented, and several rules are
listed as outright critical failures. Treat the constraints below as non-negotiable.

The user is an experienced Flutter developer learning PHP/MySQL. They must be able to defend
every architectural decision unaided at a technical defense, so **explain the reasoning behind
non-obvious choices** rather than only producing working code.

**Over-engineering is penalised as heavily as messy code** (§0). Patterns applied without a
stated reason, or layers that solve no real problem, lose marks in the same band as spaghetti.
Three layers, manual constructor injection, no container. Do not add abstraction speculatively.

Out of scope, do not build: microservices, message queues, cloud deploy, CI/CD, Kubernetes,
real-time notifications, mobile apps, automatic cron scheduling, automated end-to-end tests.

## Hard constraints (violating any of these fails the project)

- **PHP 8.2+ native only.** No Laravel/CodeIgniter/Symfony/Slim, no ORM, no DI container
  library, no CRUD generator. Composer is allowed for autoloading and dev dependencies only.
- **Vanilla JS only.** No React/Vue/Angular/jQuery, no CSS framework, no admin template.
  Fetch API is allowed, as is a credited icon library. CSS must be written by hand.
- **MySQL 8 + PDO prepared statements.** Never concatenate user input into SQL.
- **Stock is never written directly.** Every change to `product_stocks` goes through the stock
  service, which writes a `stock_ledger` row and updates `product_stocks` in one transaction.
- **Authorization is enforced server-side**, never by hiding UI. In particular a `Sales` user
  may not approve any sales order, including their own (segregation of duties).
- Passwords via `password_hash()`/`password_verify()`; session ID regenerated after login.
- **No public registration page.** Every account is created by an Admin.
- Products/suppliers/customers are deactivated (`is_active = 0`), never hard-deleted.

## Fixed values (exact strings — the brief pins these)

| Thing | Allowed values |
|---|---|
| `users.role` | `Admin`, `Sales`, `WarehouseStaff` |
| `purchase_orders.status` | `Draft`, `Ordered`, `PartiallyReceived`, `Received`, `Cancelled` |
| `sales_orders.status` | `Draft`, `PendingApproval`, `Approved`, `Fulfilled`, `Cancelled` |
| `stock_ledger.movement_type` | `Receipt`, `Issue`, `Adjustment` |

Sales order flow is `Draft → PendingApproval → Approved → Fulfilled`, or `Cancelled` at any
stage before Fulfilled. Goods issue is only legal from `Approved`.

## Graded minimums (do not fall below)

- **≥6 unit tests across ≥3 logic areas**, touching no session/PDO/network. Trivial
  getter/setter tests do not count and skipped tests count as failures.
- **≥3 integration tests** against real MySQL in Docker.
- **Static analysis: zero critical errors.** Remaining warnings must be explained in writing,
  not silently ignored. (Brief requires PHPStan level 5+; this repo is set to 6.)
- **Pagination is exactly 10 rows per page**, and active filters must survive page changes.
- Seed: ≥30 products, ≥25 combined PO+SO, ≥2 warehouses, 1 Admin + ≥2 Sales + ≥2 Warehouse
  Staff, some products below reorder point, and examples of `PendingApproval` and `Cancelled`.
  (Current seed: 34 products, 30 orders, 3 warehouses, 6 users, 10 stock rows below reorder.)

## Commands

Everything runs in Docker; the host has no working PHP or Composer.

```bash
cp .env.example .env            # first run only
docker compose up -d --build    # app on :8080, MySQL on host :3307
docker compose down -v          # DESTROYS the db volume; next up re-runs schema+seed
```

`database/schema-and-seed.sql` is executed automatically by the MySQL entrypoint **only when
the data volume is created**. After changing schema or seed you must `down -v` then `up`, or
pipe the file in manually — a plain restart will not re-run it.

```bash
docker compose exec app composer install    # required once; vendor/ is git-ignored
docker compose exec app composer test              # unit + integration
docker compose exec app composer test:unit         # no database required
docker compose exec app composer test:integration  # requires the db service
docker compose exec app composer stan              # PHPStan level 6 (needs --memory-limit, in the script)
docker compose exec app composer sniff             # PHP_CodeSniffer, PSR-12

# single test file / single test
docker compose exec app vendor/bin/phpunit tests/Unit/SalesOrderServiceTest.php
docker compose exec app vendor/bin/phpunit --filter testRejectsIssueWhenStockInsufficient
```

MySQL shell (credentials come from `.env`):

```bash
docker compose exec db mysql -uioms -p"$DB_PASSWORD" ioms
```

## Database

`database/schema.sql` and `database/seed.sql` are the sources; **`database/schema-and-seed.sql`
is generated by concatenating them** and is the file Docker and the assessor use. Regenerate it
after editing either source:

```bash
python3 scripts/generate-seed.py   # rewrites database/seed.sql deterministically
{ cat database/schema.sql; echo; echo; cat database/seed.sql; } > database/schema-and-seed.sql
```

`scripts/generate-seed.py` simulates the warehouse (opening balances, then replays receipts and
issues) and emits `product_stocks` and `stock_ledger` from the *same* simulation. Do not
hand-edit `database/seed.sql` — the two tables will silently drift apart.

The core invariant, worth re-checking after any stock work:

```sql
SELECT COUNT(*) FROM product_stocks ps
LEFT JOIN (SELECT product_id, warehouse_id, SUM(quantity) s
             FROM stock_ledger GROUP BY product_id, warehouse_id) l
  ON l.product_id = ps.product_id AND l.warehouse_id = ps.warehouse_id
WHERE COALESCE(l.s, 0) <> ps.quantity;   -- must be 0
```

`stock_ledger.quantity` is **signed** (+n Receipt, −n Issue) precisely so this check is one
`SUM`. All tables are InnoDB: transactions, row-level locking and enforced foreign keys are all
required by the brief and MyISAM has none of them.

## Architecture

Three pragmatic layers, dependencies pointing one way only:

```
public/index.php  →  app/Controller  →  app/Service  →  app/Repository (interface)
                                                             ↑              ↑
                                                        MySql*Repository  InMemory*Repository
```

- **Controller** — HTTP only: read request, call a service, pick a view or JSON response.
  No SQL, no business rules.
- **Service** — business rules and transactions. Receives dependencies via constructor
  injection (wired by hand in `public/index.php`; there is no container). Must not touch
  `$_SESSION`, `$_POST` or PDO directly — that is what makes it unit-testable.
- **Repository** — data access behind an interface. At least one repository has both a MySQL
  implementation and an in-memory fake used by unit tests.
- **Entity** — plain data objects.
- **app/Support** — framework-less plumbing (router, request, session, view, validation). This
  folder is an addition to the brief's four-folder layout; it exists because routing and
  templating are genuinely none of the four responsibilities above.

Unit tests must run with **no database at all** (that is why the in-memory repositories exist);
integration tests hit real MySQL. `phpunit.xml` keeps them as separate suites.

### Concurrency (the highest-risk requirement)

Goods issue must not oversell under concurrent requests. MySQL defaults to REPEATABLE READ, so a
plain `SELECT` inside a transaction reads a stale snapshot and two concurrent issues can both
pass the stock check. The stock service therefore uses a locking read:

```sql
SELECT quantity FROM product_stocks
 WHERE product_id = ? AND warehouse_id = ? FOR UPDATE;
```

This relies on `UNIQUE (product_id, warehouse_id)` to lock exactly one index record. The
`CHECK (quantity >= 0)` constraint is a deliberate second line of defence: if the locking is
ever wrong, the write fails and rolls back rather than persisting negative stock.

## Documentation is graded

These exact artefacts are graded deliverables, not notes:

| Path | Contents |
|---|---|
| `docs/planning/` | user stories, scope, ERD, **initial** class diagram, backlog |
| `docs/architecture/` | **as-built** class diagram, `adr-*.md` (2–3 ADRs: context/decision/consequences) |
| `docs/quality/` | `refactor-log.md` (≥3 entries with before/after), SRP audit, `tech-debt.md`, `critique.md`, static analysis report |
| `docs/testing/` | test scenarios, results, screenshots, known bugs |
| `ai-usage-log.md` | tool, purpose, sanitised prompt summary, output used/rejected, verification |

The initial diagram must predate the code and the as-built one must match it at the end, with
2–3 sentences on what changed between them and why. Keep the refactoring log and AI usage log updated **as work
happens** — backfilled entries are obvious and concealed AI use is a critical failure. The
as-built class diagram must trace to real classes; an assessor will pick one and follow it.

Refactors that clean up existing code (rather than the feature in progress) need commits
prefixed `refactor:` — at least one is required.

## Critical failures (any one of these fails the project outright)

- App or database won't start via Docker after a reasonable setup procedure.
- Core login → PO/SO → stock flow broken, or core features are UI-only with no real data.
- A forbidden framework, ORM, or DI container is used.
- No valid unit **and** integration tests, or all tests failing at final release.
- Plaintext passwords, live secrets committed, raw string-concatenated SQL, or authorization
  enforced only in the frontend.
- Stock changed without going through the service/ledger, leaving `stock_ledger` and
  `product_stocks` inconsistent.
- Goods issue/receipt not transactional, so an assessor can reproduce oversell live.
- Class diagram doesn't match actual code and can't be traced at defense.
- Author can't explain their own architecture, or AI/external-source use is concealed.

Pass mark is 80 **and** zero critical failures.
