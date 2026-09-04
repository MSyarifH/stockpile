# Class Diagram — AS-BUILT

**Status:** drawn from the finished code, not from intent. Required by DESIGN-01.
**Companion:** [`../planning/class-diagram-initial.md`](../planning/class-diagram-initial.md),
written before any PHP existed. The differences are explained at the end.

**Notation used throughout:**

- `<|..` (dashed, hollow arrow) — **implements an interface**
- `-->` (solid) — depends on a **concrete** class
- `..>` (dotted) — creates or throws, but does not hold as a collaborator

Only the composition root (`public/index.php`) points at concrete `MySql*` classes.
Every service reaches persistence through an interface.

The code contains 85 classes; drawing all of them would be unreadable, so this file shows the
layering plus the three subsystems an assessor is most likely to probe. **Every name here maps
to a real file** — the traceability table at the end lists the path for each.

---

## 1. Layers and dependency direction

```mermaid
flowchart LR
    B["Browser<br/>HTML · CSS · Vanilla JS"]
    FC["public/index.php<br/><b>composition root</b><br/><i>the only place that names<br/>a concrete MySql* class</i>"]
    C["app/Controller<br/><i>HTTP only</i>"]
    S["app/Service<br/><i>business rules<br/>+ transaction boundaries</i>"]
    RI["«interface»<br/>app/Repository<br/><i>6 interfaces</i>"]
    RM["MySql*Repository ×6<br/><i>PDO</i>"]
    RF["InMemory*Repository ×6<br/><i>unit tests</i>"]
    RC["4 concrete repositories<br/><i>no rule depends on them</i>"]
    E["app/Entity<br/><i>data + invariants</i>"]
    SUP["app/Support<br/><i>framework-less plumbing</i>"]
    DB[("MySQL 8")]

    B --> FC
    FC --> C
    C --> S
    S --> RI
    S --> RC
    RI -.-> RM
    RI -.-> RF
    RM --> DB
    RC --> DB
    S --> E
    C --> SUP
    S --> SUP

    style RI fill:#fff3cd,stroke:#856404
    style S fill:#d4edda,stroke:#155724
    style FC fill:#e3edf9,stroke:#1a4b80
```

Nothing points back up the chain. A Controller may not run SQL; a Service never receives a
`PDO`; a Repository holds no business rule.

---

## 2. Stock — the single writer (ARCH-02)

`StockService` is the only class permitted to write `product_stocks` or `stock_ledger`. Both
order services call it rather than touching stock themselves.

```mermaid
classDiagram
    class StockService {
        -StockRepository stock
        -StockLedgerRepository ledger
        -TransactionManager transactions
        +receive(MovementCommand) void
        +issue(MovementCommand) void
        +receiveAll(MovementCommand[]) void
        +issueAll(MovementCommand[]) void
        -apply(MovementCommand[], MovementType) void
        -inDeterministicLockOrder(MovementCommand[]) MovementCommand[]
    }

    class StockRepository {
        <<interface>>
        +readBalanceForUpdate(productId, warehouseId) int
        +adjust(productId, warehouseId, delta) void
        +exists(productId, warehouseId) bool
        +ensureRow(productId, warehouseId) void
    }
    class MySqlStockRepository {
        -PDO pdo
        +readBalanceForUpdate() int
    }
    class InMemoryStockRepository {
        -array balances
        +lockOrder array
        +balance(productId, warehouseId) int
    }

    class StockLedgerRepository {
        <<interface>>
        +append(LedgerEntry) int
        +between(from, to) LedgerEntry[]
        +forProduct(productId, limit) LedgerEntry[]
        +balanceFromLedger(productId, warehouseId) int
    }
    class MySqlStockLedgerRepository
    class InMemoryStockLedgerRepository

    class TransactionManager {
        <<interface>>
        +transactional(callable) mixed
    }
    class PdoTransactionManager {
        -PDO pdo
        -int depth
    }

    class MovementCommand {
        <<DTO>>
        +int productId
        +int warehouseId
        +int quantity
        +int performedBy
        +ReferenceType referenceType
        +int referenceId
        +stockKey() string
    }
    class MovementType {
        <<enum>>
        Receipt
        Issue
        Adjustment
        +signedDelta(quantity) int
        +reducesStock() bool
    }
    class LedgerEntry
    class InsufficientStockException

    StockService --> StockRepository
    StockService --> StockLedgerRepository
    StockService --> TransactionManager
    StockService ..> MovementCommand
    StockService ..> LedgerEntry
    StockService ..> InsufficientStockException
    StockRepository <|.. MySqlStockRepository
    StockRepository <|.. InMemoryStockRepository
    StockLedgerRepository <|.. MySqlStockLedgerRepository
    StockLedgerRepository <|.. InMemoryStockLedgerRepository
    TransactionManager <|.. PdoTransactionManager
    MovementCommand --> MovementType
```

**How ARCH-01 and ARCH-02 are satisfied at once.** ARCH-02 names PDO's
`beginTransaction`/`commit`/`rollBack`, while ARCH-01 forbids business logic from depending on
PDO. `TransactionManager` resolves that: the service declares *that* its work is atomic without
knowing what a transaction is made of. `PdoTransactionManager` is re-entrant — it counts nesting
depth — because an order service opens a transaction and then calls `StockService`, which opens
another, and MySQL has no nested transactions.

---

## 3. Sales order — segregation of duties (SO-01, decision D2)

```mermaid
classDiagram
    class SalesOrderController {
        -SalesOrderService orders
        +index(Request) Response
        +submit(Request, id) Response
        +approve(Request, id) Response
        +reject(Request, id) Response
        +issue(Request, id) Response
        -transition(Request, id, callable) Response
    }
    class SalesOrderService {
        -SalesOrderRepository orders
        -StockService stock
        -TransactionManager transactions
        +search(actor, OrderFilter, page) Page
        +find(actor, id) SalesOrder
        +submit(actor, id) void
        +approve(actor, id) void
        +reject(actor, id) void
        +cancel(actor, id) void
        +issueGoods(actor, id) void
    }
    class SalesOrderRepository {
        <<interface>>
        +all(createdBy) SalesOrder[]
        +paginate(OrderFilter, page, createdBy) Page
        +findById(id) SalesOrder
        +markApproved(id, approvedBy) void
    }
    class MySqlSalesOrderRepository
    class InMemorySalesOrderRepository

    class SalesOrder {
        +int createdBy
        +int approvedBy
        +SalesOrderItem[] items
        +isOwnedBy(AuthenticatedUser) bool
        +canBeApprovedBy(AuthenticatedUser) bool
        +hasLinesShortOnStock() bool
        +total() float
    }
    class SalesOrderStatus {
        <<enum>>
        Draft
        PendingApproval
        Approved
        Fulfilled
        Cancelled
        +allowedNext() SalesOrderStatus[]
        +canTransitionTo(status) bool
        +acceptsGoodsIssue() bool
    }
    class AuthenticatedUser {
        +int id
        +Role role
        +isAdmin() bool
        +is(Role...) bool
    }
    class AuthorizationException

    SalesOrderController --> SalesOrderService
    SalesOrderService --> SalesOrderRepository
    SalesOrderService --> StockService
    SalesOrderService --> TransactionManager
    SalesOrderService ..> AuthorizationException
    SalesOrderRepository <|.. MySqlSalesOrderRepository
    SalesOrderRepository <|.. InMemorySalesOrderRepository
    SalesOrderRepository ..> SalesOrder
    SalesOrder --> SalesOrderStatus
    SalesOrder ..> AuthenticatedUser
```

**Where the rule lives.** `SalesOrder::canBeApprovedBy()` states it once — the actor must be an
Admin **and** must not be the order's creator — and `SalesOrderService::approve()` asserts it.
Nothing in the controller decides it, so the rule holds identically for the HTML form, the JSON
API and a CLI script. Verified over HTTP: an Admin approving an order they raised gets 403; a
second Admin succeeds.

---

## 4. Support layer

```mermaid
classDiagram
    class Router {
        -Session session
        +get(pattern, handler, roles) void
        +post(pattern, handler, roles) void
        +dispatch(Request) Response
        -guard(roles) void
        -match(pattern, path) array
    }
    class Request {
        +fromGlobals() Request
        +method() string
        +path() string
        +string(key, default) string
        +integer(key, default) int
        +file(key) array
        +wantsJson() bool
    }
    class Response {
        +html(body, status) Response
        +json(data, status) Response
        +redirect(to, status) Response
        +raw(body, status, headers) Response
        +send() void
    }
    class Session {
        +login(AuthenticatedUser) void
        +logout() void
        +user() AuthenticatedUser
        +requireUser() AuthenticatedUser
        +flash(type, message) void
    }
    class View {
        +render(template, data) string
        +renderInLayout(template, data, layout) string
    }
    class Validator {
        +validate(data, rules)$ array
    }
    class Csrf {
        +token() string
        +assertValid(Request) void
    }
    class Page {
        +PER_PAGE = 10
        +totalPages() int
        +normalisePage(requested)$ int
        +offsetFor(page)$ int
    }
    class QueryString {
        +with(changes) string
    }
    class CsvWriter {
        +write(rows) string
    }
    class OrderLineInput {
        +parse(Request, priceField)$ array
    }
    class ImageUploader {
        +store(file, field) string
        +delete(publicPath) void
    }
    class Database {
        +connect(config)$ PDO
    }
    class HttpException {
        +status() int
        +notFound(message)$ HttpException
        +forbidden(message)$ HttpException
        +unauthorised(message)$ HttpException
    }

    Router --> Session
    Router ..> Request
    Router ..> Response
    Router ..> HttpException
    Csrf --> Session
    Session ..> HttpException
```

`Request` and `Session` exist so no Service ever reads `$_POST` or `$_SESSION`. `Page` holds the
10-per-page rule as a constant; `QueryString` is what keeps filters alive across pagination
links.

---

## What changed between initial and as-built, and why

**Three things changed.** First, the support layer grew five classes that the initial diagram did
not foresee — `Page`, `QueryString`, `CsvWriter`, `OrderLineInput` and `ImageUploader` — because
requirements the initial sketch treated as single features (FIND-01, REPORT-01, PRD-01's upload)
each turned out to contain a reusable piece of plumbing shared by two or three call sites.
Second, `UserRepository` gained an interface: the initial design classified it as master-data
CRUD, but writing `AuthService` showed that authentication carries real rules — an inactive
account may not sign in — that TEST-01 requires to be provable without a database, so ADR-001's
criterion was restated in terms of behaviour rather than table category. Third, suppliers and
customers collapsed from two planned repositories into one `BusinessPartnerRepository` driven by
a `PartnerType` enum, because §1.3 gives them identical fields and identical rules while the
foreign keys still require separate tables.

**One prediction was confirmed rather than changed.** The initial diagram recorded an assumption
that `TransactionManager` would need to be re-entrant once order services wrapped stock calls in
their own transactions. That is exactly what Phase 4 required, and the depth counter in
`PdoTransactionManager` exists for that reason.

**Nothing planned was abandoned.** The layer boundaries, the stock choke point, the injected
transaction boundary and the selective use of repository interfaces all survived contact with
the code.

---

## Traceability — every class in this diagram, and its file

| Class in diagram | File |
|---|---|
| `StockService` | `app/Service/StockService.php` |
| `StockRepository` (interface) | `app/Repository/StockRepository.php` |
| `MySqlStockRepository` | `app/Repository/MySqlStockRepository.php` |
| `InMemoryStockRepository` | `app/Repository/InMemoryStockRepository.php` |
| `StockLedgerRepository` (interface) | `app/Repository/StockLedgerRepository.php` |
| `MySqlStockLedgerRepository` | `app/Repository/MySqlStockLedgerRepository.php` |
| `InMemoryStockLedgerRepository` | `app/Repository/InMemoryStockLedgerRepository.php` |
| `TransactionManager` (interface) | `app/Support/TransactionManager.php` |
| `PdoTransactionManager` | `app/Support/PdoTransactionManager.php` |
| `MovementCommand` | `app/Entity/MovementCommand.php` |
| `MovementType` (enum) | `app/Entity/MovementType.php` |
| `LedgerEntry` | `app/Entity/LedgerEntry.php` |
| `InsufficientStockException` | `app/Service/Exception/InsufficientStockException.php` |
| `SalesOrderController` | `app/Controller/SalesOrderController.php` |
| `SalesOrderService` | `app/Service/SalesOrderService.php` |
| `SalesOrderRepository` (interface) | `app/Repository/SalesOrderRepository.php` |
| `MySqlSalesOrderRepository` | `app/Repository/MySqlSalesOrderRepository.php` |
| `InMemorySalesOrderRepository` | `app/Repository/InMemorySalesOrderRepository.php` |
| `SalesOrder` | `app/Entity/SalesOrder.php` |
| `SalesOrderStatus` (enum) | `app/Entity/SalesOrderStatus.php` |
| `AuthenticatedUser` | `app/Entity/AuthenticatedUser.php` |
| `AuthorizationException` | `app/Service/Exception/AuthorizationException.php` |
| `Router` | `app/Support/Router.php` |
| `Request` | `app/Support/Request.php` |
| `Response` | `app/Support/Response.php` |
| `Session` | `app/Support/Session.php` |
| `View` | `app/Support/View.php` |
| `Validator` | `app/Support/Validator.php` |
| `Csrf` | `app/Support/Csrf.php` |
| `Page` | `app/Support/Page.php` |
| `QueryString` | `app/Support/QueryString.php` |
| `CsvWriter` | `app/Support/CsvWriter.php` |
| `OrderLineInput` | `app/Support/OrderLineInput.php` |
| `ImageUploader` | `app/Support/ImageUploader.php` |
| `Database` | `app/Support/Database.php` |
| `HttpException` | `app/Support/Exception/HttpException.php` |

### Classes not drawn

Omitted for readability, not because they are absent. All follow the same layering:

- **Controllers (8):** `Api`, `Auth`, `BusinessPartner`, `Category`, `Dashboard`, `Product`,
  `PurchaseOrder`, `Report`, `User`, `Warehouse`
- **Services (9):** `Auth`, `BusinessPartner`, `Category`, `Dashboard`, `Product`,
  `PurchaseOrder`, `Report`, `User`, `Warehouse`
- **Repositories:** `ProductRepository` / `PurchaseOrderRepository` / `UserRepository` with
  their `MySql*` and `InMemory*` pairs, plus the four concrete ones
  (`Category`, `BusinessPartner`, `Warehouse`, `Dashboard`)
- **Entities:** `Product`, `PurchaseOrder`, `PurchaseOrderItem`, `PurchaseOrderStatus`,
  `SalesOrderItem`, `BusinessPartner`, `PartnerType`, `Category`, `Warehouse`, `StockLevel`,
  `User`, `Role`, `ReferenceType`
- **Repository criteria:** `ProductFilter`, `OrderFilter`
