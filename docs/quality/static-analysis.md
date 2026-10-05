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

Added 2026-10-05, at commit `fef1b8f`+, because the assessment requires it in addition to the
two tools above. **Community Edition 26.9.0.129388** with the default **Sonar way** Quality
Gate — both pinned by the trainer.

```bash
./run.sh sonar     # server + coverage + analysis + gate, in one command
```

See `tools/sonarqube/README.md` for how it is wired and why the server is a separate stack.

### 9.1 Final state

| Metric | Value |
|---|---|
| Quality Gate | **OK** |
| Bugs | 0 |
| Vulnerabilities | 0 |
| Security Hotspots | 0 |
| Code Smells | 39 |
| Coverage | 30.8% |
| Duplicated lines | 1.7% |
| Lines of code | 6,869 |
| Reliability / Security / Maintainability | **A / A / A** |

### 9.2 The first run was not this

The first analysis reported **12 bugs, 1 vulnerability, Reliability D** — and the Quality Gate
still said OK, because a first analysis has no New Code baseline for Sonar way's conditions to
measure. A green gate on an empty measurement is worth nothing, and is recorded here rather
than quietly replaced with the final table.

Three findings were real and are fixed:

1. **`public/assets/app.css` — `flex-basis` overridden by the `flex` shorthand** (the one
   finding rated CRITICAL). `.nav` declared `flex-basis: 100%`, then `flex: 1 1 auto` four
   lines later; the shorthand resets the basis, so the first declaration was dead and the
   desktop media query was overriding something that never applied. The navbar looked correct
   by coincidence. Merged into a single `flex: 1 1 100%`.
2. **Six order-line inputs with an unassociated label.** The markup carried
   `<label class="visually-hidden">Quantity</label>` with no `for` and no `id` — a label that
   existed and did nothing, so a screen reader still announced an unnamed field. Replaced with
   `aria-label`, *not* `<label for>`: `order-lines.js` clones these rows and a cloned `id` is a
   duplicate `id`, which breaks the association it was meant to create. Server-rendered rows
   (`views/purchase/show.php`) keep `<label for>`, which is correct there.
3. **Session cookie without a `secure` flag** — now set via `Session::isHttps()`.

### 9.3 Three findings refused, and why

Each is marked in SonarQube itself with the reasoning, so the justification travels with the
finding rather than living only here.

| Location | Rule | Status | Reason |
|---|---|---|---|
| `app/Support/View.php:68` | `php:S2003` | False positive | `require_once` keys on the resolved path. `View::render()` is the single renderer for every template, so the same template rendered twice in one request would return an empty string **with no error**. A latent trap rather than a live bug today — stated as such in the comment. |
| `public/index.php:69` | `php:S2003` | False positive | `config/config.php` **returns an array**. `require_once` returns `true` on a second include, so `$config` would silently become a boolean. `require` is the correct construct for a value-returning include. |
| `app/Support/Session.php:27` | `php:S2092` | Accepted | The flag **is** set, conditionally. A cookie marked secure is never sent over plain HTTP, so hard-coding `true` would break the `http://localhost:8080` the README instructs an assessor to use — they would log in and immediately appear logged out. |

### 9.4 Coverage, stated plainly

**30.8%**, and it is the weakest number in this report.

It follows directly from the architecture rather than from neglect: unit tests exercise
`Service` classes through `InMemory*Repository`, which is exactly what lets the unit suite run
with no database at all (§4.1). The cost is that `Controller` and `MySql*Repository` are
executed only by the six integration tests.

Adding `app/Controller/**` and `app/Repository/MySql*` to `sonar.coverage.exclusions` would
raise the figure well above 80% in one line. It was not done. Excluding the untested code
removes the evidence, not the gap — and an assessor reading `sonar-project.properties` would be
entitled to read it as hiding the problem.

The real remedy is tests that enter through the HTTP layer, which is already recorded as
**TD-07** in `docs/quality/tech-debt.md` and remains outstanding.
