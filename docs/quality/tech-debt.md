# Tech-Debt Register

Required by DESIGN-03: shortcuts and limitations recorded honestly, with what
the ideal fix would be. Nothing here is hidden or described as finished.

Each entry states the **risk if left alone**, so this is a list of decisions
rather than a list of apologies.

---

## TD-01 · Duplicated document numbering

**What:** `MySqlPurchaseOrderRepository::nextNumber()` and
`MySqlSalesOrderRepository::nextNumber()` are near-identical — about ten lines
each, differing in table, column and prefix.

**Why it is like this:** extracting a helper would require passing three
parameters to remove ten lines, and the two document series change
independently.

**Risk if left:** low. A change to one numbering scheme would not imply the
other.

**Ideal fix:** a small `DocumentNumberSequence(table, column, prefix)`
collaborator, if a third numbered document is ever added.

---

## TD-02 · Order numbers are derived from MAX, not a sequence

**What:** the next number is computed from the highest existing number for the
year, rather than from a counter.

**Why it is like this:** it keeps the numbering readable and needs no extra
table. The `UNIQUE` index on `po_number` / `so_number` is what actually
guarantees no duplicate can be stored.

**Risk if left:** **medium.** Two orders created in the same instant can compute
the same number; the second insert then fails on the unique index and the user
sees an error rather than a retry. Data stays correct — the failure is a poor
experience, not corruption.

**Ideal fix:** catch the duplicate-key error and retry once, or take the number
from a dedicated sequence table inside the same transaction. Deliberately not
done: the concurrency effort in this project was spent where correctness is at
stake (ARCH-02), not where the failure is a visible error message.

---

## TD-03 · The stock-writer rule is a convention, not enforced by the database

**What:** only `StockService` may write `product_stocks` and `stock_ledger`.
Nothing in MySQL prevents another class from issuing `UPDATE product_stocks`
directly.

**Why it is like this:** enforcing it in the database would mean triggers, which
move business logic out of the service layer this project is graded on and make
the write invisible to the transaction the service believes it controls.

**Risk if left:** **high if violated.** Bypassing the service would leave the
ledger and the balance inconsistent, which §8.2 lists as a critical failure.

**Mitigations already in place:** a single choke point; `CHECK (quantity >= 0)`
as a second line of defence; the reconciliation query asserted by integration
tests; and a seed generator that derives both tables from one simulation so they
cannot be seeded inconsistently.

**Ideal fix:** a `REVOKE`-based database user for the web app that cannot write
those tables directly, with a separate role for the service. Out of scope here.

---

## TD-04 · No stock reservation between approval and goods issue

**What:** approving a sales order does not hold stock, so two approved orders can
compete for the same units and the second fails at issue (decision D3).

**Why it is like this:** deliberate. SO-01 requires a goods issue rejected for
insufficient stock to be demonstrable, and reservation would make that
unreachable. Reservation also needs a second quantity on `ProductStock` that
§1.3 does not define.

**Risk if left:** low, and visible. The user is told the available quantity.

**Ideal fix:** an allocation model (`reserved_quantity`, released on cancel or
expiry) if the business ever requires a promise at approval time.

---

## TD-05 · Product images are never garbage-collected on failure

**What:** if a product update stores a new image and a later step in the same
request fails, the uploaded file remains on disk while the database still points
at the old one.

**Why it is like this:** the filesystem is not part of the database transaction.
The order is chosen so the *visible* state stays correct — the new file is only
promoted after it is safely written, and the old one is deleted only after the
new path is committed.

**Risk if left:** low. Orphaned files consume disk; they are unreachable because
`/uploads/` has directory listing disabled and names are random.

**Ideal fix:** a periodic sweep comparing `public/uploads` against
`products.image_path`, as a second scheduled script.

---

## TD-06 · Dashboard aggregates are computed on every request

**What:** each dashboard load runs its aggregation queries fresh; nothing is
cached.

**Why it is like this:** DASH-01 requires figures to come from aggregation
queries rather than stored numbers, and correctness was preferred to speed at
this data volume (34 products, 30 orders).

**Risk if left:** low now; would grow with the ledger. The dashboard's
`SUM(quantity * purchase_price)` scans all stock rows.

**Ideal fix:** a short-lived cache, or a summary table refreshed by the
scheduled job. Both add a staleness question that this project does not need to
answer yet.

---

## TD-07 · No test covers the HTTP layer end to end

**What:** unit tests cover services with fakes, and integration tests cover
MySQL through the repositories. Routing, CSRF and the failure handler are
verified by hand with `curl`, and those checks are recorded in
`docs/testing/` — but they are not automated.

**Why it is like this:** §4.3 puts automated end-to-end tests out of scope.

**Risk if left:** **medium.** A regression in routing or in the central failure
handler would not be caught by `composer test`. This is the widest gap in the
suite and it is worth stating plainly rather than implying the tests cover more
than they do.

**Ideal fix:** a small HTTP-level test that boots the front controller with a
constructed `Request` and asserts the status code — feasible because `Request`
does not depend on superglobals once constructed.

---

## TD-08 · PHPStan is pinned to an outdated major version

**What:** `phpstan/phpstan ^1.11` resolves to 1.12.x, which prints a notice that
2.x exists. Level 6 with zero errors.

**Why it is like this:** the brief requires level 5 or above; 1.12 satisfies it
and was stable during development. Upgrading mid-project risked new findings
unrelated to the code being written.

**Risk if left:** none functional. The notice appears in the analysis output and
is explained in `docs/quality/static-analysis.md` rather than being ignored
silently, as TEST-03 requires.

**Ideal fix:** upgrade to `^2.2` and address anything it newly reports.

---

## TD-09 · Two commits contain changes their message does not describe

**What:** commit `b2fe510` is described as "serve HEAD wherever GET is served, and document the
ports" but contains 21 files and 265 insertions, including a `sales_orders` index
(`idx_so_seller_date`) and edits to `CsvWriter`, `Response` and several repositories. Commit
`6448cd7` similarly contains 17 files where its message accounts for 12.

**Why it is like this:** every commit in this project was staged with `git add -A`. Anything left
in the working tree from earlier work was swept into whichever commit came next, so the commit
boundaries do not match the described units of work.

**Risk if left:** **medium, and it is an evidence problem rather than a code problem.** §6.1 asks
for "commit bertahap menggambarkan perubahan nyata". A commit message that describes a one-line
HTTP fix while carrying a schema change is misleading to anyone reading the history, and the
history is a graded artefact.

**Why it was NOT fixed by rewriting history:** an interactive rebase would produce a tidy log
that misrepresents how the work actually happened. Presenting a manufactured history as the real
one would be a worse integrity problem than the untidy history it replaced, and §8.2 treats
misrepresented evidence as a critical failure. The honest option is to record it here.

**How it was found:** the index analysis noticed that `idx_so_seller_date` existed in
`schema.sql` but not in the running database; tracing when that line entered the repository
exposed the staging habit behind it.

**Corrected going forward:** commits are staged with explicit paths (`git add <path>`), never
`-A`, so a commit contains only what its message claims.

## TD-10 · The icon sprite is inlined into every HTML response

**What:** `views/partial/icon-sprite.php` is ~8 KB of SVG `<symbol>` definitions, and every full
page `require`s it. That 8 KB is therefore re-sent with every HTML response instead of being
fetched once and cached, and pages use between 12 and 25 of the 24 symbols.

**Why it is like this:** the obvious alternative — serve `/assets/icons.svg` once and reference
it with `<use href="/assets/icons.svg#i-package">` — is the form the SVG spec describes and it
was the first implementation. It was measured not to render: a reduced test page served from the
application showed an empty box for the external reference and a correct icon for an identical
inline symbol beside it. Rather than ship icons that are invisible in at least one browser, the
sprite was inlined, where `<use href="#i-package">` works everywhere.

A second, subtler bug was fixed in the same change: the stroke presentation attributes were
originally on the sprite's root `<svg>`. `<use>` clones a symbol into a shadow tree that inherits
from where the `<use>` element sits in the document, **not** from the symbol's original parent,
so those attributes never reached the shapes. They are now emitted on each `<symbol>`.

**Risk if left:** **low.** 8 KB uncompressed, and Apache gzips it to well under 2 KB; the pages
it rides on are already larger than that. No correctness impact.

**Ideal fix:** emit only the symbols a given page actually references, or keep the external
sprite file and inline it as a build-time fallback once the rendering behaviour is confirmed
across the browsers the assessor will use. Either is a performance refinement, not a fix.

**How it was found:** a screenshot of the dashboard, taken to check that the new icons had not
reintroduced the 360px navigation overflow. Every nav icon was missing from the render while the
HTML contained all 24 symbols and the sprite returned HTTP 200 — the markup was right and the
page was wrong, which only looking at the rendered output could show.
