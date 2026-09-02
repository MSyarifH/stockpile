# Refactoring Log

Required by DESIGN-03. Every entry below is a change that actually happened to
code already in the repository, verified by the test suite staying green before
and after. Nothing here is reconstructed or illustrative.

**Method used throughout:** run the full suite, make the change, run it again.
100 tests passed before and after all three refactors, and the CSV endpoint was
re-exercised over HTTP to confirm byte-identical output. Behaviour did not
change — only its shape.

**Net effect of this session:** 12 files changed, 93 insertions, 265 deletions.

---

## R1 — Duplicate Code · Extract Method (to the class that already owned it)

**Smell:** Duplicate Code. Eight controllers each carried an identical private
helper translating "no session" into a 401.

**Technique:** Extract Method, moved onto `Session` — the class that already
owns how identity is stored.

### Before — repeated verbatim in 8 controllers

```php
private function requireUser(): AuthenticatedUser
{
    $user = $this->session->user();
    if ($user === null) {
        throw HttpException::unauthorised();
    }

    return $user;
}
```

`UserController`, `ProductController`, `CategoryController`,
`WarehouseController`, `BusinessPartnerController`, `PurchaseOrderController`,
`SalesOrderController`, `ReportController`.

### After — once, in `app/Support/Session.php`

```php
public function requireUser(): AuthenticatedUser
{
    $user = $this->user();
    if ($user === null) {
        throw HttpException::unauthorised();
    }

    return $user;
}
```

Call sites became `$this->session->requireUser()`.

**Why here and not a base controller or a trait.** A shared parent class would
create an inheritance relationship purely to share six lines, and a trait would
hide the dependency on `$this->session`. `Session` already decides how identity
is read; deciding what "absent identity" means over HTTP belongs beside it.
No new class was introduced.

**Result:** 8 controllers, −79 lines net. Suite green.

---

## R2 — Duplicate Code · Extract Class

**Smell:** Duplicate Code. `PurchaseOrderController::linesFrom()` and
`SalesOrderController::linesFrom()` were 25 lines each and differed on exactly
two lines — the name of the price field. Confirmed with `diff`:

```
10c10
<   $prices = is_array($raw['purchase_price'] ?? null) ? $raw['purchase_price'] : [];
---
>   $prices = is_array($raw['selling_price'] ?? null) ? $raw['selling_price'] : [];
20c20
<   'purchase_price' => (float) ($prices[$index] ?? 0),
---
>   'selling_price'  => (float) ($prices[$index] ?? 0),
```

**Technique:** Extract Class — `App\Support\OrderLineInput`, parameterised by the
price field name.

### Before (both controllers)

```php
private function linesFrom(Request $request): array
{
    $raw = $request->input('items', []);
    if (!is_array($raw)) {
        return [];
    }

    $productIds = is_array($raw['product_id'] ?? null) ? $raw['product_id'] : [];
    $quantities = is_array($raw['quantity'] ?? null) ? $raw['quantity'] : [];
    $prices = is_array($raw['purchase_price'] ?? null) ? $raw['purchase_price'] : [];

    $lines = [];
    foreach ($productIds as $index => $productId) {
        if (!is_numeric($productId) || (int) $productId <= 0) {
            continue;
        }
        $lines[] = [
            'product_id' => (int) $productId,
            'quantity' => (int) ($quantities[$index] ?? 0),
            'purchase_price' => (float) ($prices[$index] ?? 0),
        ];
    }

    return $lines;
}
```

### After

```php
private function linesFrom(Request $request): array
{
    return array_map(
        static fn (array $line): array => [
            'product_id' => $line['product_id'],
            'quantity' => $line['quantity'],
            'purchase_price' => $line['price'],
        ],
        OrderLineInput::parse($request, 'purchase_price'),
    );
}
```

**A decision inside the refactor.** The obvious "cleaner" move was to rename the
services' input key to a neutral `price` and delete the mapping entirely. I
rejected it: `purchase_price` also exists on `ProductController` and
`ProductService` meaning *the product's own price*, and unifying the names would
have touched 35 call sites while making two different concepts share one word.
The 6-line mapping is the smaller cost.

**Result:** parsing exists once; the two controllers keep only the mapping to
their own service's contract. Suite green.

---

## R3 — Single Responsibility · Extract Class *(this is also the SRP audit)*

**Smell:** a service with more than one reason to change. `ReportService` had
grown five responsibilities:

1. authorization (who may export what),
2. date-range validation,
3. fetching rows from repositories,
4. assembling report rows,
5. **formatting those rows as CSV text.**

The fifth is not a reporting rule at all — it is a file format. A change to the
CSV dialect (delimiter, byte-order mark, quoting) had no business forcing a
change to a class that also decides who may read stock movements.

**Technique:** Extract Class — `App\Support\CsvWriter`.

### Before — `ReportService`

```php
public function toCsv(array $rows): string
{
    $handle = fopen('php://temp', 'r+');
    ...
    fwrite($handle, "\xEF\xBB\xBF");
    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }
    ...
}
```

### After — `CsvWriter::write()`, injected into `ReportController`

```php
return Response::raw($this->csv->write($rows), 200, [
    'Content-Type' => 'text/csv; charset=utf-8',
    ...
]);
```

`ReportService` no longer mentions CSV anywhere. It returns plain tabular data,
which means the same rows could be rendered as an HTML table or JSON without
touching it — the test of whether the split was real.

**Testing consequence.** The CSV tests moved out of `ReportServiceTest` into
`CsvWriterTest`, where they no longer need a ledger, a repository or a user just
to check that a comma is quoted. Two extra cases became cheap enough to add
(empty report, embedded newline), so the suite grew from 98 to 100 tests.

**Result:** 5 responsibilities → 4 in `ReportService`, 1 in `CsvWriter`.
Suite green; CSV output verified identical over HTTP afterwards.

---

## Not refactored — and why

Recorded so the omission reads as judgement rather than oversight.

**`nextNumber()` is near-duplicated** in `MySqlPurchaseOrderRepository` and
`MySqlSalesOrderRepository` — roughly ten lines each, differing in table name,
column name and prefix. Extracting it would mean passing three parameters into a
helper to remove ten lines, and the two documents number independently and are
unlikely to change together. Left in place and recorded in
[`tech-debt.md`](tech-debt.md) rather than hidden.

**The `formWithErrors()` shape recurs** across five CRUD controllers. The bodies
differ in which view they render and which fields they echo back, so what they
share is a *shape*, not code. Unifying it would mean a generic form-rendering
abstraction driven by configuration — which is exactly the kind of layer §0
penalises for solving no real problem.
