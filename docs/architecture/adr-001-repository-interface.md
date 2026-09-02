# ADR-001 — Repository interfaces on business boundaries, concrete classes elsewhere

**Status:** Accepted · **Date:** 2026-09-02 · **Amended:** 2026-09-02 · **Requirement:** ARCH-01, TEST-01

> **Amendment (during Phase 1).** The original decision drew the line at *"master data versus
> transactional data"* and placed `UserRepository` on the concrete side. Writing `AuthService`
> proved that wrong: authentication carries real business rules (an inactive account may not
> sign in; the password must verify) that TEST-01 requires to be testable without a database.
> The criterion below has been restated in terms of **behaviour rather than table category**,
> and `UserRepository` moved to the interface side. Recorded as an amendment rather than a
> silent edit, because the original reasoning is the more instructive part.

## Context

The brief forbids ORMs and requires PDO directly, while also requiring that business logic not
depend on PDO, and that at least one repository interface have both a MySQL and an in-memory
implementation used by unit tests.

Putting PDO calls in controllers would be the least code. It would also make TEST-01
unsatisfiable: a unit test of "reject a goods issue when stock is insufficient" would need a
running MySQL, a product row, a warehouse row, a user row and a stock row before it could
assert anything — and would then be an integration test wearing a unit test's name.

A second pressure runs the other way. §0 penalises structure that solves no real problem, so
giving all ten repositories an interface "for consistency" would add roughly ten files that no
test and no business rule benefits from.

## Decision

A repository gets an interface when this question is answered "yes":

> **Does a service contain a decision — a branch, a rule, a rejection — that depends on this
> repository's data, and that TEST-01 requires to be proven without a database?**

If yes, the repository is defined as an interface with a MySQL implementation and an in-memory
fake. If no, it is a plain concrete class.

Note that the criterion is about **behaviour, not table category**. The first version of this
ADR used "master data versus transactional data" as the test and got `UserRepository` wrong:
`users` is master data by any reasonable classification, yet `AuthService` branches on it to
reject inactive accounts and bad passwords — exactly the kind of rule that must be unit-tested.

Interface + MySQL + in-memory fake:

| Repository | The rule that earns it an interface |
|---|---|
| `UserRepository` | inactive account rejected; credentials must verify |
| `StockRepository` | issue rejected when the balance is insufficient |
| `StockLedgerRepository` | every movement writes exactly one ledger row |
| `SalesOrderRepository` | legal status transitions; creator may not approve |
| `PurchaseOrderRepository` | partial receipt arithmetic and outstanding quantity |
| `ProductRepository` | low-stock / reorder-point calculation |

Concrete class only, no interface — `CategoryRepository`, `SupplierRepository`,
`CustomerRepository`. These are read and written but never *reasoned about*: no service branches
on their contents, so no unit test needs to fake them.

Services receive repositories through constructor injection. The only place that names a
concrete MySQL implementation is the composition root in `public/index.php`.

## Consequences

**Good.** Business rules become unit-testable with no database, which is what makes TEST-01's
"≥6 tests across ≥3 areas, no real PDO" achievable at all. The dependency direction required by
ARCH-01 is enforced structurally: a service cannot reach PDO because it never receives one.
Swapping the persistence of one aggregate touches one class.

**Bad.** The codebase is deliberately inconsistent — some repositories have interfaces and some
do not. This looks like an oversight unless explained, which is why it is written down here. A
reader must check this ADR to know the rule.

**Bad.** The criterion is a judgement call, and this ADR has already been wrong once. A
repository can acquire a business rule later and need promoting to an interface — which is a
cheap change (add the interface, extract the fake) but must actually be noticed.

**Accepted cost.** Four extra fakes, roughly 100 lines total. They pay for themselves at the
first unit test.

## Alternatives considered

**PDO directly in controllers.** Rejected: unit tests would require a database, so TEST-01 could
not be met, and ARCH-01's dependency rule would be violated outright.

**An interface for every repository.** Rejected: about ten files of ceremony for no test and no
rule. §0 scores unjustified structure the same as messy code.

**A generic `RepositoryInterface<T>` base.** Rejected: PHP has no generics, so it would degrade
to `mixed` return types, weakening PHPStan level 6 exactly where type safety is most useful.

**Splitting by table category (master data vs transactional).** This was the original decision
and it is recorded here as rejected, because it classifies by what a table *holds* rather than
by what the code *does* with it. `users` is master data that carries authentication rules;
`categories` is master data that carries none. The category tells you nothing about testability.
