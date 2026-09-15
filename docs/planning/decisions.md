# Decision Log — clarifications on ambiguous requirements

Per FAQ #12: ask before changing scope, and record the answer here.

Three kinds of entry: **[Trainer]** answers received from the trainer, **[Decided]** readings I
resolved myself against business-process reasoning and can defend, and **[Assumption]** low-risk
readings adopted where the brief is silent.

D1–D3 were originally raised as questions. Rather than block on them I resolved each by asking
which reading is safest as a *business control*, and recorded the reasoning below. All three are
defensible without a trainer answer; if a trainer later rules differently, each is a small,
localised change.

| # | Question | Status | Reading adopted |
|---|---|---|---|
| D1 | May Warehouse Staff *create* a PO, or only propose one? | **[Decided]** | Warehouse Staff may create a PO as `Draft`; only Admin may move it to `Ordered`. See reasoning below. |
| D2 | Can an Admin approve a sales order they created themselves? | **[Decided]** | No. `approved_by != created_by` is enforced for every role, Admin included. A second Admin account exists so this rule cannot deadlock. See reasoning below. |
| D3 | Is stock reserved at approval time? | **[Decided]** | No reservation. Stock is checked and decremented only at goods issue. See reasoning below. |
| D4 | Must a partially received PO be closable early? | [Assumption] | Yes — `Cancelled` is reachable from `PartiallyReceived`; already-received quantities stay in the ledger. |
| D5 | Which warehouse does a sales order draw from? | [Assumption] | A single source warehouse per order (`sales_orders.warehouse_id`), chosen at creation. Splitting one order across warehouses is not in §1.3. |
| D6 | Is `Adjustment` movement exposed in the UI? | [Assumption] | Not in the UI. Used by the seed for opening balances only. Exposing manual adjustment would create a stock-editing path that §1.3 warns against. |
| D7 | Does "inventory value" use purchase price or selling price? | [Assumption] | Purchase price — inventory is valued at cost. Stated on the dashboard so the figure is unambiguous. |
| D8 | Should the JSON API use session auth or a token? | [Assumption] | Session, per API-01: "Autentikasi diperiksa sama seperti halaman biasa." Returns 401 JSON, never an HTML redirect. |
| D10 | Does inventory value include stock of deactivated products? | **[Decided]** | Yes. A discontinued line still physically occupies the warehouse and is still an asset. Low-stock counts exclude them, because a discontinued line is not reordered — the two figures cover different populations on purpose, and the dashboard labels now say so. |
| D9 | May a product never used on an order be hard-deleted? | **[Decided]** | No. Nothing is ever hard-deleted; deactivation only. Resolves a textual conflict between §1.3 and PRD-01 — see reasoning below and `requirement-tensions.md` B2. |

---

## D1 — Warehouse Staff creates a PO as Draft; only Admin may issue it

§1.2 grants Warehouse Staff "boleh mengusulkan" a purchase order.

**Business reasoning.** Real procurement separates two documents: a *purchase requisition* (an
internal request — "stock is low, please buy") and a *purchase order* (the document sent to the
supplier, which commits the company financially). The person who notices low stock is the right
person to raise the request, but should not be the person who commits company money.

`Draft → Ordered` is exactly that point of financial commitment, so the authority boundary is
placed there. Warehouse Staff can raise and edit a `Draft`; only an Admin can issue it.

**Why this is the safe reading.** It satisfies "mengusulkan" literally while still letting
Warehouse Staff do the work. If the intended reading were "may create fully", we are not
blocking creation — only the commitment step — so the correction would be a one-line permission
change rather than a redesign.

## D2 — The creator of an order may never approve it, Admin included

§1 states the control objective directly: *"tidak ada satu peran yang bisa membuat sekaligus
menyetujui transaksi yang sama."*

**Business reasoning.** Segregation of duties exists so that no **single person** can drive a
transaction from creation to approval alone — that is the path by which a fictitious order
becomes a real shipment. Reading the rule as applying only to Sales would leave the loophole
open precisely on the role with the most authority, which inverts the intent of the control.
Auditors call this the "self-approval" finding, and it is the first thing tested in an
inventory or finance system.

The rule is therefore: **the actor must hold approval authority (Admin) *and* must not be the
order's creator.** Two independent conditions, both enforced in
`SalesOrderService::approve()` — in the service, so the rule holds identically for the HTML
form, the JSON API and any script.

**Operational consequence, and how it is handled.** With only one Admin account, an order raised
by that Admin could never be approved by anyone — the control would deadlock the system. A rule
that can wedge the business is not a safe rule. A **second Admin account** is therefore seeded
(`admin2@example.com`). §7.1 specifies one Admin as a *minimum*, not a maximum, so this stays
within the brief, and it makes the "Admin cannot approve their own order" scenario demonstrable
rather than theoretical.

## D3 — No stock reservation; stock is checked and decremented at goods issue only

**The brief settles this.** SO-01 requires that goods issue "ditolak jika stok tersedia tidak
mencukupi", and its Bukti asks for a demonstration of "goods issue saat stok tidak cukup". That
scenario is only reachable if an already-`Approved` order can still fail at issue. Reserving
stock at approval would make the very scenario the brief demands impossible to produce.

**Business reasoning.** Reservation ("allocated but not yet shipped") requires a second quantity
on `ProductStock` — `reserved_quantity` — plus rules for releasing it on cancellation, on
expiry, and on partial fulfilment. §1.3 specifies only `quantity`. Introducing an allocation
model means introducing more state that must be kept consistent under concurrency, which is
additional risk against the one requirement most likely to fail (ARCH-02), for no requirement
that asks for it. §0 penalises exactly this kind of unrequested complexity.

**Where the trade-off is absorbed.** Approving an order whose stock is insufficient is allowed,
but the approval screen **warns** the Admin when current stock will not cover the order. The
warning is advisory and does not block: the authoritative check stays at goods issue, inside the
locked transaction, where it is the only check that can actually be trusted under concurrency.

**Consequence accepted.** Two approved orders can compete for the same stock, and the second
will fail at issue. That is the correct behaviour for a system without allocation, and it
matches how the brief describes the flow.

## D9 — Nothing is hard-deleted, including products never used on an order

§1.3 ("Keputusan data") states without condition that products, suppliers and customers are
deactivated rather than permanently deleted. PRD-01 states the same rule but conditioned on the
product having been used on an order — which implies an unused product *could* be deleted. The
two passages cannot both be followed literally.

**Decision: follow §1.3. No entity is ever hard-deleted; there is no delete endpoint.**

**Business reasoning.** The two readings carry very different risk. Not offering a delete button
where one was permitted is a trivial shortfall — a reviewer sees a missing convenience. Deleting
a row that is still referenced by an order line or a ledger entry is unrecoverable, breaks the
reconciliation invariant, and §8.2 lists ledger inconsistency as a critical failure. When two
readings are both defensible, the one whose failure mode is recoverable wins.

There is also a records argument: a product that ever appeared in the catalogue is part of the
commercial history — of what was offered and at what price — even if nobody ordered it. That is
why the foreign keys in the schema are `ON DELETE RESTRICT`: the database refuses the delete
rather than cascading damage through the ledger.

**Cost accepted.** Rows created by mistake accumulate. Mitigated by the active/inactive filter on
every list, and recorded in the tech-debt register as a known limitation rather than hidden.

---

## D10 — Inventory value counts all stock; low-stock counts only active products

The dashboard's inventory value and its low-stock count deliberately cover **different
populations**, which looked like an inconsistency when testing found the two figures diverging
(Rp 681,233,000 against Rp 670,455,000 with 3 deactivated products in the catalogue).

**Decision: inventory value includes every product with stock; the low-stock count includes only
active products.**

**Business reasoning.** Valuation and replenishment answer different questions. "What is the
stock in my warehouses worth?" must include a discontinued line — the boxes are still on the
shelf and still an asset, and excluding them would understate the balance sheet. "What must I
reorder?" must exclude it — nobody reorders a line they have withdrawn from sale.

Making the two agree would break one of them. The real defect was that the dashboard did not say
which population each tile covered, so the labels now do: "all stock including discontinued
lines" against "of N active products (discontinued lines are not reordered)".

---

**Status:** D1–D3, D9 and D10 resolved and implemented. Remaining entries (D4–D8) are low-risk readings that
do not change the shape of the design.
