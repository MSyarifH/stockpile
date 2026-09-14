# Inventory & Order Management System

Web application for managing products, multi-warehouse stock, purchases from suppliers and
sales to customers, with every stock movement traceable to a ledger entry.

Built for the PT Neuronworks Intermediate Programmer final project. PHP 8.2 native (no
framework), Vanilla JS, MySQL 8, Docker Compose.

> **Project status: in progress.** The environment, database and design documentation are
> complete. Application code begins at Phase 1. See
> [`docs/planning/backlog.md`](docs/planning/backlog.md) for exact progress against every
> requirement.

## Requirements

Docker and Docker Compose. Nothing else — there is no dependency on a locally installed PHP,
Composer or MySQL.

## Running it

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
```

### → Open **http://localhost:8080**

### Or use the helper script

`./run.sh` is an **optional** wrapper that runs exactly the commands above. Everything in this
README works without it; it exists because it waits for MySQL to finish its first-run import
before declaring success, creates `.env` and installs Composer dependencies if they are missing,
and fails with a sentence instead of a stack trace when Docker is not running or a port is taken.

```bash
./run.sh            # print every command with what it does, and start nothing
./run.sh start      # build, start, and wait until the app really answers
./run.sh stop       # stop the containers, keeping all data
./run.sh down       # remove the containers, keeping the database volume
./run.sh reset      # DESTROY the database volume and re-import schema + seed (asks first)
./run.sh status     # container state, plus an HTTP probe of the app
./run.sh check      # tests + PHPStan + PHP_CodeSniffer + the stock-ledger invariant
```

Run with no arguments it prints the menu and starts nothing: one of these commands deletes the
database volume, so the default is the harmless one.


Sign in with `admin@ioms.test` / `Password123!` (all demo accounts are listed below).

### Ports

| Service | Host port | Container port | Set by |
|---|---|---|---|
| Application (Apache + PHP 8.2) | **8080** | 80 | `APP_PORT` in `.env` |
| MySQL 8 | **3307** | 3306 | `DB_PORT_HOST` in `.env` |

MySQL is published on **3307**, not 3306, so it cannot collide with a MySQL already installed on
the host. Connect a client with:

```bash
mysql -h 127.0.0.1 -P 3307 -u ioms -p ioms
```

If either port is already taken, change it in `.env` and run `docker compose up -d` again —
nothing in the source refers to a port number:

```bash
APP_PORT=9090
DB_PORT_HOST=3399
```

Container names are intentionally not pinned, so a second copy of this stack can run alongside
the first with different ports.

### Verifying it is up

```bash
docker compose ps                                  # both services, db "healthy"
curl -I http://localhost:8080/login                # 200
curl http://localhost:8080/api/products/SKU-ELK-0001/availability   # 401 without a session
```

### Database

Schema and seed data load **automatically** the first time the MySQL volume is created, from
`database/schema-and-seed.sql`.

To reset to a clean seeded state:

```bash
docker compose down -v && docker compose up -d
```

> `down -v` deletes the volume. A plain `restart` will **not** re-run the schema — MySQL only
> executes the init script when the data directory is empty.

### Stopping

```bash
docker compose down       # stop, keep the data
docker compose down -v    # stop and delete the database
```

## Demo accounts

All accounts use the password `Password123!`.

| Email | Role | Purpose |
|---|---|---|
| `admin@ioms.test` | Admin | Full access; approves sales orders |
| `admin2@ioms.test` | Admin | Second Admin — an order's creator may never approve it, so an Admin-raised order needs a different Admin |
| `sales1@ioms.test` | Sales | Creates and submits sales orders |
| `sales2@ioms.test` | Sales | Second Sales user, to test order ownership |
| `warehouse1@ioms.test` | Warehouse Staff | Goods receipt and goods issue |
| `warehouse2@ioms.test` | Warehouse Staff | Second warehouse user |
| `inactive@ioms.test` | Sales (inactive) | Demonstrates that inactive users cannot log in |

These are demo credentials for assessment. Real values belong in `.env`, which is git-ignored.

## Tests

```bash
docker compose exec app composer test              # unit + integration
docker compose exec app composer test:unit         # no database needed
docker compose exec app composer test:integration  # requires the db service
```

A single file or a single test:

```bash
docker compose exec app vendor/bin/phpunit tests/Unit/StockServiceTest.php
docker compose exec app vendor/bin/phpunit --filter testRejectsIssueWhenStockInsufficient
```

Unit tests use in-memory repository fakes and touch no database at all; integration tests run
against the real MySQL container. They are separate PHPUnit suites.

## Static analysis

```bash
docker compose exec app composer stan    # PHPStan level 6
docker compose exec app composer sniff   # PHP_CodeSniffer, PSR-12
```

## Scheduled job

```bash
docker compose exec app php scripts/check-low-stock.php
```

Reports products below their reorder point. Runs outside the web request cycle.

## Architecture

Three layers, dependencies pointing one way only:

```
public/index.php → Controller → Service → Repository (interface) → MySQL
   (composition root)              ↳ in-memory fake, used by unit tests
```

- **Controller** — HTTP only. No SQL, no business rules.
- **Service** — business rules and transaction boundaries. Never touches `$_SESSION`, `$_POST`
  or PDO directly, which is what makes it testable without a database.
- **Repository** — data access behind an interface, where a business rule depends on it.
- **Entity** — plain data objects.

Objects are wired by hand in `public/index.php`. There is no DI container, by choice.

**Stock is written in exactly one place.** `StockService` is the only class permitted to modify
`product_stocks` or `stock_ledger`; purchase and sales services call it rather than writing
stock themselves. Both tables are updated inside a single transaction, so this always holds:

```sql
SUM(stock_ledger.quantity)  ==  product_stocks.quantity     -- per (product, warehouse)
```

Concurrent goods issues are made safe with a locking read (`SELECT … FOR UPDATE`) inside that
transaction. The reasoning, and the alternative that was rejected, are in
[`docs/architecture/adr-002-oversell-prevention.md`](docs/architecture/adr-002-oversell-prevention.md).

## Frontend

No framework, no build step, no `package.json`. Pages are rendered by PHP; JavaScript is added
only where the server cannot do the job on its own.

| File | Loaded by | Why it exists |
|---|---|---|
| `public/assets/form-validate.js` | every page with a `novalidate` form | The frontend half of VAL-01 |
| `public/assets/order-lines.js` | purchase and sales order forms | Order lines are added and removed without a page reload — a static form cannot vary its own row count |
| `public/assets/availability.js` | sales order form | Calls `GET /api/products/{sku}/availability` (API-01) and shows per-warehouse stock for the chosen product |

There are **no inline `<script>` blocks and no inline event handlers** anywhere in `views/`.

`form-validate.js` deliberately contains no rule table. It reads the constraint attributes
already in the markup (`required`, `type`, `min`, `max`, `step`, `minlength`, `maxlength`,
`accept`) — the same constraints `App\Support\Validator` enforces server-side — and reproduces
the server's message wording, so a user sees the same sentence whether the check happened in the
browser or after the POST. It can only cancel a submit: **every value is validated again on the
server**, which is the source of truth. Deleting all three files changes the user experience and
no security property.

Executed evidence, rather than an assertion that it works:
[`docs/testing/form-validate-harness.html`](docs/testing/form-validate-harness.html) runs ten
checks against the real script in a browser.

Icons are a self-hosted [Lucide](https://lucide.dev) subset, inlined as an SVG sprite by
`views/partial/icon-sprite.php` and generated by `scripts/build-icon-sprite.py`. No CDN: the
brief requires the app to run from a clean folder via Docker, and a CDN would leave an offline
assessor with no icons.

## Documentation

| Path | Contents |
|---|---|
| `docs/planning/` | ERD, initial class diagram, user stories, scope, backlog, decision log, requirement tensions |
| `docs/architecture/` | As-built class diagram, architecture decision records |
| `docs/quality/` | Refactoring log, SRP audit, tech-debt register, critique, analysis reports |
| `docs/testing/` | Test scenarios, results, known bugs |
| `ai-usage-log.md` | Declaration of AI assistance, per §6.2 of the brief |

## Known limitations

Tracked honestly rather than hidden. The full register, with the ideal fix for each entry, is
in [`docs/quality/tech-debt.md`](docs/quality/tech-debt.md); known defects are in
[`docs/testing/known-bugs.md`](docs/testing/known-bugs.md).

- Three requirement ambiguities are recorded as assumptions awaiting trainer confirmation
  (D1, D2, D3 in `docs/planning/decisions.md`).
- Dashboard figures are aggregated on every request, with no caching (TD-06).
- No automated test covers the HTTP layer end to end (TD-07); §4.3 puts end-to-end testing out
  of scope, so the client-side script is verified by the browser harness linked above instead.

## Attribution

All source code is written by the author. AI assistance was used and is declared in full in
[`ai-usage-log.md`](ai-usage-log.md).

One third-party asset is included, as §4 permits (*"library icon yang dicantumkan"*):

- **[Lucide](https://lucide.dev) v0.544.0** — icon set, **ISC License**, © Lucide Contributors;
  portions © Cole Bemis 2013–2023 as part of Feather (MIT). Used **unmodified**. Only the 24
  icons the application actually renders are vendored, extracted from the official
  `lucide-static` npm package by [`scripts/build-icon-sprite.py`](scripts/build-icon-sprite.py)
  into `views/partial/icon-sprite.php`. That file is generated — the licence text and source
  URL are reproduced in its header.

No other third-party code or assets are included.
