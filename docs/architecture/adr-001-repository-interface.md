# ADR-001 — Repository interfaces on business boundaries, concrete classes elsewhere

**Status:** Accepted · **Date:** 2026-09-02 · **Requirement:** ARCH-01, TEST-01

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

Repositories are introduced behind an **interface only where a service's business rule depends
on them**, and as plain concrete classes otherwise.

Interface + MySQL + in-memory fake:

- `StockRepository`, `StockLedgerRepository` — the oversell rule and ledger consistency
- `SalesOrderRepository` — status transitions and approval authorization
- `PurchaseOrderRepository` — partial receipt arithmetic
- `ProductRepository` — low-stock / reorder-point calculation

Concrete class only, no interface:

- `CategoryRepository`, `SupplierRepository`, `CustomerRepository`, `UserRepository` — pure CRUD
  over master data. No business rule branches on them, so no unit test needs to fake them.

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

**Accepted cost.** Four extra fakes, roughly 100 lines total. They pay for themselves at the
first unit test.

## Alternatives considered

**PDO directly in controllers.** Rejected: unit tests would require a database, so TEST-01 could
not be met, and ARCH-01's dependency rule would be violated outright.

**An interface for every repository.** Rejected: about ten files of ceremony for no test and no
rule. §0 scores unjustified structure the same as messy code.

**A generic `RepositoryInterface<T>` base.** Rejected: PHP has no generics, so it would degrade
to `mixed` return types, weakening PHPStan level 6 exactly where type safety is most useful.
