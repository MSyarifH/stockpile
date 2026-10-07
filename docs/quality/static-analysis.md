# Static Analysis Report

Deliverable for **TEST-03**: a static analysis report at PHPStan level 5 or above (or
PHP_CodeSniffer PSR-12), with zero critical errors, and any remaining output explained in
writing rather than ignored.

This project runs **both** tools. Both currently report **zero errors and zero warnings**.

Captured on 2026-09-04 from commit `07eb5e8`, inside the project's Docker `app` container.

---

## 1. Result summary

| Tool | Files analysed | Errors | Warnings | Exit code |
|---|---|---|---|---|
| PHPStan 1.12.34, level 6 | 98 | 0 | 0 | 0 |
| PHP_CodeSniffer 3.13.6, PSR-12 + 2 extra rules | 98 | 0 | 0 | 0 |

No actual code finding is reported by either tool. The only non-empty output beyond the
progress bars is PHPStan's upgrade notice, explained in section 5.

---

## 2. Tool versions and environment

```
PHP 8.2.33 (cli) (built: Aug 25 2026 00:40:46) (NTS)
Copyright (c) The PHP Group
Zend Engine v4.2.33, Copyright (c) Zend Technologies
    with Zend OPcache v8.2.33, Copyright (c), by Zend Technologies

PHPStan - PHP Static Analysis Tool 1.12.34

PHP_CodeSniffer version 3.13.6 (stable) by Squiz and PHPCSStandards
```

Both are dev dependencies only. `composer.json` declares:

```json
"require-dev": {
    "phpunit/phpunit": "^10.5",
    "phpstan/phpstan": "^1.11",
    "squizlabs/php_codesniffer": "^3.10"
}
```

The host machine has no working PHP, so every command below is executed through
`docker compose exec -T app`. That is a property of the development environment, not of the
tooling.

---

## 3. Configuration

### PHPStan — `phpstan.neon`

```neon
parameters:
    level: 6
    paths:
        - app
        - scripts
        - tests
    treatPhpDocTypesAsCertain: false
```

- **Level 6**, where the brief requires level 5 or above. One level stricter than required.
  Level 6 adds the requirement that every parameter, property and return type is declared —
  including array value types — so missing iterable types are errors here, not silence.
- **Paths**: `app` (the three architectural layers plus `app/Support`), `scripts` (the seed
  generator and the scheduled low-stock job) and `tests`. Analysing the tests matters:
  a fake repository whose signature has drifted from its interface is a real defect, and
  level 6 catches it in the test double before it is trusted as a stand-in for MySQL.
- **`public/` and `views/` are not in `paths`.** `public/index.php` is the hand-wiring
  front controller and `views/` contains PHP templates that rely on variables injected by
  the view renderer; PHPStan cannot know those variables' types without annotations that
  would exist only to satisfy the tool. This is a deliberate scope choice and is stated here
  rather than left to be discovered — everything containing business logic is analysed.
- **`treatPhpDocTypesAsCertain: false`** stops PHPStan from treating a PHPDoc type as proof.
  With the default `true`, a defensive `if ($x === null)` on a value documented as non-null
  is reported as an always-false condition; the check is then removed on the tool's word
  about a docblock a human wrote. Set to `false`, native types are still trusted and runtime
  guards on documented types survive.

### PHP_CodeSniffer — `phpcs.xml`

```xml
<?xml version="1.0"?>
<ruleset name="IOMS">
    <description>PSR-12 for application code.</description>
    <file>app</file>
    <file>tests</file>
    <file>scripts</file>
    <arg name="colors"/>
    <arg value="p"/>
    <rule ref="PSR12"/>
    <!-- strict_types is set per file and is decided by the CALLING file, so one
         omission silently reopens coercive typing. Enforced rather than remembered. -->
    <rule ref="Generic.PHP.RequireStrictTypes"/>
    <rule ref="Generic.Files.LineLength">
        <properties><property name="lineLimit" value="140"/><property name="absoluteLineLimit" value="0"/></properties>
    </rule>
</ruleset>
```

- **`PSR12`** is the base standard, as TEST-03 names.
- **`Generic.PHP.RequireStrictTypes`** is an addition. `declare(strict_types=1)` is per-file
  and applies to calls *made from* that file, so a single missing declaration silently
  restores coercive typing for everything that file calls — `"5"` passed where `int` is
  declared becomes `5` instead of a `TypeError`. Enforcing it mechanically is more reliable
  than remembering it.
- **`Generic.Files.LineLength`** at 140 with `absoluteLineLimit` 0. PSR-12 makes the 120
  limit a soft recommendation; 140 was chosen as a hard ceiling instead of an unbounded
  advisory, and `absoluteLineLimit` 0 disables the second, error-level threshold so a long
  SQL string is reported once rather than twice.
- Same three paths as PHPStan, for the same reasons.

---

## 4. Captured output

### PHPStan (`composer stan`)

```
> phpstan analyse --memory-limit=512M

⚠️  You're running an old version of PHPStan.️

The last release in the 1.12.x series with new features
and bugfixes was released on July 17th 2025,
that's 414 days ago.

Since then more than 65 new PHPStan versions were released
with hundreds of new features, bugfixes, and other
quality of life improvements.

To learn about what you're missing out on, check out
this blog with articles about the latest major releases:
https://phpstan.org/blog

Upgrade today to PHPStan 2.2 or newer by using
"phpstan/phpstan": "^2.2" in your composer.json.

Note: Using configuration file /var/www/html/phpstan.neon.
 98/98 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%


 [OK] No errors
```

Exit code 0.

### PHP_CodeSniffer (`vendor/bin/phpcs`)

```
............................................................ 60 / 98 (61%)
......................................                       98 / 98 (100%)


Time: 525ms; Memory: 10MB
```

Exit code 0. Every character in the progress display is `.`, which is PHP_CodeSniffer's
notation for a file with no errors and no warnings; an `E` or `W` would appear in place of a
dot. No summary table is printed because there is nothing to report. 98 files, zero
violations of PSR-12, zero missing `strict_types`, zero lines over 140 characters.

---

## 5. Warnings and notices, explained

TEST-03 requires remaining output to be explained rather than left to the reader. Both tools
report zero findings, so what follows explains the two pieces of non-finding output that a
reviewer will nonetheless see.

### 5.1 The PHPStan upgrade notice

**What it says.** That the installed 1.12.x series was last given new features on
17 July 2025, that more than 65 releases have followed, and that `^2.2` should be used
instead. It is printed before analysis begins and is not attached to any file or line.

**It is not a code finding.** PHPStan's own exit code is 0 and its verdict is `[OK] No
errors`. The notice is a marketing and maintenance message about the analyser, printed
unconditionally on old versions regardless of the code being analysed.

**Why the version is pinned.** `composer.json` requires `phpstan/phpstan: "^1.11"`, which
resolves within the 1.x major to 1.12.34. Two reasons for staying there:

1. The brief requires level 5 or above. PHPStan 1.12 at level 6 satisfies that with margin;
   upgrading buys no compliance.
2. A major-version bump mid-project changes what counts as an error. PHPStan 2.x tightens
   several rules and would likely surface findings in code already written and reviewed,
   mixing tool-driven churn into feature commits. Holding the analyser still while the code
   moved was the deliberate choice.

**It is recorded, not hidden.** This is entry **TD-08** in
[`docs/quality/tech-debt.md`](tech-debt.md) — "PHPStan is pinned to an outdated major
version" — which states the reason, records the functional risk as none, and gives the ideal
fix as upgrading to `^2.2` and addressing anything newly reported. The notice appearing in
the output above is the expected consequence of that recorded decision.

### 5.2 The `--memory-limit=512M` flag, and the output it prevents

The `stan` script is `phpstan analyse --memory-limit=512M`, not a bare `phpstan analyse`.
This matters to anyone reading a report, so it is documented rather than left as an
unexplained flag.

Run without it, PHPStan inherited the container's default 128M limit and its analysis
worker was killed part-way through. The output was:

```
Found 1 error
```

with no file, no line and no message — because the "error" was the worker's death, not a
finding in the code. That output is easy to misread as a genuine, unlocatable code failure,
and it is exactly the kind of thing a reviewer could reasonably take as a defect. It was
neither suppressed nor worked around in the code: the limit was raised so the analysis
completes, after which the true result is `[OK] No errors`.

The underlying cause is ordinary — PHPStan's inference over 98 files with level 6's full
type resolution needs more than 128M — and 512M is a comfortable ceiling rather than a
tuned figure.

---

## 6. Suppressions: none

A suppressed error is not a fixed error, so this was verified rather than asserted.

```bash
grep -rn "phpstan-ignore\|@phpstan" app/ tests/ public/ views/   # no output
ls phpstan-baseline.neon                                          # No such file or directory
```

Confirmed:

- **Zero** `phpstan-ignore` / `@phpstan-ignore` / `@phpstan-ignore-next-line` comments
  anywhere in `app/`, `tests/`, `public/` or `views/`.
- **Zero** `@phpstan-*` annotations of any kind.
- **No `phpstan-baseline.neon`** exists, and `phpstan.neon` includes no `baseline` or
  `ignoreErrors` section — its full contents are quoted in section 3.
- **No `phpcs:ignore` / `phpcs:disable` / `@codingStandardsIgnore`** in the analysed paths.

The zero-error result in section 4 is therefore an unsuppressed zero: every file in the
analysed paths was judged on its merits and passed. The only narrowing anywhere in the
configuration is the exclusion of `public/` and `views/` from `paths`, stated openly in
section 3 with its reason.

`phpcbf` (PHP_CodeSniffer's automatic fixer) has not been run against the tree for this
report; the code passes as written.

---

## 7. What these tools do and do not catch

Being precise about this matters more than the zero-error result, because a green static
analysis run invites the conclusion that the code is correct. It is not that kind of
evidence.

### What they verify

- **Types and nullability** (PHPStan, level 6): that every parameter, property and return
  type is declared including array value types; that a method exists on the class it is
  called on; that a nullable value is not dereferenced without a check; that a `match` or
  branch is reachable; that a repository implementation and its interface agree.
- **Style and consistency** (PHP_CodeSniffer): PSR-12 formatting, plus the two project rules
  — `declare(strict_types=1)` present in every file, and no line over 140 characters.

### What they do not verify

Neither tool executes code, so none of the project's actual risks are covered by them:

- **SQL correctness.** PHPStan sees a query as a string. That a `JOIN` is right, that a
  `WHERE` filters the intended rows, that a prepared statement's placeholders match its bound
  parameters, that an index exists — all invisible.
- **Authorization rules.** That a `Sales` user cannot approve a sales order, including their
  own, is a runtime branch on a role string. A static analyser cannot tell an enforced check
  from a missing one.
- **Transaction boundaries.** That a goods issue's ledger write and balance update are in one
  transaction, and that the locking read `SELECT ... FOR UPDATE` prevents oversell under
  concurrency, is a property of MySQL's behaviour at runtime, not of the source text.
- **HTTP behaviour.** Routing, status codes, CSRF token validation, redirects and the central
  failure handler.
- **Business rules generally.** That the sales order status flow forbids
  `Draft → Approved` is a conditional a type checker has no opinion about.

### What covers those instead

- **Unit tests** — 96 tests across 9 classes in `tests/Unit/`, using the in-memory
  repositories and touching no session, PDO or network — cover the business rules: the sales
  order status flow and the segregation-of-duties rule that a `Sales` user may not approve
  (`SalesOrderServiceTest`, 19 tests), the purchase order receipt flow
  (`PurchaseOrderServiceTest`, 14), stock movement and rejection on insufficient stock
  (`StockServiceTest`, 13), and pagination's fixed 10 rows with filters surviving page
  changes (`PaginationTest`, 14).
- **Integration tests** — `tests/Integration/StockMovementTest.php`, 6 tests against real
  MySQL in Docker — cover what only a database can answer: transactional goods issue and
  receipt, the `CHECK (quantity >= 0)` constraint, and the reconciliation invariant that
  `SUM(stock_ledger.quantity)` equals `product_stocks.quantity` for every pair.
- **The HTTP layer is not automatically tested.** Routing, CSRF and the failure handler are
  verified by hand with `curl`, with the checks recorded in `docs/testing/`. This gap is
  recorded as entry **TD-07** in [`docs/quality/tech-debt.md`](tech-debt.md), which states
  the risk as medium and describes the ideal fix; it is not repeated here.

The honest reading of section 1 is therefore: the code is type-clean and style-clean, and its
correctness is evidenced by the test suite, not by this report.

---

## 8. Reproducing this report

The host has no working PHP, so all commands run inside the container.

```bash
# Start the stack and install dev dependencies (once)
cp .env.example .env            # first run only
docker compose up -d --build
docker compose exec app composer install

# Tool versions
docker compose exec -T app php -v
docker compose exec -T app vendor/bin/phpstan --version
docker compose exec -T app vendor/bin/phpcs --version

# The two analyses
docker compose exec -T app composer stan      # phpstan analyse --memory-limit=512M
docker compose exec -T app vendor/bin/phpcs   # equivalently: composer sniff

# Verify there are no suppressions
docker compose exec -T app grep -rn "phpstan-ignore\|@phpstan" app/ tests/ public/ views/
ls phpstan-baseline.neon
```

Both analyses exit 0. `composer stan` without `--memory-limit=512M` may print `Found 1
error` with no file — that is the memory failure described in section 5.2, not a code
finding.

---
## 9. SonarQube

Added 2026-10-05 and completed 2026-10-07, because the assessment requires it in
addition to the two tools above. **Community Edition 26.9.0.129388** with the
default **Sonar way** Quality Gate — both pinned by the trainer.

```bash
./run.sh sonar     # server + coverage + analysis + gate, in one command
```

See `tools/sonarqube/README.md` for how it is wired and why the server is a
separate stack.

### 9.1 Final state

| Metric | Value |
|---|---|
| **Quality Gate** | **OK** — all three conditions pass |
| Coverage on New Code | 82.1% (required ≥ 80%) |
| Duplication on New Code | 1.2% (required ≤ 3%) |
| Violations on New Code | 0 (required 0) |
| Bugs | 0 |
| Vulnerabilities | 0 |
| Security Hotspots | 0 |
| Code Smells | 0 |
| Coverage (overall) | 56.9% |
| Duplicated lines | 1.7% |
| Lines of code | 6,886 |
| Reliability / Security / Maintainability | **A / A / A** |

Tests behind that coverage: **180** (162 unit, 18 integration), up from 138.

### 9.2 How it got there, including the part that looks bad

The first analysis reported **12 bugs, 1 vulnerability, 39 code smells and
Reliability D** — and the Quality Gate still said OK. It said OK because a
first analysis has no New Code baseline, so Sonar way's conditions had nothing
to measure. A green gate on an empty measurement is worth nothing, and it is
recorded here rather than quietly replaced with the table above.

The sequence is worth keeping because the middle of it is counter-intuitive:

| Stage | Gate | Issues | Coverage (new code) |
|---|---|---|---|
| First analysis | OK (nothing measured) | 52 | — (6 new lines) |
| Real defects fixed | **ERROR** | 12 | 55% |
| All findings cleared | **ERROR** | 0 | 62% |
| HTTP layer covered | **OK** | 0 | **82%** |

Fixing the code turned the gate **red**. That is not a regression: once 235
lines had changed, the coverage condition finally had something to measure, and
it measured a real gap. The gate only went green again when that gap was
actually closed.

### 9.3 What was fixed, and what was refused

Of 61 findings: **49 fixed**, **12 refused with the reason written into
SonarQube itself** (10 false positive, 2 accepted). The comments are readable in
the UI, so the justification travels with the finding rather than living only
in this file.

**Fixed — a sample of the ones that mattered:**

1. **`flex-basis` overridden by the `flex` shorthand** (`public/assets/app.css`,
   the only finding rated CRITICAL). `.nav` declared `flex-basis: 100%`, then
   `flex: 1 1 auto` four lines later; the shorthand resets the basis, so the
   first declaration was dead and the desktop media query was overriding
   something that never applied. The navbar looked correct by coincidence.
2. **Twelve order-line fields with an unassociated label.** The markup carried
   `<label class="visually-hidden">Quantity</label>` with no `for` and no `id` —
   a label that existed and did nothing, so a screen reader still announced an
   unnamed field. Replaced with `aria-label`, *not* `<label for>`, because
   `order-lines.js` clones these rows and a cloned `id` is a duplicate `id`.
3. **Session cookie without a `secure` flag** — now set via `Session::isHttps()`.
4. **`Validator::applyRules` had cognitive complexity 29**, because one method
   parsed the rule, judged the value *and* coerced its type. Split into
   `violation()` and `coerce()`; all 28 Validator tests passed unchanged, which
   is the evidence that behaviour did not move.
5. **Three dead parameters and three orphaned local variables.** One of those
   locals was a trap: `$actor = $this->session->requireUser()` looks like an
   unused assignment, but the *call* is the authorisation guard. Deleting the
   line would have let an anonymous request reach the form.

**Refused:**

| Location | Rule | Status | Reason |
|---|---|---|---|
| 8 controller actions | `php:S1172` | False positive | The Router calls every handler as `($handler)($request, ...$routeParameters)`. These eight take a path parameter, so `$request` is positional — removing it would hand the Request object to `$id`. The thirteen actions whose routes carry no path parameter **have** had it removed. |
| `app/Support/View.php` | `php:S2003` | False positive | `require_once` keys on the resolved path. `render()` is the single renderer for every template, so the same template rendered twice in one request would return an empty string **with no error**. |
| `public/index.php` | `php:S2003` | False positive | `config/config.php` **returns an array**. `require_once` returns `true` on a second include, so `$config` would silently become a boolean. |
| `app/Support/Session.php` | `php:S2092` | Accepted | The secure flag **is** set, conditionally. A cookie marked secure is never sent over plain HTTP, so hard-coding `true` would break the `http://localhost:8080` the README instructs an assessor to use. |
| `app/Support/Router.php` | `php:S1142` | Accepted | All five returns are guard clauses. Collapsing them under a three-return limit means nesting the conditions — the shape guard clauses exist to avoid. |

### 9.4 Coverage: what changed and what it means

Overall line coverage went from **30.4% to 56.9%**, and coverage on new code
from 55% to **82.1%**.

The number is not the point; the layer is. Until 2026-10-07 the HTTP layer had
**no automated test at all** — controllers were verified by clicking. That is
precisely how the stock filter shipped returning HTTP 500 while a test counted
its zero rows and passed (`docs/testing/known-bugs.md`).
`tests/Integration/ControllerRenderingTest.php` now renders every page through
the real `View` and the real templates, so a page that throws, or a template
that reads a variable nobody passes, fails in the suite rather than in front of
a user.

**Why it is an integration test rather than a unit test.**
`CategoryRepository`, `WarehouseRepository` and `BusinessPartnerRepository` are
concrete PDO classes on purpose: ADR-001 puts a repository behind an interface
only where a service *branches* on its data, and those three are plain CRUD.
Inventing interfaces for them purely so a unit test could reach the controllers
would be the speculative abstraction §0 penalises. The real repositories and the
real database are the honest way to cover this layer.

**What was NOT done.** `app/Controller/**` and `app/Repository/MySql*` were
never added to `sonar.coverage.exclusions`. Excluding them would have produced a
passing number in one line by deleting the evidence instead of the gap.

**What is still uncovered.** 43% of lines overall, chiefly the write paths of
the controllers and the MySQL repositories' less-travelled branches. The
remaining work is recorded as **TD-07** in `docs/quality/tech-debt.md`.
