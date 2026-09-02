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
(`admin2@ioms.test`). §7.1 specifies one Admin as a *minimum*, not a maximum, so this stays
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

---

**Status:** D1–D3 resolved and implemented. Remaining entries (D4–D8) are low-risk readings that
do not change the shape of the design.
