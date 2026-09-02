# Decision Log — clarifications on ambiguous requirements

Per FAQ #12: ask before changing scope, and record the answer here.

Two kinds of entry: **[Trainer]** answers received from the trainer, and **[Assumption]**
readings I adopted where the brief is silent. Assumptions are flagged so they can be corrected
cheaply rather than discovered at defense.

| # | Question | Status | Reading adopted |
|---|---|---|---|
| D1 | May Warehouse Staff *create* a PO, or only propose one? | [Assumption] | §1.2 says "Boleh mengusulkan". Implemented as: Warehouse Staff can create a PO in `Draft`; only Admin may move it to `Ordered`. |
| D2 | Can an Admin approve a sales order that they created themselves? | [Assumption] | §1.2 forbids the *creator* approving the same order. Rule implemented as `actor.id !== order.createdBy` **and** actor is Admin — so an Admin cannot approve their own order either. Stricter reading, and the safer one. |
| D3 | Does goods issue reserve stock at approval time? | [Assumption] | No reservation. Stock is checked and decremented only at goods issue. Approval does not hold stock, so an approved order can still fail on insufficient stock — which SO-01 explicitly requires to be possible. |
| D4 | Must a partially received PO be closable early? | [Assumption] | Yes — `Cancelled` is reachable from `PartiallyReceived`; already-received quantities stay in the ledger. |
| D5 | Which warehouse does a sales order draw from? | [Assumption] | A single source warehouse per order (`sales_orders.warehouse_id`), chosen at creation. Splitting one order across warehouses is not in §1.3. |
| D6 | Is `Adjustment` movement exposed in the UI? | [Assumption] | Not in the UI. Used by the seed for opening balances only. Exposing manual adjustment would create a stock-editing path that §1.3 warns against. |
| D7 | Does "inventory value" use purchase price or selling price? | [Assumption] | Purchase price — inventory is valued at cost. Stated on the dashboard so the figure is unambiguous. |
| D8 | Should the JSON API use session auth or a token? | [Assumption] | Session, per API-01: "Autentikasi diperiksa sama seperti halaman biasa." Returns 401 JSON, never an HTML redirect. |

**Open — to raise with the trainer:** D1, D2 and D3 change observable behaviour and are worth
confirming. The rest are low-risk readings.
