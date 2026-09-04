# Known Bugs and Limitations

Everything observed during the test run recorded in [`test-results.md`](test-results.md) that
failed, behaved oddly, or is a limitation an assessor should know about. Written while testing,
not reconstructed afterwards, and nothing was fixed in the code in order to shorten this list.

This file covers **defects and observed oddities**. Deliberate design shortcuts — decisions taken
with a reason, rather than mistakes — live in
[`../quality/tech-debt.md`](../quality/tech-debt.md) and are not repeated here; where the two
touch the same area the entry links across instead of restating it.

**Summary**

| ID | Severity | Requirement | One line | Status |
|---|---|---|---|---|
| BUG-01 | Medium | PRD-01, PO-01, SO-01, VAL-01 | An unknown foreign-key id returns 500 instead of 422 | **FIXED** |
| BUG-02 | Medium | PO-01 | An illegal purchase-order transition posted directly returns 500 instead of a 4xx with a message | **FIXED** |
| BUG-03 | Low | DASH-01 | The inventory tiles include deactivated products; the low-stock figures exclude them | **RESOLVED as intended behaviour, labels corrected** (decision D10) |
| BUG-04 | Medium | REPORT-01 | The order-status CSV's `Total` column is `0.00` on every row | **FIXED** |
| BUG-05 | Low | PRD-01 | An upload larger than PHP's `post_max_size` surfaces as a CSRF 403, not a size error | **FIXED** |
| BUG-06 | **High** | UI-01 | At 360px the navigation overflowed to 932px and the page scrolled sideways | **FIXED** |
| BUG-07 | **High** | VAL-01 | A mistyped rule name was silently ignored, so validation switched itself off | **FIXED** |

## How these were fixed

Recorded here rather than in the entries below, so the original observations stay as they were
written during testing.

**BUG-01 and BUG-02 shared one root cause.** `ValidationException` was not mapped in the central
failure handler in `public/index.php`, so any validation failure a controller did not catch in
order to re-render its form became a 500. Added two arms to that `match`: `ValidationException`
→ 422 with its messages, and a `PDOException` with SQLSTATE `23000` → 422 with a generic
message. The driver's own text is deliberately **not** passed through, because it names tables
and constraints. Verified: an unknown `category_id` now returns 422 reading "One of the selected
records does not exist…", and placing an already-received purchase order returns 422 reading
"A Received purchase order cannot become Ordered." Neither response contains a table name.

**BUG-04** was a genuine data error in graded evidence. `ReportService::orderStatus()` used the
list query, which does not load line items, so `SalesOrder::total()` summed an empty array and
returned `0.00` — a figure that looks real. The list query now computes the total with a
correlated subquery, and `total()` prefers loaded items and falls back to it. Verified against
SQL: all 20 rows now match, 0 rows read `0.00`.

**BUG-05.** PHP empties `$_POST` and `$_FILES` when `post_max_size` is exceeded, and raises no
error, so the first thing noticed was the missing CSRF token. `Request::bodyWasDiscarded()` now
detects a POST with a non-zero `Content-Length` but an empty body, and the front controller
checks it **before** anything looks for a token. Verified with a 10 MB upload against an 8 MB
limit: 413 reading "That upload was larger than the server accepts. The limit is 8M per
request…" A valid PNG still uploads.

**BUG-06** was found by capturing screenshots at a true 360px viewport. `.nav` was
`display: flex` with no `flex-wrap`, so the ten links an Admin sees formed one 932px row and the
document scrolled sideways by 588px — UI-01 requires that neither the navigation nor the tables
be clipped. Fixed with `flex-wrap: wrap` and `min-width: 0` (a flex item defaults to
`min-width: auto` and refuses to shrink below its content). Re-measured: nav 313px, document
scrollWidth 345 in a 360px viewport, and `scrollWidth == viewport` on all seven pages at both
sizes. All screenshots were re-captured. Full before/after in
[`screenshots.md`](screenshots.md).

**BUG-07** was found by the critique exercise. `Validator::applyRules()` had a `switch` with no
`default`, so an unrecognised rule name fell through and the value was accepted unconditionally:
`'required|emial'` validated anything at all, and `'required|integer'` accepted `'abc'`. Neither
PHPStan (rules are runtime strings) nor any test caught it, because the class had **no tests**.
Fixed by throwing `LogicException` on an unknown rule — rules are written by developers, never
supplied by a request, so failing loudly is safe. Two related changes came with it: `min`/`max`
were renamed to `min_value` / `max_value` / `max_length`, because they read as a pair but were
not one (`min` compared the numeric value, `max` compared string length, so `'int|max:100'`
would have limited the number of digits and accepted `999999`); and `tests/Unit/ValidatorTest.php`
was written, 19 cases including a regression test for the unknown rule.

---

## BUG-01 · An unknown foreign-key id returns 500 instead of 422

**Severity:** medium. Wrong status code and an unhelpful error page; no data problem.

**Requirements affected:** PRD-01, PO-01, SO-01, VAL-01.
**Scenarios that failed:** TS-PRD-01-10, TS-PO-01-15, TS-VAL-01-10.

**Observed.** Posting a well-formed create request whose foreign key points at a row that does not
exist produces a 500 error page:

| Request | Field | Expected | Actual |
|---|---|---|---|
| `POST /products` | `category_id=9999` | 422 with a field error | **500** |
| `POST /purchase-orders` | `supplier_id=99999` | 422 | **500** |
| `POST /sales-orders` | `customer_id=99999` | 422 | **500** |
| `POST /sales-orders` | `items[product_id][]=99999` | 422 | **500** |
| `POST /sales-orders` | `warehouse_id=99999` | 422 | **500** |

Every other field on the same forms is validated correctly — blank name, non-numeric price,
negative price, negative reorder point, missing category, negative quantity, zero quantity,
negative line price, a future date and an unparseable date all return 422 with a specific message.
It is only *existence* of the referenced row that is not checked.

**Cause.** The services validate shape but not existence, so the insert reaches MySQL and the
foreign-key constraint rejects it. The container log shows:

```
[PDOException] SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a
child row: a foreign key constraint fails (`ioms`.`products`, CONSTRAINT `fk_products_category`
FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) …)
in /var/www/html/app/Repository/MySqlProductRepository.php:165
```

`PDOException` is not an `HttpException` or an `AuthorizationException`, so the central failure
handler in `public/index.php` falls through to its `default` arm and returns 500.

**What is *not* wrong.** This was checked deliberately, because a 500 during a write is exactly
where partial data tends to appear:

- Nothing is written. `sales_orders` stayed at 20 rows and `purchase_orders` at 16 across all
  five attempts, and there are **0** orphaned line items in either child table — the multi-line
  insert is inside a transaction, so the failed insert rolled the whole thing back.
- Nothing leaks. The 500 page contains only "Something went wrong. Please try again."; grepping
  the body for `stack trace`, `#0 ` and `/var/www/html/app` returns no matches. The exception text
  above appears in the container log only, which is what ERR-01 requires.
- Referential integrity holds regardless, because the database is enforcing it.

So the defect is the response, not the data. A user who somehow submits a stale id sees a generic
failure page instead of "That category no longer exists".

**Ideal fix.** Two options, in order of preference:

1. An existence check in the service — `Validator`'s `exists` rule already exists for this — so a
   bad reference is a validation error like any other. This is the correct fix: it puts the rule in
   the layer that owns business rules, and it produces a message naming the field.
2. Catch `PDOException` with SQLSTATE `23000` in the repository and rethrow it as a
   `ValidationException`. Cheaper, but it turns a database detail into a user-facing rule and it
   cannot say *which* of several foreign keys failed without parsing the driver message.

Option 1 leaves the constraint in place as a second line of defence, exactly as
`CHECK (quantity >= 0)` backs up the locking read.

**Why it is still here.** Found during this test run, after the feature phases were closed. It is
recorded rather than patched so the test evidence and the code agree at submission time.

---

## BUG-02 · Illegal purchase-order transitions return 500

**Severity:** medium. Wrong status code and no explanation to the user; no data problem.

**Requirement affected:** PO-01. **Scenarios that failed:** TS-PO-01-10, TS-PO-01-11,
TS-PO-01-12.

**Observed.** Posting a state change that the status machine forbids:

| Request | Order state | Expected | Actual |
|---|---|---|---|
| `POST /purchase-orders/16/cancel` | `Received` | 4xx + "A Received purchase order cannot become Cancelled." | **500** |
| `POST /purchase-orders/16/place` | `Received` | 4xx + message | **500** |
| `POST /purchase-orders/14/cancel` | `Cancelled` | 4xx + message | **500** |

The equivalent sales-order requests behave correctly, which is what makes this a bug rather than a
design choice. All five of these return 302 with a readable flash message and leave the status
untouched:

```
POST /sales-orders/1/cancel   (Fulfilled) → "A Fulfilled sales order cannot become Cancelled."
POST /sales-orders/1/issue    (Fulfilled) → "Goods can only be issued for an approved order. This order is Fulfilled."
POST /sales-orders/1/approve  (Fulfilled) → "A Fulfilled sales order cannot become Approved."
POST /sales-orders/13/issue   (Draft)     → "Goods can only be issued for an approved order. This order is Draft."
POST /sales-orders/13/approve (Draft)     → "A Draft sales order cannot become Approved."
```

Goods **receipt** on a purchase order is also handled correctly — receiving against a `Draft` or a
`Received` order returns 302 with "Goods can only be received against an order that has been
placed. This order is Received." So the gap is narrow: it is `place()` and `cancel()` only.

**Cause.** `PurchaseOrderService::assertTransition()` throws a `ValidationException`
(`app/Service/PurchaseOrderService.php:230`). `PurchaseOrderController::receive()` catches it and
flashes the message; `place()` and `cancel()` do not:

```php
public function cancel(Request $request, string $id): Response
{
    $actor = $this->session->requireUser();
    $this->csrf->assertValid($request);

    $this->orders->cancel($actor, (int) $id);          // ← ValidationException escapes
    $this->session->flash('success', 'Purchase order cancelled.');

    return Response::redirect('/purchase-orders/' . (int) $id);
}
```

The uncaught `ValidationException` reaches the central handler, which does not recognise it and
returns 500. Confirmed in the log:

```
[App\Support\Exception\ValidationException] Validation failed.
in /var/www/html/app/Service/PurchaseOrderService.php:230
```

**What is *not* wrong.** The transition itself is refused: PO 16 remained `Received` and PO 14
remained `Cancelled`. The rule lives in the service, so it holds against a direct POST; only the
presentation of the refusal is broken. Nothing leaks in the 500 body.

**Ideal fix.** Either wrap the two calls in the same `try/catch (ValidationException)` block that
`receive()` uses, or — better, since three controllers repeat that block — teach the central
failure handler in `public/index.php` to map `ValidationException` to 422 the way it already maps
`AuthorizationException` to 403. The second is a one-line change in one place and would have
prevented BUG-01 from producing a bare 500 as well.

**Related.** This is exactly the gap TD-07 predicts: nothing in `composer test` exercises the HTTP
layer, so a controller that forgets an exception handler is not caught by the suite. BUG-01 and
BUG-02 are two instances of the same blind spot, and both were found by hand with `curl`. See
[`../quality/tech-debt.md`](../quality/tech-debt.md) TD-07.

---

## BUG-03 · Inventory tiles count deactivated products, low-stock figures do not

**Severity:** low. An internal inconsistency in reporting, not a wrong sum.

**Requirement affected:** DASH-01. No scenario asserted this, so it is an observation rather than
a recorded failure; TS-DASH-01-4 passed because every tile matches its own query.

**Observed.** The Admin dashboard shows "Inventory value (at cost) Rp 681.233.000" and "Units in
stock 5.077". Both figures include products with `is_active = 0`. Restricting the same queries to
active products gives Rp 670,455,000 and 4,888 — two seeded inactive products hold 189 units worth
Rp 10,778,000 between them. Meanwhile "Below reorder point", the "Needs reordering" table,
`/products?stock=low` and `scripts/check-low-stock.php` all exclude inactive products.

**Cause.** `DashboardRepository::totalInventoryValue()` and `totalUnitsInStock()` do not filter on
`p.is_active`:

```php
'SELECT COALESCE(SUM(ps.quantity * p.purchase_price), 0)
   FROM product_stocks ps
   JOIN products p ON p.id = ps.product_id'
```

whereas the low-stock query does.

**Is it a bug?** Arguably not, and this is worth being able to argue either way at a defence. A
deactivated product still physically occupies the warehouse and still represents money tied up in
stock, so counting it in inventory value is defensible; it is *not* worth reordering, so excluding
it from the low-stock list is also defensible. What is not defensible is that the two definitions
are unstated, so a reader cannot tell which population a given tile describes.

**Ideal fix.** Decide the definition once, name it in the code, and apply it consistently — for
example a documented `activeStockScope()` used by both, or a label on the tile reading "including
discontinued lines". Nothing here needs a schema change.

---

## BUG-04 · The order-status CSV reports every total as 0.00

**Severity:** medium. Genuinely wrong data in a graded deliverable.

**Requirement affected:** REPORT-01. **Scenario that failed:** TS-REPORT-01-5.

**Observed.** `GET /reports/order-status?from=2026-01-01&to=2026-12-31` as Admin returns 200 with
correct headers and 20 rows, and the final `Total` column is `0.00` on **every** row:

```
"Order number",Date,Customer,Warehouse,Status,"Raised by","Approved by",Total
SO-2026-0020,2026-09-04,"CV Berkah Abadi","Gudang Bandung",Draft,"Sinta Sales",,0.00
SO-2026-0019,2026-09-04,"CV Berkah Abadi","Gudang Bandung",Cancelled,"Sinta Sales","Rizky Admin",0.00
SO-2026-0018,2026-09-04,"CV Berkah Abadi","Gudang Bandung",Approved,"Rizky Admin","Putri Admin",0.00
SO-2026-0017,2026-09-04,"CV Berkah Abadi","Gudang Bandung",Fulfilled,"Sinta Sales","Rizky Admin",0.00
```

`SELECT SUM(quantity * selling_price)` for those four orders returns 500,000, 24,999,750,000,
750,000 and 1,250,000 respectively. The distinct set of values in the CSV's last column is exactly
`{0.00}`.

Every other column in the export is correct, and the ownership restriction works (Admin gets 20
rows, `sales1` gets 10, matching `COUNT(*) WHERE created_by = 2`).

**Cause.** `ReportService::orderStatus()` writes `number_format($order->total(), 2, '.', '')`, and
`SalesOrder::total()` sums `$this->items`:

```php
public function total(): float
{
    return array_sum(array_map(
        static fn (SalesOrderItem $item): float => $item->lineTotal(),
        $this->items,
    ));
}
```

But the report is fed by `MySqlSalesOrderRepository::between()`, which selects only the order
header and calls `SalesOrder::fromRow($row)` without hydrating line items. `$items` is therefore
empty and `array_sum([])` is `0`. The sales-order **detail** page shows the same order's total
correctly (Rp 1.250.000) because `findById()` does load the items — which is what localises the
fault to `between()` rather than to the entity or the CSV writer.

**Why the unit tests did not catch it.** `ReportServiceTest` uses `InMemorySalesOrderRepository`,
whose `between()` returns whatever orders the test constructed — items included. The fake is more
generous than the real repository, so the test passes and production does not. That is the classic
in-memory-fake failure mode and it is the honest lesson from this run: a fake must be as mean as
the thing it replaces.

**Ideal fix.** Two parts, and both are worth doing:

1. Make the export's data source complete. Either hydrate items in `between()` with a second
   query keyed on the returned order ids, or — better for a report — have the repository compute
   the total in SQL (`SUM(i.quantity * i.selling_price)` joined and grouped) and expose it as a
   dedicated report row type rather than a partially-populated entity. Returning an entity that
   silently lies about its own total is the underlying design fault.
2. Tighten the fake so `InMemorySalesOrderRepository::between()` returns headers only, matching
   the MySQL implementation, and add a unit test asserting a non-zero total in the export. Without
   this, the same class of bug returns.

**Not fixed here** because this file records what the code does at submission, and changing
`between()` without also correcting the fake would leave a passing test that still proves nothing.

---

## BUG-05 · An oversized upload surfaces as a CSRF failure

**Severity:** low. A confusing message in an edge case.

**Requirement affected:** PRD-01. No scenario asserted this; TS-PRD-01-12 tests the application's
own 2 MB limit, which works correctly.

**Observed.** `POST /products` with a 12 MB file returns **403** with no field-level message.
Uploading 3 MB — over the application's 2 MB limit but under PHP's 8 MB `post_max_size` — correctly
returns **422** with "The image must be 2048 KB or smaller."

**Cause.** PHP discards the entire request body when `post_max_size` (8 MB) is exceeded, so
`$_POST` is empty, so the `_token` field is missing, so `Csrf::assertValid()` rejects the request
before any upload validation runs. The relevant limits are `post_max_size=8M`,
`upload_max_filesize=4M` and the application's own `max_bytes = 2 * 1024 * 1024` in
`config/config.php`.

**Ideal fix.** Detect the condition explicitly — an empty `$_POST` on a `POST` with a non-zero
`CONTENT_LENGTH` means the body was discarded — and return 413 with "That file is too large to
upload" before the CSRF check. Alternatively set `post_max_size` closer to the application limit so
the two agree, though that only narrows the window rather than closing it.

**Not a security concern.** Rejecting the request is the safe outcome; only the explanation is
wrong. Nothing was written and no file was stored.

---

## Test suite limitations

### FIRST principles — verified, not asserted

TEST-03 claims the suite has no `sleep()`, no network calls and no order dependence. That claim was
checked rather than repeated:

| Claim | How it was checked | Result |
|---|---|---|
| No sleeps | `grep -rnE '\b(sleep\|usleep\|time_nanosleep)\s*\(' tests/` | no matches |
| No network calls | `grep -rnE 'curl_\|file_get_contents\(.https?\|fsockopen\|stream_socket_client' tests/` | no matches |
| No declared ordering | `grep -rn '@depends' tests/` | no matches |
| No database in unit tests | `grep -rlnE 'new PDO\|PDO::\|\$_SESSION\|\$_POST' tests/Unit/` | no matches |
| No mutable shared state | `grep -rn 'static ' tests/Unit/` | two hits, both `static fn` closures in `PaginationTest` |
| Order independence, in practice | `--order-by=random` and `--order-by=reverse` on the unit suite | `OK (96 tests, 205 assertions)` both times |
| Isolation from the database | unit suite with `docker compose stop db` | `OK (96 tests, 205 assertions)` |

The absence of `@depends` alone would have been weak evidence — a test can share state through a
static property without declaring anything — which is why the suite was actually reordered and the
grep for statics was run.

One honest caveat: the *integration* suite's isolation rests on each test cleaning up after itself
via `IntegrationTestCase`, not on a fresh database per test. It passes under the default order and
was not separately reordered, because two of its six tests deliberately open concurrent
connections and the interleaving, not the order, is the interesting variable there.

### Gaps the suite does not cover

| Gap | Consequence | Cross-reference |
|---|---|---|
| No automated HTTP-level test | Routing, CSRF and the central failure handler are covered only by hand with `curl`. **BUG-01 and BUG-02 are both instances of what this gap lets through** — each is a controller-level exception mapping that no unit or integration test can see. | TD-07 |
| In-memory fakes can be more generous than the real repositories | **BUG-04** is precisely this: `InMemorySalesOrderRepository::between()` returns fully-hydrated orders while the MySQL implementation returns headers only, so the export test passes against a fake that behaves better than production. | new, see BUG-04 |
| No browser-level testing | UI-01 was unverified when this run was written. It has since been exercised at a true 360px viewport via the Chrome DevTools Protocol, which found and led to the fix of BUG-06. Still no *automated* browser test. | [`screenshots.md`](screenshots.md), TD-07 |
| No `EXPLAIN` evidence for the dashboard and report queries | Since closed: 33 queries were EXPLAINed and the results filed in [`../quality/index-analysis.md`](../quality/index-analysis.md), which also found that `idx_so_seller_date` existed in `schema.sql` but not in the running database. | DB-01 Bukti |

### Things verified only by reading the code

Recorded for completeness so nothing in `test-results.md` overstates what was executed:

- **TS-VIEW-01-5** — the unfiltered "nothing yet" empty state. Every table has seed data, and
  emptying one would require raw `DELETE` statements. Verified by inspection at
  `views/product/index.php:50`, `views/purchase/index.php:46` and `views/sales/index.php:53`, each
  of which branches on `$filter->isActive()`.
- **TS-API-01-6** — Fetch API consumption from the frontend. Verified at
  `public/assets/availability.js:29`, which calls
  `fetch('/api/products/' + encodeURIComponent(sku) + '/availability')` with no library involved.
  The endpoint itself was exercised over HTTP in all four of its status paths.

---

## Checked and found clean

Listed because "no bugs found" is only meaningful if it says what was looked at. Each of the
following was actively attacked, not merely used:

| Area | What was attempted | Result |
|---|---|---|
| Account enumeration | Compared the login responses for a wrong password, an unknown email and a deactivated account | byte-identical once the CSRF token is normalised |
| Session fixation | Compared `PHPSESSID` before and after login | regenerated |
| Session resumption after logout | Re-requested protected pages and the JSON API on the logged-out cookie jar | 302 and 401 respectively |
| Segregation of duties | Direct POSTs to `…/approve` as Sales (own order and another's), as Warehouse Staff, and as the Admin who raised the order | 403 in every case; `approved_by = created_by` count stayed 0 |
| Oversell | Approved an order for 99 999 units against 40 in stock, then issued it | refused with the available quantity named; stock and ledger unchanged |
| Stock integrity | Ran the ledger/balance reconciliation before, during and after all stock movements | 0 mismatches every time |
| Negative stock | `SELECT COUNT(*) FROM product_stocks WHERE quantity < 0` | 0 |
| SQL injection | `' OR 1=1 --`, `%' UNION SELECT NULL --`, `1; DROP TABLE users; --` through the product search | 200 with 0 rows each; `users` and `products` intact |
| Stored XSS | Created a category named `<script>alert(1)</script>` and viewed the list | rendered escaped; no live script in the response |
| CSRF | Omitted and forged `_token` on four different POST endpoints | 403 each; nothing written |
| Hard deletion | `POST /products/1/delete`, `DELETE /products/1` | 404 and 405 — no such endpoint exists |
| Self-lockout | Admin attempting to deactivate and to demote their own account | 403 both, with specific messages |
| Public registration | `GET /register`, `GET /signup` | 404 |
| Source and secret exposure | `/.env`, `/../.env`, `/composer.json`, `/app/Service/StockService.php`, `/index.php` | 404 |
| Upload hardening | Directory listing of `/uploads/`; a text file renamed `.jpg` | 403; 422 with a MIME error |
| Trace leakage | Grepped the 403, 404, 405 and 500 bodies for `stack trace`, `#0 ` and `/var/www/html/app` | no matches; details appear in the container log only |
| Router strictness | Non-numeric ids, negative ids, `0`, and a URL-encoded path traversal in an id segment | 404 throughout |
| Verb confusion | `POST` to a GET-only route, `GET` to a POST-only route, `DELETE` to a collection | 405, distinct from 404 |
| Pagination bounds | `page=999`, `page=-5`, `page=abc` | 200 with 0 rows, and clamped to page 1 |
| Filter injection | `stock=bogus`, `status=Nope`, `sort=bogus` | value discarded, default applied, never queried |
| Static analysis | PHPStan level 6 and PHP_CodeSniffer PSR-12 | zero errors, zero violations |

No further defects were found in any of these. The four failing scenarios and the two observations
above are the complete list from this run.

---

## Not bugs, though they looked like one

Kept because a reader repeating these tests will hit the same two traps.

**Order sorting appeared broken.** `GET /sales-orders?sort=date_asc` and `?sort=date_desc` return
identical output. The accepted values are `asc` and `desc` (`OrderFilter::SORT_ASC` /
`SORT_DESC`); `date_asc` is unrecognised and correctly falls back to the default descending order,
which is TS-FIND-01-8's expected behaviour. With `?sort=asc` and `?sort=desc` the two orders are
opposite, as they should be.

**Activation toggles appeared not to work.** `POST /users/{id}/active` and
`POST /products/{id}/active` read `activate=1`, not `is_active=1`. Posting the wrong field name is
read as "deactivate" and returns a successful 302, which is easy to misread as a silent failure.
Both endpoints behave correctly with `activate`.

Neither is a defect, but both are cases where the observable symptom is a plausible bug, so they
are recorded rather than quietly dropped.
