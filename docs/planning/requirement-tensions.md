# Requirement Tensions

Places where the brief pulls in two directions at once. Recorded because each one forces a
design decision, and being able to name the tension is a better answer at defense than
pretending it was not noticed.

Two categories are kept apart deliberately: **deliberate tensions**, which are the assessment
itself, and **textual conflicts**, where two passages cannot both be followed literally.

---

## A. Deliberate tensions — these *are* the exercise

### A1. "Add design depth" vs "do not over-engineer" (§0, §3)

§3 grades architectural depth. §0 states that unjustified patterns and layers that solve no real
problem are penalised **in the same band as messy code**.

These only look contradictory. The instruction is to put abstraction exactly where it earns its
place and to be able to say why it is there and not elsewhere. Remove the tension and there is
nothing left to assess.

**How this project answers it.** Every abstraction is tied to a requirement it makes achievable:
`TransactionManager` exists so ARCH-01 and ARCH-02 can both be satisfied; repository interfaces
exist where TEST-01 needs a rule tested without a database. No abstraction exists for symmetry.
See ADR-001.

### A2. "At least one repository interface" (ARCH-01) vs "≥6 unit tests across ≥3 areas" (TEST-01)

ARCH-01 asks for a minimum of one repository interface with two implementations. TEST-01 asks
for at least six unit tests across at least three logic areas, touching no real PDO.

Taken alone, ARCH-01 sounds like one interface is sufficient. It is not: three independent logic
areas need three independently fakeable dependencies. **ARCH-01 sets a floor; TEST-01 sets the
real number.**

**How this project answers it.** Six interfaces, each justified by a specific rule under test,
listed in ADR-001. Not one, and not all ten.

---

## B. Textual conflicts — two passages that cannot both be followed literally

### B1. ARCH-01 forbids PDO in business logic; ARCH-02 requires PDO methods in business logic ⚠

> **ARCH-01:** "Business logic tidak boleh bergantung langsung pada PDO, session, atau
> superglobal PHP."
>
> **ARCH-02:** "Perubahan ProductStock dan penulisan StockLedger terjadi dalam satu transaksi
> (`beginTransaction` / `commit` / `rollBack`)."

`beginTransaction()`, `commit()` and `rollBack()` are methods **on PDO**. ARCH-02 places the
transaction boundary in the service, and ARCH-01 forbids the service from depending on the class
that provides it. Read literally, both cannot hold.

This is the sharpest tension in the brief, and it is almost certainly intentional: resolving it
is the design work being graded.

**Resolution adopted.** The transaction boundary is injected as a behaviour, not as a handle:

```php
interface TransactionManager {
    public function transactional(callable $work): mixed;   // commit, or roll back on throw
}
```

`PdoTransactionManager` implements it over a real PDO; unit tests inject a fake that simply runs
the callable. The service states *that* its work is atomic without knowing *how* atomicity is
achieved. ARCH-02's requirement is met — a real `beginTransaction`/`commit`/`rollBack` pair runs
per movement — while ARCH-01's dependency rule holds, because the service never receives a PDO.

Full reasoning in ADR-002. Implementation note: the manager is re-entrant, because an order
service opens a transaction and then calls `StockService`, which opens one too — and MySQL has
no true nested transactions.

### B2. May an unused product be deleted? §1.3 says no; PRD-01 implies yes ⚠

> **§1.3 Keputusan data:** "Produk, supplier, dan customer dinonaktifkan, bukan dihapus
> permanen." — unconditional.
>
> **PRD-01:** "Produk yang **sudah dipakai pada order** hanya dapat dinonaktifkan, bukan
> dihapus." — conditional.

PRD-01's condition implies its converse: a product *not* yet used on an order may be deleted.
§1.3 states the rule with no condition at all.

**Resolution adopted: follow §1.3. Nothing is ever hard-deleted.** Recorded as decision D9.

Three reasons. §1.3 is labelled "Keputusan data" — a policy statement, which carries more weight
than a feature description. The two readings are not symmetric in risk: offering no delete button
when one was permitted is a trivial shortfall, whereas deleting a row still referenced by a
ledger entry is unrecoverable and would break the reconciliation invariant. And commercially, a
product that ever appeared in a catalogue has a history worth keeping even if it was never
ordered.

**Cost accepted.** The product list will accumulate rows that were created by mistake. Mitigated
by the active/inactive filter; noted in the tech-debt register.

### B3. §1.2 grants Admin approval rights; §1 forbids anyone approving what they created

Already resolved as decision D2: approval requires two independent conditions — the actor holds
approval authority (Admin) **and** is not the order's creator. A second Admin account is seeded
so the rule cannot deadlock a single-Admin system. Full reasoning in
[`decisions.md`](decisions.md).

---

## C. Ambiguities, not conflicts

Wording that is underspecified rather than contradictory. Each is resolved with reasoning in
[`decisions.md`](decisions.md).

| Ref | Wording | Resolved as |
|---|---|---|
| D1 | Warehouse Staff "boleh mengusulkan" a PO — propose how? | Creates a `Draft`; only Admin issues it to the supplier |
| D3 | Is stock reserved at approval? Brief does not say | No reservation — SO-01's required evidence depends on there being none |
| D5 | Which warehouse does a sales order draw from? | One source warehouse per order; §1.3 has no field for splitting |
| D7 | "Nilai inventori" at cost or at retail? | At purchase price, stated on the dashboard |

---

## What this document is for

An assessor asking *"apakah ada requirement yang saling bertabrakan?"* is testing whether the
brief was read critically or merely executed. The useful answer is not "no" — it is naming B1,
explaining why the two rules cannot both be followed literally, and showing the design that
satisfies the intent of both.
