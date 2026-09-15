# Code Critique

Required by DESIGN-04.

## 1. Purpose and status

DESIGN-04 asks the participant to critique **a snippet supplied by the assessor**:
which smells are present, which SOLID principles are violated, and how it should
be refactored. Implementing the fix is not required.

**That snippet does not exist yet.** It arrives from the assessor, and the real
answer to DESIGN-04 will be written against their code during the defence. This
file is therefore a *preparation* document, and says so openly rather than
pretending to answer a question that has not been asked. It contains:

- the method I intend to follow, in a fixed order (§2);
- a worked example on a snippet of the kind the brief describes — one service
  that validates, persists and notifies (§3);
- a worked example on **this project's own code**, so the method is shown
  working on something I cannot dismiss as a strawman (§4);
- the things a critique should not say (§5).

The vocabulary is deliberately the same as
[`refactor-log.md`](refactor-log.md) and [`tech-debt.md`](tech-debt.md): a named
smell, a named refactoring technique, what becomes testable, and the cost.

## 2. The method

Five steps, in this order. The order matters: naming a principle before naming
the smell tends to produce a principle chosen to sound impressive, and choosing
a technique before knowing what should become testable tends to produce a
technique that moves code without improving anything.

1. **Name the smells, from the code.** Point at lines. "Long Method — 60 lines
   in one function" is a claim an assessor can check; "poor separation of
   concerns" is not.
2. **Map each smell to the principle it violates.** One smell can breach more
   than one; a smell that breaches none is still worth reporting as a smell. Do
   not force a mapping to all five letters of SOLID.
3. **Name the refactoring technique.** Extract Method, Extract Class, Introduce
   Parameter Object, Replace Conditional with Polymorphism, Dependency
   Injection. A technique with a name is a plan; "split it up" is not.
4. **State what becomes testable afterwards.** This is the step most critiques
   skip, and it is the one that proves the split was real. If the same tests
   would be written before and after, the refactor moved code without changing
   what could be verified. The test for §3 below is: can the validation rules be
   asserted with no database and no mail server?
5. **State the cost.** Every refactor buys something with something: more
   classes, more indirection, a wider constructor, a migration across call
   sites. Saying what it costs is what distinguishes a judgement from a reflex —
   and §0 of the brief penalises structure that solves no real problem as
   heavily as it penalises mess, so an unpriced recommendation is a risk.

A sixth, implicit step: **decide whether to do it at all.** "Leave it, and here
is why" is a legitimate conclusion, and §4 reaches exactly that on one point.

## 3. Worked example 1 — the validate/persist/notify service

The snippet below is the archetype the brief names. It is written here as an
illustration only; it is not a file in this repository.

```php
<?php

class OrderService
{
    public function submitOrder($customerEmail, $productId, $qty, $price)
    {
        // 1. validation
        if ($customerEmail == '' || strpos($customerEmail, '@') === false) {
            return 'bad email';
        }
        if ($qty <= 0) {
            return 'bad quantity';
        }
        if ($price < 0) {
            return 'bad price';
        }

        // 2. persistence
        $pdo = new PDO('mysql:host=db;dbname=stockpile', 'root', 'root');
        $pdo->query("INSERT INTO orders (email, total)
                     VALUES ('" . $customerEmail . "', " . ($qty * $price) . ")");
        $orderId = $pdo->lastInsertId();
        $pdo->query("INSERT INTO order_lines (order_id, product_id, qty)
                     VALUES (" . $orderId . ", " . $productId . ", " . $qty . ")");
        $pdo->query("UPDATE product_stocks SET quantity = quantity - " . $qty . "
                     WHERE product_id = " . $productId);

        // 3. notification
        $body = "<h1>Thanks!</h1><p>Your order " . $orderId . " totals "
              . number_format($qty * $price, 2) . "</p>";
        mail($customerEmail, 'Order confirmation', $body,
             "Content-Type: text/html\r\nFrom: shop@example.com");

        // 4. logging
        file_put_contents('/tmp/orders.log', date('c') . " order $orderId\n", FILE_APPEND);

        return 'ok';
    }
}
```

### 3.1 Smells

**Long Method.** One method, four unrelated jobs, roughly forty lines. The
comments numbering the sections are themselves the evidence: a method that needs
headings is a method that wants to be several methods.

**Hidden Dependency.** `new PDO(...)` and `mail(...)` are constructed and called
inside the method. Nothing in the constructor or the signature says this class
talks to a database and an SMTP server. A caller cannot tell from the API that
invoking it sends email, and a test cannot prevent it.

**Primitive Obsession.** Four loose scalars — `$customerEmail, $productId, $qty,
$price` — with no types and no meaning attached. Two of them are numbers that
must not be negative; nothing in the signature expresses that, so every caller
and this method must re-check it. Swap the third and fourth arguments and the
code still runs, charging the wrong total.

**Feature Envy.** The stock decrement (`UPDATE product_stocks SET quantity =
quantity - ...`) is a stock rule being executed by an order class. The knowledge
of how a balance is adjusted belongs with whatever owns stock; here an order
service reaches across and manipulates another concept's data directly.

**Message-formatting in a service.** `<h1>Thanks!</h1>` is presentation. A
change to the wording of an email would force a change to the class that also
decides whether a quantity is valid.

**Magic literals and hard-coded credentials.** `'root', 'root'`, the DSN, the
`From:` address and `/tmp/orders.log` are all pinned in the middle of business
logic; the credentials additionally cannot be varied per environment.

### 3.2 Defects, not just smells

Two items here are outright faults rather than matters of shape, and a critique
that lists smells while missing them has read the code less carefully than it
appears to have done.

**SQL injection.** `"... VALUES ('" . $customerEmail . "', ..."` concatenates
request data into SQL. An address of `x@y', 0); DROP TABLE orders; -- ` is
executed as SQL. The fix is not "sanitise the input" but a prepared statement
with bound parameters — the same rule this project's hard constraints impose,
and one the brief lists as a critical failure.

**No transaction.** Three writes — order header, order line, stock decrement —
run as three independent statements. If the third fails, an order exists that
never consumed stock; if the process dies between the first and second, an order
exists with no lines. There is no `beginTransaction()`, no `commit()`, no
rollback path. Nor is there a locking read before the decrement, so two
concurrent submissions both read the old balance and stock can go negative. This
is the same requirement this project addresses with `SELECT ... FOR UPDATE`
plus a `CHECK (quantity >= 0)` constraint as a second line of defence.

**Silent stock write.** Even if the transaction were fixed, the decrement writes
a balance and records no movement anywhere. There is no audit row, so no later
question ("why is this figure 40?") can be answered.

**Error handling by return string.** `return 'bad email'` is indistinguishable
at the call site from `return 'ok'` without string comparison, and the caller has
no way to know which field was at fault. Failures should be typed.

### 3.3 SOLID

**Single Responsibility — violated, and this is the central fault.** The class
has four reasons to change: a new validation rule, a schema change, a change to
the email template, a change to the log destination. Any one of those edits a
class that the other three also depend on. Every other problem here is
downstream of this one.

**Dependency Inversion — violated.** The class depends on concrete details
(`PDO`, the global `mail()`, the filesystem) rather than on abstractions it is
given. The practical consequence is the important one: **the validation rules
cannot be tested at all** without a live MySQL and a mail transport, because
reaching line 20 requires the connection on line 18 to succeed. High-level
policy is chained to low-level mechanism.

**Open/Closed — violated.** Adding a second notification channel (SMS, webhook)
means editing this method again; the notification behaviour cannot be extended
without modifying the class that owns the order rules. Note the qualification:
OCP is only worth raising here because a second channel is a *plausible*
requirement. If email were the only channel this business will ever have, citing
OCP would be inventing a variation to justify an interface — see §5.

**Interface Segregation / Liskov — not applicable.** There are no interfaces and
no inheritance here, so there is nothing to segregate and nothing to
substitute. Saying so is better than manufacturing a violation to complete the
acronym.

### 3.4 Refactored shape

Shape only — class names and responsibilities, per DESIGN-04, which does not
require an implementation.

| Class | Single responsibility | Depends on |
|---|---|---|
| `OrderRequest` (value object) | carries a validated email, product id, quantity and price; cannot be constructed in an invalid state | nothing |
| `OrderValidator` | turns raw request input into an `OrderRequest` or a typed `ValidationException` | nothing |
| `OrderService` | the business rule: create the order, move the stock, both inside one transaction | `OrderRepository`, `StockService`, `Notifier` (all injected) |
| `OrderRepository` (interface) | persistence of orders and lines via prepared statements | `PDO` in the MySQL implementation only |
| `StockService` | the only writer of balances; records a movement for every change | `StockRepository` |
| `Notifier` (interface) | "tell the customer" — one implementation per channel | its transport |
| `EmailTemplate` | the wording and markup of the message | nothing |

Techniques used, named: **Extract Class** (four times), **Introduce Parameter
Object** (`OrderRequest`), **Dependency Injection** (PDO and the notifier become
constructor arguments), **Replace Conditional with Polymorphism** would apply
only if the notification channel actually varies, **Extract Method** for the
transaction boundary.

**What becomes testable.** All the validation rules, with no database and no mail
server, because `OrderValidator` has no collaborators. The transaction rule
("stock is not moved if the order insert fails") becomes assertable against an
in-memory repository. And "a confirmation is sent exactly once" can be asserted
with a fake `Notifier` that counts calls, which is impossible while `mail()` is
called directly.

**The cost.** Seven classes where there was one, and a composition root that has
to wire them. That is a real price. It is worth paying here because each split
removes a *demonstrated* obstacle — an untestable rule, an unparameterised
query, a missing transaction — not because seven classes is tidier than one. Two
of the splits are the ones I would defend hardest if told to do only two:
injecting persistence (it unblocks all testing) and the transaction boundary (it
fixes a correctness bug).

## 4. Worked example 2 — critique of this project's own code

Applying the same five steps to `app/Support/Validator.php` (171 lines), written
by me in Phase 1 and used by every CRUD controller in the project.

### 4.1 The smells, from the actual file

**Long Method / Switch Statement.** `applyRules()` is a single `switch` over
eight rule names across lines 82–139. It is not yet unreadable, but it is the
place every future rule must be added.

**Primitive Obsession.** Rules are strings parsed at runtime:

```php
$rules = explode('|', $ruleString);
...
[$name, $parameter] = array_pad(explode(':', $rule, 2), 2, null);
```

so `'required|int|min:1'` is a miniature language with no compile-time checking.
PHPStan level 6 cannot see inside it. A typo in a rule name is invisible to
static analysis, which leads directly to the next point.

**A real defect: unknown rules pass silently.** The `switch` at line 82 has
**no `default` branch**. A rule name that matches no `case` simply falls through
and the value is accepted at line 142. So `'email' => 'required|emial'` validates
every string, and nothing — not PHPStan, not the test suite, not a runtime
warning — reports it. This is the most serious finding in this file, and it is a
one-line fix (`default: throw new \LogicException("Unknown rule {$name}")`),
which is a fair argument that it should simply be fixed rather than filed.

**Order-dependent and overloaded rules.** `min` and `max` do not mean the same
kind of thing:

```php
case 'min':
    if ((float) $value < (float) $parameter) {
case 'max':
    if (mb_strlen($string) > (int) $parameter) {
```

`min` compares a **numeric value**; `max` compares a **string length**. Both
appear in the same rule strings — `'sku' => 'required|max:64'` is a length, while
`'reorder_point' => 'required|int|min:0'` is a value — so a reader must know
which is which by memory. Worse, `min` relies on `$value` having already been
cast by an earlier `int` or `decimal` case in the same loop, so `'min:1|int'`
and `'int|min:1'` are not equivalent. That ordering dependency is nowhere
documented and nowhere tested.

**Mixed responsibilities, mildly.** The class both applies rules and composes
user-facing English (`label()` at line 155 turns `reorder_point` into "Reorder
point"; `fail()` concatenates the sentence). Message wording and validation
logic have different reasons to change, and there is no way to translate a
message without editing the validator.

**No test of its own.** `tests/Unit/` contains nine test files and
**`ValidatorTest.php` is not among them**. The most widely reused class in
`app/Support` — the one every controller trusts to be the server-side source of
truth for VAL-01 — is covered only incidentally, through service tests that
happen to pass valid data. Given that the missing `default` branch is exactly the
kind of bug a direct test would have caught, this is the weakest point in the
suite alongside TD-07.

### 4.2 SOLID

**Open/Closed — violated in the letter, and I accept it.** Adding a rule means
editing `applyRules()`. The textbook fix is Replace Conditional with
Polymorphism: a `Rule` interface, eight small classes, a registry. That is eight
new files to replace a `switch` that has been edited perhaps four times in the
project's life, and it is precisely the trade §0 warns about. I would not do it
here, and §5 explains why naming OCP is not on its own an argument for acting.

**Single Responsibility — partially violated.** Two reasons to change: the rule
set, and the wording of messages. Real, but small.

**Dependency Inversion — not violated.** The class has no collaborators at all;
that is why it *could* be unit-tested trivially, which makes the absent test
harder to excuse rather than easier.

### 4.3 Would I refactor it?

**Three things I would change, in order of value:**

1. **Add the `default` branch.** A defect, not a matter of taste: a mistyped
   rule currently disables validation for that field silently. One line.
2. **Write `ValidatorTest`.** No collaborators, no database — the cheapest test
   in the project, covering the class with the widest reach. It should assert the
   `min`/`max` asymmetry and the ordering dependency explicitly, so that
   behaviour becomes specified rather than accidental.
3. **Rename to say what they mean** — `max` to `max_length`, `min` to
   `min_value` — which removes the need to remember. This touches roughly twenty
   call sites, so it is a mechanical change with a real diff cost, and I would do
   it only together with (1), which is what makes a renamed-but-mistyped rule
   fail loudly instead of silently.

**One thing I would not change:** the `switch` itself. Replacing it with eight
rule classes and a registry adds files and indirection to a class that is 171
lines, has one caller pattern, no collaborators, and no demonstrated pressure to
be extended by anyone outside this repository. The honest reason the `switch`
looks bad is that it is a `switch`, not that it has cost anything. Under the
project's own criterion in ADR-001 — structure is earned by a rule or a test
that needs it, not by consistency — it has not earned the split. If a third
party ever needed to register rules without editing this file, that would be the
trigger, and the `default` branch added in (1) is what would make the extension
point safe.

Items (1) and (2) are recorded so the omission reads as a decision rather than
an oversight; if they are not in the repository at the time of the defence, they
are outstanding work and I will say so.

## 5. What a critique should not say

Four traps. Each is a way of sounding rigorous while giving bad advice, and §0
of the brief scores unjustified complexity in the same band as messy code — so
these are not stylistic preferences, they cost marks.

**"This should use a Factory / Strategy / Observer."** A pattern named without
the variation it absorbs is decoration. The test is: *what changes, and what
does the pattern let me change without editing existing code?* If the answer is
"nothing changes yet", the pattern is speculative. In §3 I named Replace
Conditional with Polymorphism and then declined to apply it in §4, because in one
case the variation is plausible and in the other it is imagined.

**"This class needs an interface."** An interface earns its place when something
varies — a second implementation, or a test that must substitute a fake.
`Validator` has no collaborators and one implementation; wrapping it in
`ValidatorInterface` would add a file and change nothing about what can be
tested. ADR-001 states this project's criterion, which is why six repositories
have interfaces and four do not.

**"This is not scalable."** Not a critique until the load is named. "The
dashboard runs four aggregate queries per request; at 34 products that is
sub-millisecond, and it would need roughly six figures of ledger rows before it
mattered" is a finding — it is TD-06, and it is filed with the volume attached.
"Not scalable" with no number is a guess that happens to be phrased as an
engineering objection.

**"Extract everything into more layers."** The mirror-image mistake of the
original snippet, and a reviewer can commit it while sounding responsible. Seven
classes in §3 are justified one at a time — each removes a named obstacle. An
eighth, introduced for symmetry, would be a fault of the same kind as the
forty-line method, differing only in direction.

The shortest usable summary of the whole method: **name the smell from the code,
say what the refactor makes testable, and say what it costs.** A critique that
does the first only is a vocabulary exercise. A critique that skips the third is
a recommendation nobody can weigh.
