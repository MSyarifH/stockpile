# Test Scenarios

Manual and automated test scenarios covering every functional requirement in §2 of the brief.
Executed results are recorded separately in [`test-results.md`](test-results.md); anything that
failed or behaved oddly is registered in [`known-bugs.md`](known-bugs.md).

Scenario IDs are stable: `TS-<requirement>-<n>`. Negative cases are marked **(neg)** and are
listed alongside the happy path rather than in a separate appendix, because for most of these
requirements the negative case *is* the requirement — a rule that cannot be shown to refuse
something has not been shown to exist.

## Conventions used by every scenario

- Base URL `http://localhost:8080`. The stack is started with `docker compose up -d`.
- Demo accounts all use the password `Password123!`:
  `admin@example.com`, `admin2@example.com` (Admin); `sales1@example.com`, `sales2@example.com` (Sales);
  `warehouse1@example.com`, `warehouse2@example.com` (Warehouse Staff); `inactive@example.com`
  (deactivated Sales account).
- Every state-changing request is a `POST` carrying a `_token` field. Checks driven with `curl`
  therefore GET the relevant page first, extract `name="_token" value="…"`, and reuse one cookie
  jar (`curl -b/-c`).
- **The expected result of an HTTP scenario is always a status code**, not merely the presence or
  absence of content. An empty list and a 500 error page are indistinguishable if only rows are
  counted, so every row assertion is paired with a status assertion.
- Where a scenario asserts that nothing was written, the assertion is made against the database,
  not against the page — a friendly error message is not evidence that no row was inserted.

---

## AUTH-01 — Login and session

| ID | Precondition | Steps | Expected result |
|---|---|---|---|
| TS-AUTH-01-1 | Signed out | POST `/login` with `admin@example.com` / correct password | 302 to the Admin dashboard; `/dashboard` then returns 200 |
| TS-AUTH-01-2 | Signed out | Same for `sales1@example.com` | 302, then `/dashboard` 200 showing the Sales dashboard |
| TS-AUTH-01-3 | Signed out | Same for `warehouse1@example.com` | 302, then `/dashboard` 200 showing the Warehouse dashboard |
| TS-AUTH-01-4 **(neg)** | Signed out | POST `/login` with a valid email and a wrong password | 401, login form redisplayed with a message that names neither field |
| TS-AUTH-01-5 **(neg)** | Signed out | POST `/login` with an email that does not exist | 401 and a response **byte-identical** to TS-AUTH-01-4 once the CSRF token is normalised, so the response cannot be used to enumerate accounts |
| TS-AUTH-01-6 **(neg)** | `inactive@example.com` exists with `is_active = 0` | POST `/login` with its correct password | 401; no session established; `/dashboard` still redirects to `/login` |
| TS-AUTH-01-7 **(neg)** | Signed out | GET `/dashboard`, `/products`, `/users`, `/purchase-orders`, `/sales-orders`, `/reports` | every one 302 to `/login`; no page content served |
| TS-AUTH-01-8 | Signed out | Record `PHPSESSID` from the login page, log in, record it again | the two identifiers differ, proving `session_regenerate_id(true)` ran |
| TS-AUTH-01-9 | Any account seeded | Read `users.password_hash` | every row is a `$2y$` bcrypt hash; no plaintext or reversible value |

## AUTH-02 — Logout

| ID | Precondition | Steps | Expected result |
|---|---|---|---|
| TS-AUTH-02-1 | Signed in as Admin | POST `/logout` with a valid token | 302 to `/login` |
| TS-AUTH-02-2 **(neg)** | Immediately after TS-AUTH-02-1, reusing the same cookie jar | GET `/dashboard`, then `/products` | both 302 to `/login`; the session cannot be resumed by re-opening the URL |
| TS-AUTH-02-3 **(neg)** | Same cookie jar | GET `/api/products/SKU-ELK-0001/availability` | 401 with a JSON body, not an HTML redirect |

## USR-01 — User management (Admin only, no public registration)

| ID | Precondition | Steps | Expected result |
|---|---|---|---|
| TS-USR-01-1 | Signed in as Admin | POST `/users` with a new name, unique email, strong password, role `Sales` | 302 to `/users`; the row exists with a `$2y$` hash |
| TS-USR-01-2 | The user from TS-USR-01-1 | Log in as that user | succeeds, proving the created credential is usable |
| TS-USR-01-3 | Same user | POST `/users/{id}/active` with `activate=0`, then `activate=1` | 302 each time; `is_active` follows; login fails while deactivated and succeeds again after reactivation |
| TS-USR-01-4 **(neg)** | Signed in as Admin | POST `/users` reusing `sales1@example.com` | 422 and the message "That email address is already in use."; no second row created |
| TS-USR-01-5 **(neg)** | Signed in as Admin | POST `/users` with `role=SuperUser` | 422; the role enumeration is closed to `Admin`/`Sales`/`WarehouseStaff` |
| TS-USR-01-6 **(neg)** | Signed in as Admin | POST `/users` with `password=123` | 422; weak passwords refused server-side |
| TS-USR-01-7 **(neg)** | Signed in as Admin (id 1) | POST `/users/1/active` with `activate=0` | 403 "You cannot deactivate your own account."; the account stays active, so a single Admin cannot lock everyone out |
| TS-USR-01-8 **(neg)** | Signed in as Admin (id 1) | POST `/users/1` changing `role` to `Sales` | 403; the account remains `Admin` |
| TS-USR-01-9 **(neg)** | Signed in as Sales, then as Warehouse Staff | GET `/users`, GET `/users/create`, POST `/users` | 403 on all six requests — the endpoint refuses, not just the navigation |
| TS-USR-01-10 **(neg)** | Signed out | GET `/register`, GET `/signup` | 404; there is no public registration surface |

## PRD-01 — Products, categories, reorder point, image upload

| ID | Precondition | Steps | Expected result |
|---|---|---|---|
| TS-PRD-01-1 | Signed in as Admin, Sales, Warehouse Staff in turn | GET `/products` and `/products/1` | 200 for all three roles — the catalogue is readable by every signed-in role |
| TS-PRD-01-2 **(neg)** | Signed in as Sales / Warehouse Staff | GET `/products/create`, POST `/products` | 403; only Admin may change the catalogue |
| TS-PRD-01-3 | Signed in as Admin | POST `/products` with a unique SKU and valid figures | 302; the product exists and gains one `product_stocks` row per warehouse |
| TS-PRD-01-4 **(neg)** | Signed in as Admin | POST `/products` reusing `SKU-ELK-0001` | 422 "That SKU is already used by another product." |
| TS-PRD-01-5 **(neg)** | Signed in as Admin | POST `/products` with `purchase_price=-5` | 422 "Purchase price must be at least 0." |
| TS-PRD-01-6 **(neg)** | Signed in as Admin | POST `/products` with `purchase_price=abc` | 422 "Purchase price must be a number." |
| TS-PRD-01-7 **(neg)** | Signed in as Admin | POST `/products` with `reorder_point=-3` | 422 |
| TS-PRD-01-8 **(neg)** | Signed in as Admin | POST `/products` with an empty `name` | 422 |
| TS-PRD-01-9 **(neg)** | Signed in as Admin | POST `/products` with an empty `category_id` | 422 |
| TS-PRD-01-10 **(neg)** | Signed in as Admin | POST `/products` with `category_id=9999` (no such category) | **422 with a field error**; nothing written |
| TS-PRD-01-11 **(neg)** | Signed in as Admin | POST `/products` attaching a text file renamed `fake.jpg` and declared `image/jpeg` | 422 "The image must be a JPEG, PNG or WebP file."; no product row and no file on disk |
| TS-PRD-01-12 **(neg)** | Signed in as Admin | POST `/products` attaching a genuine 3 MB PNG (over the 2 MB application limit) | 422 naming the size limit |
| TS-PRD-01-13 | Signed in as Admin | POST `/products` attaching a genuine 1×1 PNG | 302; `image_path` is a 32-hex random filename, so the stored name cannot be guessed from the upload |
| TS-PRD-01-14 | A product with an image | GET `/uploads/` and GET the stored image | directory listing 403; the image itself 200 with `Content-Type: image/png` |
| TS-PRD-01-15 | Signed in as Admin | POST `/products/{id}/active` with `activate=0` then `activate=1` | 302 each; the row is never removed |
| TS-PRD-01-16 **(neg)** | Signed in as Admin | POST `/products/{id}/delete` and `DELETE /products/{id}` | 404 and 405 — there is no hard-delete endpoint at all |
| TS-PRD-01-17 | Signed in as Admin | Create a category whose name is `<script>alert(1)</script>`, then view `/categories` | the name is rendered escaped (`&lt;script&gt;`), never as live markup |

## WH-01 — Warehouses and multi-location stock

| ID | Precondition | Steps | Expected result |
|---|---|---|---|
| TS-WH-01-1 | Signed in as Admin | GET `/warehouses` | 200 with the three seeded warehouses |
| TS-WH-01-2 **(neg)** | Signed in as Sales / Warehouse Staff | GET `/warehouses` | 403 |
| TS-WH-01-3 | Seeded data | GET `/products/1` | the page shows a per-warehouse breakdown **and** a total, with different quantities per warehouse |
| TS-WH-01-4 | A newly created product | Inspect `product_stocks` | one row per warehouse, so every product has a stock position everywhere |

## PO-01 — Purchase orders and goods receipt

| ID | Precondition | Steps | Expected result |
|---|---|---|---|
| TS-PO-01-1 **(neg)** | Signed in as Sales | GET `/purchase-orders`, POST `/purchase-orders` | 403; Sales has no part in purchasing |
| TS-PO-01-2 | Signed in as Warehouse Staff | POST `/purchase-orders` with supplier, warehouse, date and one line of 15 | 302 to the new order; status `Draft` |
| TS-PO-01-3 **(neg)** | The draft from TS-PO-01-2, signed in as Warehouse Staff | POST `/purchase-orders/{id}/place` | 403 — only an Admin may place an order with a supplier (decision D1) |
| TS-PO-01-4 **(neg)** | Still `Draft`, signed in as Warehouse Staff | POST `/purchase-orders/{id}/receive` with `received[line]=5` | rejected with "Goods can only be received against an order that has been placed."; stock, `received_quantity` and the ledger all unchanged |
| TS-PO-01-5 | Signed in as Admin | POST `/purchase-orders/{id}/place` | 302; status becomes `Ordered` |
| TS-PO-01-6 **(neg)** | Status `Ordered`, 15 outstanding | POST `…/receive` with `received[line]=99` | rejected with "cannot receive 99, only 15 outstanding."; stock unchanged, no ledger row |
| TS-PO-01-7 | Status `Ordered` | POST `…/receive` with `received[line]=6` | success; status auto-derives to `PartiallyReceived`; 9 outstanding; stock +6; exactly one `Receipt` ledger row of `+6` referencing the order and the acting user |
| TS-PO-01-8 | Status `PartiallyReceived` | POST `…/receive` with the remaining 9 | status auto-derives to `Received`; stock +9; a second `Receipt` row of `+9` |
| TS-PO-01-9 **(neg)** | Status `Received` | POST `…/receive` again | rejected: "…This order is Received."; nothing written |
| TS-PO-01-10 **(neg)** | Status `Received` | POST `…/cancel` | the illegal transition must be refused with a **4xx** and an error message; nothing written |
| TS-PO-01-11 **(neg)** | Status `Received` | POST `…/place` | as TS-PO-01-10 |
| TS-PO-01-12 **(neg)** | Status `Cancelled` | POST `…/cancel` | as TS-PO-01-10 |
| TS-PO-01-13 **(neg)** | Signed in as Warehouse Staff | POST `/purchase-orders` with `items[quantity][]=-2` | 422 "Line 1: quantity must be at least 1." |
| TS-PO-01-14 **(neg)** | Signed in as Warehouse Staff | POST `/purchase-orders` with `order_date=2030-01-01` | 422 "The order date cannot be in the future." |
| TS-PO-01-15 **(neg)** | Signed in as Warehouse Staff | POST `/purchase-orders` with `supplier_id=99999` | **422**; no order and no orphaned line items |

## SO-01 — Sales orders, approval and goods issue

| ID | Precondition | Steps | Expected result |
|---|---|---|---|
| TS-SO-01-1 **(neg)** | Signed in as Warehouse Staff | GET `/sales-orders/create`, POST `/sales-orders` | 403; Warehouse Staff cannot raise sales orders |
| TS-SO-01-2 | Signed in as `sales1` | POST `/sales-orders` with customer, warehouse, date and one line of 5 | 302; status `Draft`, `created_by` = sales1 |
| TS-SO-01-3 **(neg)** | The `Draft` order | POST `…/approve` as Admin | refused: a `Draft` order cannot become `Approved`; status unchanged |
| TS-SO-01-4 **(neg)** | The `Draft` order | POST `…/issue` as Admin | refused: goods can only be issued for an approved order |
| TS-SO-01-5 | Signed in as `sales1` | POST `…/submit` | status `PendingApproval` |
| TS-SO-01-6 **(neg)** | `PendingApproval`, raised by `sales1` | POST `…/approve` **as `sales1`** | **403** — a Sales user may not approve any order, including their own (segregation of duties) |
| TS-SO-01-7 **(neg)** | Same order | POST `…/approve` as `sales2` | 403 — no Sales user may approve, even someone else's order |
| TS-SO-01-8 **(neg)** | Same order | POST `…/approve` as `warehouse1` | 403 |
| TS-SO-01-9 | Same order | POST `…/approve` as `admin` (who did not raise it) | 302; status `Approved`, `approved_by` recorded and different from `created_by` |
| TS-SO-01-10 **(neg)** | An order raised **by** `admin` and submitted | POST `…/approve` **as that same admin** | **403** — no user may approve an order they created, Admin included (decision D2) |
| TS-SO-01-11 | The same order | POST `…/approve` as `admin2` | 302; `created_by ≠ approved_by` |
| TS-SO-01-12 **(neg)** | An `Approved` order | POST `…/issue` as `sales1` | 403 — only Admin or Warehouse Staff issue goods |
| TS-SO-01-13 | An `Approved` order for 5 units with sufficient stock | POST `…/issue` as `warehouse1` | 302; status `Fulfilled`; stock −5; one `Issue` ledger row of `−5` referencing the order |
| TS-SO-01-14 **(neg)** | An order for 99 999 units, approved (approval reserves nothing, decision D3) | POST `…/issue` | refused with a message stating requested and available quantities; status stays `Approved`; stock unchanged; no ledger row |
| TS-SO-01-15 | An `Approved` order | POST `…/cancel` | 302; status `Cancelled` — cancellation is legal at any stage before `Fulfilled` |
| TS-SO-01-16 **(neg)** | A `Fulfilled` order | POST `…/cancel`, `…/issue`, `…/approve` | each refused with an explanatory message; status stays `Fulfilled` |
| TS-SO-01-17 **(neg)** | `PendingApproval` | POST `…/reject` as `sales1` | 403 |
| TS-SO-01-18 | `PendingApproval` | POST `…/reject` as Admin | 302; the order returns to `Draft` for correction |
| TS-SO-01-19 **(neg)** | An order raised by `sales1` | GET it as `sales2` | 404, not 403 — a Sales user is not told that another seller's order exists |
| TS-SO-01-20 | Whole database | `SELECT COUNT(*) … WHERE approved_by = created_by` | 0, at every point during testing |

## VIEW-01 — Lists and detail pages

| ID | Precondition | Steps | Expected result |
|---|---|---|---|
| TS-VIEW-01-1 | Signed in as Admin | GET `/products`, `/purchase-orders`, `/sales-orders` and one detail page of each | 200 throughout |
| TS-VIEW-01-2 | Signed in as `sales1` | GET `/sales-orders` | 200, showing only orders raised by `sales1` — a strict subset of the Admin's list |
| TS-VIEW-01-3 | Signed in as Admin | GET `/products?q=zzzznomatch` | 200 with "No products match those filters." — a *filtered* empty state |
| TS-VIEW-01-4 | Same | GET `/purchase-orders?q=zzzznomatch`, `/sales-orders?q=zzzznomatch` | 200 with the equivalent "…match those filters" wording |
| TS-VIEW-01-5 | An empty table | View the list with no filters applied | wording distinguishes "nothing yet" from "nothing matches", so an empty catalogue is not mistaken for a bad search |

## FIND-01 — Search, filter, sort, pagination

| ID | Precondition | Steps | Expected result |
|---|---|---|---|
| TS-FIND-01-1 | Seeded data | GET `/products?q=a&stock=normal` pages 1–4 | exactly 10, 10, 5 and 0 rows; page size is exactly 10 |
| TS-FIND-01-2 | Same | GET `/sales-orders` and `/purchase-orders`, pages 1–2 | 10 rows on page 1 in both cases; the remainder on page 2 |
| TS-FIND-01-3 | Same | Inspect the pagination links on `/products?q=a&stock=normal` | each `href` carries `q` **and** `stock` forward, so filters survive a page change |
| TS-FIND-01-4 | Same | GET page 2 of that filtered list | the filter form still shows `q=a` and `stock=normal` as selected |
| TS-FIND-01-5 | Same | GET `/sales-orders?sort=asc&page=2` | the pagination link is `?sort=asc&page=2` and the sort control is still selected on page 2 |
| TS-FIND-01-6 **(neg)** | Same | GET `/products?…&page=999` | 200 with 0 rows — an out-of-range page is empty, not an error |
| TS-FIND-01-7 **(neg)** | Same | GET `/products?…&page=-5` and `?page=abc` | 200; the page is clamped to 1 rather than producing a negative offset |
| TS-FIND-01-8 **(neg)** | Same | GET `/products?stock=bogus`, `/purchase-orders?status=Nope`, `/sales-orders?sort=bogus` | 200; the unknown value is discarded and the default applied, never passed to SQL |
| TS-FIND-01-9 | Seeded data | GET `/sales-orders?sort=asc` and `?sort=desc` | the first dates are ascending and descending respectively |
| TS-FIND-01-10 | Seeded data | GET `/sales-orders?q=SO-2026-0003` | only that order; search by document number works |
| TS-FIND-01-11 | Seeded data | GET `/products?stock=low` | only products at or below their reorder point |
| TS-FIND-01-12 **(neg)** | Signed in as Admin | GET `/products` with `q` set to `' OR 1=1 --`, `%' UNION SELECT NULL --`, `1; DROP TABLE users; --` | 200 with 0 rows each; `users` and `products` still intact — the input is a bound parameter, never concatenated |

## DASH-01 — Role dashboards

| ID | Precondition | Steps | Expected result |
|---|---|---|---|
| TS-DASH-01-1 | Signed in as Admin | GET `/dashboard` | 200 with inventory value, units in stock, products below reorder point, awaiting approval, and both order-status breakdowns |
| TS-DASH-01-2 | Signed in as `sales1` | GET `/dashboard` | 200 with that user's own order count, value and status breakdown only |
| TS-DASH-01-3 | Signed in as `warehouse1` | GET `/dashboard` | 200 with the goods-receipt queue, goods-issue queue, units in stock and low-stock list |
| TS-DASH-01-4 | Any of the above | Re-run each displayed figure as SQL against the same tables | every figure matches, proving the tiles are aggregated rather than hardcoded |
| TS-DASH-01-5 | Seeded data | Compare the Admin "below reorder point" tile, `/products?stock=low`, and `scripts/check-low-stock.php` | all three name the same products — one definition, three consumers |

## REPORT-01 — CSV exports

| ID | Precondition | Steps | Expected result |
|---|---|---|---|
| TS-REPORT-01-1 | Signed in as Admin | GET `/reports/stock-movements?from=2026-06-01&to=2026-06-30` | 200, `Content-Type: text/csv`, `Content-Disposition: attachment; filename="…"`, one header row plus the June ledger rows |
| TS-REPORT-01-2 | Same | GET the same endpoint for September 2026 | 200 with a different, smaller row count from the same endpoint, proving the range filter is applied |
| TS-REPORT-01-3 **(neg)** | Signed in as `sales1` | GET `/reports/stock-movements?…` | 403 — stock movements are for Admin and Warehouse Staff |
| TS-REPORT-01-4 | Signed in as `warehouse1` | GET `/reports/stock-movements?…` | 200 |
| TS-REPORT-01-5 | Signed in as Admin | GET `/reports/order-status?from=2026-01-01&to=2026-12-31` | 200 CSV listing every sales order in range, **with a correct order total per row** |
| TS-REPORT-01-6 | Signed in as `sales1` | Same URL | 200 but restricted to that user's own orders — the export is not a way around ownership |
| TS-REPORT-01-7 **(neg)** | Signed in as Admin | GET with `from` later than `to` | 422 |
| TS-REPORT-01-8 **(neg)** | Signed in as Admin | GET with `from=nope&to=alsonope` | 422 |
| TS-REPORT-01-9 **(neg)** | Signed out | GET `/reports/stock-movements?…` | 302 to `/login`; no CSV bytes served |

## API-01 — JSON availability endpoint

| ID | Precondition | Steps | Expected result |
|---|---|---|---|
| TS-API-01-1 **(neg)** | Signed out | GET `/api/products/SKU-ELK-0001/availability` | **401** with `Content-Type: application/json` and a JSON body — never an HTML page or a redirect |
| TS-API-01-2 | Signed in as Admin | Same URL | 200 JSON with the SKU, reorder point, per-warehouse availability and a total |
| TS-API-01-3 | Signed in as Sales, then Warehouse Staff | Same URL | 200 for both — availability is readable by every signed-in role, as the catalogue is |
| TS-API-01-4 **(neg)** | Signed in as Admin | GET `/api/products/NOPE-9999/availability` | **404** JSON naming the unknown SKU |
| TS-API-01-5 **(neg)** | A cookie jar whose session was logged out | Same URL | 401 JSON — the API applies the same authentication check as the pages |
| TS-API-01-6 | Signed in | The product page's Vanilla JS availability lookup | consumes this endpoint via the Fetch API; no second copy of the query |

## VAL-01 — Server-side validation and CSRF

| ID | Precondition | Steps | Expected result |
|---|---|---|---|
| TS-VAL-01-1 **(neg)** | Signed in as Admin | POST `/products` with **no** `_token` | 403; nothing written |
| TS-VAL-01-2 **(neg)** | Signed in as Admin | POST `/products` with `_token=deadbeef` | 403 |
| TS-VAL-01-3 **(neg)** | Signed in as Admin | POST `/users` with no token | 403 |
| TS-VAL-01-4 **(neg)** | Signed in as Sales | POST `/sales-orders` with no token | 403 |
| TS-VAL-01-5 **(neg)** | Signed in as Sales | POST `/sales-orders` with no line items | 422 "A sales order needs at least one line." |
| TS-VAL-01-6 **(neg)** | Signed in as Sales | POST `/sales-orders` with `items[quantity][]=-5`, then `=0` | 422 "Line 1: quantity must be at least 1." in both cases |
| TS-VAL-01-7 **(neg)** | Signed in as Sales | POST `/sales-orders` with `items[selling_price][]=-1` | 422 "Line 1: price cannot be negative." |
| TS-VAL-01-8 **(neg)** | Signed in as Sales | POST `/sales-orders` with a future `order_date` | 422 |
| TS-VAL-01-9 **(neg)** | Signed in as Sales | POST `/sales-orders` with `order_date=not-a-date` | 422 |
| TS-VAL-01-10 **(neg)** | Signed in as Sales | POST `/sales-orders` with `customer_id=99999`, `items[product_id][]=99999`, `warehouse_id=99999` in turn | **422** in each case; no order row and no orphaned line items |
| TS-VAL-01-11 | Any rejected write above | Inspect the database | the relevant table's row count is unchanged — the message is not the only evidence |

### VAL-01 frontend half — `form-validate.js`

VAL-01 asks for validation in the frontend **as well as** the backend. These scenarios cover the
browser half. They are run by opening `docs/testing/form-validate-harness.html`, which loads the
real `public/assets/form-validate.js` against fields carrying the same constraint attributes the
application's forms render.

Every one of these is a **usability** check, never a security one: the corresponding negative
cases above prove the server refuses the same input when the browser is bypassed.

| ID | Field under test | Input | Expected result |
|---|---|---|---|
| TS-VAL-01-F1 | `email` (required) | blank | submit cancelled; "Email is required." |
| TS-VAL-01-F2 | `email` | `not-an-email` | "Email must be a valid email address." |
| TS-VAL-01-F3 | `reorder_point` (`min="0"`) | `-1` | "Reorder point must be at least 0." |
| TS-VAL-01-F4 | `reorder_point` (`step="1"`) | `2.5` | "Reorder point must be a whole number." |
| TS-VAL-01-F5 | `purchase_price` (`step="0.01"`) | `12.50` | **accepted** — a decimal step must not be rejected as a non-integer |
| TS-VAL-01-F6 | `password` (`minlength="8"`) | `short` | "Password must be at least 8 characters." |
| TS-VAL-01-F7 | `order_date` (`max=today`) | a future date | "Order date cannot be later than …" |
| TS-VAL-01-F8 | `items[quantity][]` (`min="1"`) | `0` | "Quantity must be at least 1." — the label is derived from an array-style name |
| TS-VAL-01-F9 | all fields | valid values | submit proceeds; no messages rendered |
| TS-VAL-01-F10 | `email`, after failing | corrected to a valid address | message and `aria-invalid` clear on input, without a second submit |
| TS-VAL-01-F11 | `sku` (required) | blank | "**SKU** is required." — the acronym, not "Sku" |
| TS-VAL-01-F12 | `category_id` (required) | nothing chosen | "**Category** is required." — the `_id` is an implementation detail the user never saw |

F11 and F12 exist as a pair with `ValidatorTest::testLabelWordingTheBrowserMustMatch`, which
asserts the same two sentences server-side. Either one alone would let the two halves drift.

**Cross-check tying the two halves together:** the message text in F1–F8 is produced by
`form-validate.js` but written to match `App\Support\Validator::label()` and
`ImageUploader`. TS-VAL-01-5 … 10 above show the server producing its own messages for the same
class of input, so the user reads the same sentence either way.

## ERR-01 — Error handling

| ID | Precondition | Steps | Expected result |
|---|---|---|---|
| TS-ERR-01-1 **(neg)** | Signed out | GET a protected URL | 302 to `/login` (not a bare 401 page) |
| TS-ERR-01-2 **(neg)** | Signed in as Sales | GET `/users` | 403 error page |
| TS-ERR-01-3 **(neg)** | Any | GET `/no-such-page`, GET `/products/1/nope` | 404 error page |
| TS-ERR-01-4 **(neg)** | Signed in | POST `/dashboard` (a GET-only route), GET `/logout` (POST-only), `DELETE /products` | 405 in all three cases — a wrong verb is distinguished from a missing route |
| TS-ERR-01-5 **(neg)** | Signed in | GET `/products/abc`, `/products/-1`, `/products/0`, `/sales-orders/abc`, and a URL-encoded traversal in the id | 404; no PHP notice or SQL error reaches the browser |
| TS-ERR-01-6 **(neg)** | Any error page above | Search the body for a stack trace, file path or SQL message | none present; details are written to the container log only |
| TS-ERR-01-7 **(neg)** | Any | GET `/.env`, `/../.env`, `/composer.json`, `/app/Service/StockService.php`, `/index.php` | 404; nothing outside `public/` is reachable and the front controller is not addressable directly |
| TS-ERR-01-8 **(neg)** | Any | GET `/uploads/` | 403; directory listing is disabled |

## DB-01 — Schema, constraints and prepared statements

| ID | Precondition | Steps | Expected result |
|---|---|---|---|
| TS-DB-01-1 | Seeded database | Count tables whose engine is not InnoDB | 0 — transactions, row locking and enforced foreign keys are all required |
| TS-DB-01-2 | Same | Count tables without a primary key | 0 |
| TS-DB-01-3 | Same | Count foreign-key constraints | ≥ 1 per relation |
| TS-DB-01-4 | Source tree | Count `prepare()` calls in `app/`, and grep for a superglobal interpolated into SQL | many prepared statements; **zero** interpolations |
| TS-DB-01-5 | Seeded and exercised database | Run the ledger/balance reconciliation query | 0 mismatched rows — `SUM(stock_ledger.quantity)` equals `product_stocks.quantity` for every (product, warehouse) |
| TS-DB-01-6 | Same | `SELECT COUNT(*) FROM product_stocks WHERE quantity < 0` | 0; the `CHECK (quantity >= 0)` constraint is the second line of defence behind the locking read |
| TS-DB-01-7 | Same | Count users whose `password_hash` is not a `$2y$` bcrypt string | 0 |
| TS-DB-01-8 | Docker only | `composer test:integration` | the concurrency and rollback tests exercise `SELECT … FOR UPDATE` and transaction rollback against real MySQL |

## JOB-01 — Scheduled low-stock job

| ID | Precondition | Steps | Expected result |
|---|---|---|---|
| TS-JOB-01-1 | Stack running | `docker compose exec app php scripts/check-low-stock.php` | a report listing every product at or below its reorder point, with the shortfall total |
| TS-JOB-01-2 | Same | Compare its output with the Admin dashboard tile and `/products?stock=low` | identical products — the job reuses the same service and the same low-stock definition |
| TS-JOB-01-3 | Products below reorder point exist | Inspect the exit code | non-zero, so a cron wrapper can alert without parsing the text |
| TS-JOB-01-4 | Same | Observe that the script runs outside the web request cycle | no session, no HTTP, no superglobals involved |

## Automated suites

| ID | Steps | Expected result |
|---|---|---|
| TS-TEST-1 | `docker compose exec app composer test` | unit and integration suites both pass, zero skipped |
| TS-TEST-2 | `docker compose stop db`, then `vendor/bin/phpunit --testsuite unit` | the whole unit suite still passes with no database at all, proving the services are testable through the in-memory fakes |
| TS-TEST-3 | With the database still stopped, `vendor/bin/phpunit --testsuite integration` | fails to connect — confirming the two suites are genuinely separated and the integration suite really does hit MySQL rather than a fake |
| TS-TEST-4 | `docker compose start db`, wait for `healthy`, re-run the integration suite | passes again; the stack is left running and healthy |
| TS-TEST-5 | Grep the test tree for `sleep(`, network calls and `@depends`; run the unit suite with `--order-by=random` and `--order-by=reverse` | no timing, no network, no order dependence (the FIRST principles) |
| TS-TEST-6 | `composer stan` and `composer sniff` | PHPStan level 6 with zero errors; PSR-12 with no violations |
