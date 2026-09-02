# AI Usage Log

Declaration required by §6.2 of the project brief.

**Declaration:** AI assistance **was** used on this project. This log records every material
use. I remain fully responsible for the solution and can explain each architectural decision
without AI assistance at the technical defense.

The brief sets four obligations. How I apply them:

| Obligation | How it is honoured here |
|---|---|
| **DISCLOSE** | Every material AI interaction appears below, including output I rejected. |
| **REVIEW** | I read every generated line before keeping it. Anything I could not explain was rewritten or removed rather than kept because it worked. |
| **VERIFY** | Claims are checked against the brief and against MySQL/PHP documentation — not accepted because the model asserted them. |
| **TEST** | Behaviour is confirmed by running it (query output, test run, HTTP response), and the evidence is named in the entry. |

**Tool used:** Claude Code (Anthropic), Opus 5, via CLI on macOS.

**Data handling:** No client data, PII, credentials, or proprietary third-party source was sent
to the AI service. The project brief is training material issued to me for this assessment. The
only credential-shaped string in this repository is the bcrypt hash of the documented demo
password `Password123!`, which is required by §7 and is not an active secret. Real values live
in `.env`, which is git-ignored.

**A note on how this log is written:** entries are recorded as work happens, not reconstructed
afterwards. Where verification is still outstanding, the entry says so explicitly rather than
being written up as if complete.

---

## Session 1 — 2026-09-02 — Project setup, database foundation

### AI-01 · Repository structure
- **Purpose:** Scaffold folders satisfying §4.1.
- **Prompt (summary):** "Create the directory layout required by the brief's §4.1."
- **Output used:** Folder tree; `app/Controller|Service|Repository|Entity`, `views/`, `config/`,
  `database/`, `tests/Unit`, `tests/Integration`, `docs/{planning,architecture,quality,testing}`.
- **Output reviewed / amended:** The AI added `app/Support/`, which is *not* in the brief's list.
  I kept it deliberately: routing, session and templating are not any of the four named
  responsibilities, and §4.1 permits different folder names provided responsibilities stay
  separated. I am prepared to defend this as framework-less infrastructure rather than a fifth
  business layer. Recorded because it is a knowing deviation.
- **Verification:** Compared the resulting tree against §4.1 item by item; every required
  responsibility is present.

### AI-02 · Docker environment
- **Purpose:** §5.1 — app + MySQL must come up from clean with `docker compose up --build`.
- **Prompt (summary):** "PHP 8.2 Apache image and compose file with MySQL 8, env-driven config."
- **Output used:** `Dockerfile` (php:8.2-apache, `pdo_mysql`, document root pointed at
  `public/`, `display_errors=Off`), `compose.yaml`, `.env.example`.
- **Output rejected:** The AI initially placed the Dockerfile at `docker/php/Dockerfile`. I
  challenged this — we build exactly one image, so the multi-service convention adds structure
  that solves no problem here, which §0 penalises. Moved to repository root and simplified the
  compose entry to `build: .`.
- **Verification:** `docker compose up -d` from a destroyed volume; both services reach healthy
  state. Confirmed the document root excludes `app/` and `.env` from HTTP reach.

### AI-03 · Database schema
- **Purpose:** DB-01 — tables per §1.3 with keys, constraints and indexes.
- **Prompt (summary):** "Write the MySQL 8 schema for the entities in §1.3, with FKs,
  constraints and the indexes the brief's queries need."
- **Output used:** `database/schema.sql`, 12 InnoDB tables.
- **Output reviewed / amended:** I questioned two choices before keeping them, and can defend
  both: (a) `stock_ledger.quantity` is **signed** so that `SUM(quantity)` per product+warehouse
  reconstructs current stock in one query — making the core invariant cheap to assert;
  (b) `CHECK (quantity >= 0)` on `product_stocks` is deliberately redundant with the service
  layer, as a second line of defence specifically against a concurrency bug.
- **Verification:** Loaded into MySQL 8.0.46 from empty; queried `information_schema.TABLES` to
  confirm all 12 tables are InnoDB (transactions, row locks and enforced FKs are all required —
  MyISAM has none of them).
- **Still to verify:** that the chosen indexes are actually used by the dashboard and report
  queries. To be checked with `EXPLAIN` once those queries exist, and recorded in
  `docs/quality/`.

### AI-04 · Seed data generator
- **Purpose:** §7.1 demo data and FIND-01 pagination data.
- **Prompt (summary):** "Generate seed data meeting the §7.1 minimums."
- **Output rejected:** The first approach was hand-written `INSERT` statements for
  `product_stocks` and `stock_ledger`. I rejected it: two hand-maintained tables drift apart,
  and inconsistency between them is listed in §8.2 as a critical failure.
- **Output used:** A replacement generator (`scripts/generate-seed.py`) that simulates the
  warehouse — opening balances, then replaying every receipt and issue — and emits **both**
  tables from the same simulation, so they cannot disagree by construction. Deterministic
  (fixed RNG seed) so the file is reproducible.
- **Verification (evidence):** Ran against the live database:
  - reconciliation `SELECT COUNT(*) … WHERE COALESCE(SUM(ledger),0) <> product_stocks.quantity`
    → **0 rows**;
  - sales orders approved by their own creator → **0 rows** (seed does not contradict §1.2);
  - counts: 34 products (min 30), 30 orders (min 25), 3 warehouses, 6 users, 10 stock rows below
    reorder point; both order types include `PendingApproval` and `Cancelled`.
- **Note:** demo password hash generated by real `password_hash()` inside the container and
  confirmed with `password_verify()` returning `true`; not copied from any external source.

### AI-05 · Concurrency guidance (ARCH-02) — advisory only, not yet implemented
- **Purpose:** Understand how to prevent oversell before writing the stock service.
- **Prompt (summary):** "How does MySQL's default isolation level affect a read-then-write stock
  check, and what makes the check safe?"
- **Output used (as understanding, no code yet):** MySQL 8 defaults to `REPEATABLE READ`, under
  which a plain `SELECT` in a transaction reads a consistent snapshot; two concurrent goods
  issues can therefore both pass the same stock check and oversell. `SELECT … FOR UPDATE`
  performs a locking read instead, taking an exclusive lock on the matching index record so the
  second transaction blocks and then sees the first one's committed result.
- **Verification so far:** Confirmed the server's actual isolation level by querying
  `@@transaction_isolation` → `REPEATABLE-READ` (i.e. checked against the running database, not
  taken on assertion). Cross-checked the behaviour against the MySQL 8.0 reference manual
  sections on consistent non-locking reads and locking reads.
- **NOT YET VERIFIED — outstanding:** no stock service exists yet, so the mechanism is currently
  untested in this project. It will only count as verified once the TEST-02 integration test
  drives two concurrent connections and demonstrates the second issue being rejected. Until that
  test passes, this entry is understanding, not evidence.

### AI-06 · `CLAUDE.md` project instructions
- **Purpose:** Persist the brief's binding constraints so later AI sessions cannot silently
  violate them.
- **Output used:** `CLAUDE.md` — constraints, commands, architecture, invariant query.
- **Output reviewed / amended:** I audited the first draft against the brief and found it
  accurate but **incomplete**. I added: exact fixed enum strings (§1.3), graded minimums
  (test counts, 10-per-page pagination, seed thresholds), the §0 over-engineering penalty and
  §4.3 out-of-scope list, the named documentation artefacts, and the full §8.2 critical-failure
  list. Recorded because it shows AI output being checked against the source rather than
  trusted — the first draft would have left several fixed values undocumented.
- **Verification:** Section-by-section comparison against the brief PDF.

---

## Session 2 — 2026-09-02 — Planning artefacts, environment hardening

### AI-07 · Backlog decomposition
- **Purpose:** Turn the brief's requirement IDs into an ordered, verifiable plan.
- **Prompt (summary):** "Decompose every requirement into a checklist ordered by build
  dependency rather than document order."
- **Output used:** `docs/planning/backlog.md` — 194 items, phased, each Bukti tracked separately.
- **Output reviewed:** I checked the decomposition back against §2 and §3 to confirm no
  requirement ID was dropped. I moved the stock service ahead of the PO and SO phases myself,
  since both are callers of it — building either first would embed stock logic in an order
  service and require untangling later.
- **Verification:** Every requirement ID in §2 and §3 appears at least once in the file.

### AI-08 · Design artefacts (DESIGN-01)
- **Purpose:** ERD, initial class diagram, user stories, scope, decision log — before code.
- **Output used:** Five files under `docs/planning/`.
- **Output reviewed / amended:** The AI's first class diagram had `StockService` receiving a
  `PDO` directly. I rejected it: that violates ARCH-01 and would force unit tests to use a real
  database. Replaced with an injected `TransactionManager` interface, so the service declares
  atomicity without depending on PDO. I also had the AI rename `lockForUpdate()` to
  `readBalanceForUpdate()` — the abstraction should hide the mechanism, not the contract.
- **Verification (evidence):** All 5 Mermaid blocks parsed with the Mermaid parser under Node,
  so the diagrams provably render rather than merely looking plausible.

### AI-09 · Clean-clone environment test — **found a real defect**
- **Purpose:** §5.1 requires the project to run from a clean folder, not just my machine.
- **Method:** Cloned the repository to a separate directory, changed only the ports, and ran
  `docker compose up -d --build` as a reviewer would.
- **Result:** **Failed.** `container_name: ioms-db` / `ioms-app` were pinned in `compose.yaml`.
  Container names are global to the Docker daemon, so a second copy of the stack cannot start
  while another is running.
- **Fix:** Removed both `container_name` keys, with a comment recording why. Compose derives
  names from the project instead; every documented command addresses services, so nothing else
  changed.
- **Re-verified:** Clean clone boots, seed loads into the fresh volume (34 products, 30 orders,
  0 ledger mismatches), and both stacks now run simultaneously.
- **Note on method:** the first run reported exit code 0 while actually failing, because the
  build output was piped through `tail`. I stopped piping output when the exit status matters.
  Recorded because it nearly produced a false "verified" entry in this log.

### AI-10 · Apache hardening — **found a second defect**
- **Purpose:** Confirm nothing outside `public/` is reachable over HTTP.
- **Result:** Directory listing was enabled; `/uploads/` returned a browsable index. With product
  images stored under unguessable names (PRD-01), a listing would defeat that entirely.
- **Fix:** `Options -Indexes`, `ServerTokens Prod`, `ServerSignature Off`, and
  `php_admin_flag engine off` on `uploads/` so uploaded files can never be executed. Added
  `public/.htaccess` routing all non-file requests to the front controller.
- **AI output rejected:** The AI's first attempt put a `<Directory>` block inside `.htaccess`,
  which Apache does not permit there — every request returned 500. I read the Apache error log
  (`<Directory not allowed here`), and moved the block into the server config where it is legal,
  using `php_admin_flag` so `.htaccess` cannot override it. Recorded as an example of AI output
  that was confidently wrong and was caught by testing rather than by review.
- **Verification:** `/uploads/` → 403; `/` → front controller; `.env` and `composer.json` return
  the front controller's output, not file contents (confirmed by inspecting the response body,
  not just the status code); `Server:` header no longer advertises a version.

### AI-11 · Architecture Decision Records (DESIGN-02)
- **Purpose:** Record decisions while the reasoning was fresh, not reconstructed at the end.
- **Output used:** `adr-001-repository-interface.md`, `adr-002-oversell-prevention.md`,
  `adr-003-signed-ledger.md`.
- **Output reviewed:** Each ADR's "alternatives considered" section records options I actually
  weighed in discussion — notably the conditional atomic `UPDATE` for ARCH-02, which I rejected
  because a multi-line sales order must be validated in full before any line is written. The
  deadlock-avoidance decision (sorting line items by `product_id` before locking) was added
  after I asked what happens when two multi-line orders touch the same products in opposite
  order.
- **Verification:** ADR-002's claim about `REPEATABLE READ` was checked against the running
  server (`@@transaction_isolation`) and the MySQL 8.0 manual. Still to be proven by test —
  see the register below.

### AI-12 · Resolving three requirement ambiguities (D1–D3)
- **Purpose:** Decide, rather than block on, the three ambiguities that change behaviour.
- **My direction:** I chose to resolve these myself against business-process reasoning instead
  of waiting for a trainer answer, on the basis that the assessment tests whether I can defend a
  decision, not whether I can ask a question.
- **How each was decided:** D1 by mapping to the real procurement split between a requisition
  and a purchase order, placing the authority boundary at the point of financial commitment
  (`Draft → Ordered`). D2 by taking the control objective in §1 literally — no single *person*
  may create and approve the same transaction — which applies to Admins too. D3 by observing
  that SO-01's required evidence ("uji goods issue saat stok tidak cukup") is unreachable if
  approval reserves stock, so reservation is ruled out by the brief itself.
- **Correction I made to the AI's proposal:** the AI's D2 rule would have deadlocked the system.
  With a single seeded Admin, an order raised by that Admin could never be approved by anyone.
  I required a second Admin account (`admin2@ioms.test`) before accepting the rule; §7.1 states
  one Admin as a minimum, not a maximum. Recorded because the rule was correct but unusable as
  first proposed, and the gap was operational rather than logical.
- **Verification (evidence):** Seed regenerated and reloaded from an empty volume — 2 Admin
  accounts, 2 distinct approvers across seeded orders, **0** orders where
  `approved_by = created_by`, and the ledger/stock reconciliation still returns **0** mismatches.
- **Still to verify:** that the rule is enforced in code. Currently it holds only in seed data;
  it becomes real in Phase 5 and must be proven by a unit test asserting that an Admin cannot
  approve an order they created.

---

## Session 3 — 2026-09-02 — Master data (Phase 2)

### AI-13 · Suppliers and customers sharing one implementation
- **Purpose:** Avoid writing the same CRUD twice for two entities with identical fields.
- **My decision:** §1.3 lists Supplier and Customer on a single row with the same fields and the
  same rule (deactivate, never delete). I had the AI implement one controller, one service and
  one repository driven by a `PartnerType` enum, but keep **separate tables** — a purchase order
  must reference a supplier and a sales order a customer, and merging the tables would remove
  the foreign key that currently prevents mixing them.
- **Output reviewed:** The repository interpolates a table name into SQL, which normally I would
  reject outright. I accepted it only after confirming the name comes from a `match()` over an
  enum, so exactly two literal strings can ever appear and no request data reaches it. Every
  value is still bound. The reasoning is written in the class docblock so a reviewer does not
  have to reconstruct it.

### AI-14 · Upload directory permissions — **defect found by testing, not review**
- **Symptom:** A valid PNG was rejected with "The image could not be saved" while an invalid file
  was correctly rejected earlier — so the validation path looked fine and only the *success*
  path was broken.
- **Cause:** the `uploads` named volume is created as `root:root`, but Apache workers run as
  `www-data`, so `move_uploaded_file()` failed. Neither PHPStan, PHPCS nor any unit test could
  have caught this: it only exists in the running container.
- **Fix:** the Dockerfile now creates `public/uploads` owned by `www-data`. Docker initialises a
  named volume from the image, ownership included, so a clean clone gets it right.
- **Verified:** volume recreated from scratch; `www-data` can write; a real PNG uploads and is
  served; a text file renamed `.jpg` is still rejected.
- **Why it matters:** this would have failed live during the PRD-01 upload demo.

### AI-15 · PHPStan memory limit — **second defect found by running the tool**
- **Symptom:** `phpstan analyse` reported "Found 1 error" with no file or line.
- **Cause:** not a code problem — the analysis exceeded PHP's 128M limit and the worker crashed.
  Read carelessly, this looks like a static-analysis failure, which TEST-03 grades.
- **Fix:** `--memory-limit=512M` in the composer `stan` script, so the documented command works.
- **Verified:** 50 files analysed, zero errors.

### AI-16 · Unit tests for the catalogue (logic area 3)
- **Output reviewed / amended:** The AI's first version used `@phpstan-ignore-next-line` in three
  places to silence a shape mismatch on the test data helper. I rejected that: suppressing the
  analyser in tests hides exactly the kind of mistake it exists to catch. Replaced with a proper
  array-shape annotation; there are now zero ignore comments in the codebase.
- **Test I asked for specifically:** the low-stock boundary. The brief says "di bawah reorder
  point", which leaves stock *equal* to the reorder point ambiguous. Business reading: reaching
  the reorder point is precisely when you reorder, so the boundary is inclusive. Pinned in a
  test so the product list, dashboard and scheduled job cannot drift apart on the definition.
- **Verification:** 24 unit tests across 3 logic areas pass **with the database container
  stopped**, which is the evidence TEST-01 actually asks for.

---

## Session 4 — 2026-09-02 — Stock service and ARCH-02 (Phase 3)

### AI-17 · StockService as the single writer of stock
- **Purpose:** ARCH-02 — concurrency-safe goods receipt and goods issue.
- **Output used:** `StockService` with `receive/issue/receiveAll/issueAll`, `MovementCommand`,
  `MovementType`, `LedgerEntry`, `InsufficientStockException`, and `StockRepository` /
  `StockLedgerRepository` each with a MySQL and an in-memory implementation.
- **Design points I required beyond the AI's first sketch:**
  1. **Two-phase apply.** Lock and validate *every* line before writing *any* line. The first
     version validated and wrote line by line, which would half-ship a multi-line order.
  2. **Accumulate per stock row.** Two lines of the same product in one order must be summed
     before the check; comparing them individually lets 6 + 6 pass against a balance of 10.
  3. **Deterministic lock order** (sort by product, then warehouse), so two multi-line orders
     touching the same products in opposite order cannot deadlock.
- **Verification:** 13 unit tests with the in-memory fakes, no database.

### AI-18 · Proving ARCH-02 — and finding my own test was weak
- **What I did:** wrote two integration tests against real MySQL, then deliberately deleted the
  `FOR UPDATE` clause to check the tests would actually catch its absence.
- **Result of that check:** only ONE of the two tests failed. The oversell test was written
  sequentially — A commits, then B reads — so it passed even with the locking removed. It was
  asserting the right outcome for the wrong reason and would have given false confidence.
- **Correction:** rewrote it as a genuine interleaving (A locks → B reads a stale snapshot → B is
  blocked attempting its own locking read → A commits → B retries and is refused on the committed
  value). Re-ran the mutation check: **both** tests now fail without `FOR UPDATE`.
- **Why this is recorded:** the test suite was green before and after the first version. Green
  tests are not evidence that the mechanism works; deliberately breaking the mechanism and
  watching the tests go red is.

### AI-19 · Fabricated history in a code comment — caught in review
- **What happened:** the AI's docblock on `MovementCommand` claimed "the previous draft passed
  four loose ints and two in the wrong order silently moved the wrong stock", citing the
  refactoring log. **No such draft ever existed** — the class was written as a DTO from the start.
- **Action:** rewritten to state the actual reasoning (four same-typed parameters in a row are
  swappable without any tool noticing) with no invented history, and the false cross-reference to
  `refactor-log.md` removed.
- **Why this matters:** DESIGN-03 is graded on a *real* refactoring history. A fabricated
  before/after would be worse than having none, and §8.2 treats misrepresented evidence as a
  critical failure. Recorded as a reminder to check narrative claims in generated comments, not
  only the logic.

---

## Outstanding verification register

Items where AI output is accepted as understanding but **not yet proven in this project**.
Each must move to verified, or be removed, before final release.

**Cleared 2026-09-02:** AI-05 / AI-11 — `SELECT … FOR UPDATE` prevents oversell. Now proven by
`Tests\Integration\StockMovementTest::testConcurrentIssuesCannotOversell` and
`::testASecondTransactionCannotReadTheSameStockRowWhileItIsLocked`, both of which were confirmed
to fail when the `FOR UPDATE` clause is removed.

| Ref | Claim awaiting proof | Proof required |
|---|---|---|
| AI-03 | Chosen indexes serve the real queries | `EXPLAIN` on dashboard/report queries, output filed in `docs/quality/` |
| AI-12 | `approved_by != created_by` enforced for every role | Unit test in Phase 5: Admin approving their own order is rejected |

---

## Work done without AI assistance

Recorded for completeness, since the brief asks for an honest picture rather than only a list
of AI contributions.

- Decision to relocate the Dockerfile to the repository root (AI-02) — my correction, against
  the AI's initial structure.
- Decision to keep `app/Support/` as a deliberate, defensible deviation from §4.1 (AI-01).
- Rejection of hand-written stock seed data on critical-failure grounds (AI-04).
- Build sequencing for the project (vertical slice order, risk-first on ARCH-02) — reviewed and
  adopted after discussion; recorded in `docs/planning/backlog.md`.
- Rejection of a `PDO`-dependent `StockService` in favour of an injected transaction boundary
  (AI-08), and the decision to keep interfaces only where a business rule depends on them.
- Decision to run a clean-clone environment test at all (AI-09), which is what exposed the
  `container_name` defect.
- The eight ambiguity readings in `docs/planning/decisions.md`; D1, D2 and D3 are flagged to
  confirm with the trainer rather than assumed silently.

---

*This log is updated as work happens. Entries are appended, not rewritten; corrections are
recorded as corrections.*
