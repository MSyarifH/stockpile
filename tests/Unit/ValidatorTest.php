<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Exception\ValidationException;
use App\Support\Validator;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Logic area 10 — server-side validation (VAL-01).
 *
 * This class had no tests at all until the critique exercise pointed out that it
 * has the widest reach in app/Support (every CRUD controller calls it) and no
 * collaborators whatsoever, making it the cheapest class in the project to test.
 * See docs/quality/critique.md, worked example 2.
 */
final class ValidatorTest extends TestCase
{
    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $rules
     * @return array<string,mixed>
     */
    private function validate(array $data, array $rules): array
    {
        return Validator::validate($data, $rules);
    }

    // --- the regression that prompted these tests --------------------------

    /**
     * A mistyped rule name used to be silently ignored, so 'required|emial'
     * accepted anything at all — validation switched itself off with no signal.
     * Rules are developer-written, never request data, so failing loudly is the
     * right behaviour.
     */
    public function testAnUnknownRuleNameThrowsInsteadOfBeingIgnored(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Unknown validation rule "emial"');

        $this->validate(['email' => 'not-an-email'], ['email' => 'required|emial']);
    }

    public function testAMistypedNumericRuleAlsoThrows(): void
    {
        $this->expectException(LogicException::class);
        // 'integer' is not a rule; 'int' is.
        $this->validate(['qty' => 'abc'], ['qty' => 'required|integer']);
    }

    public function testRequiredAndOptionalDoNotTripTheUnknownRuleCheck(): void
    {
        self::assertSame(['a' => 'x'], $this->validate(['a' => 'x'], ['a' => 'required']));
        self::assertSame(['b' => 'y'], $this->validate(['b' => 'y'], ['b' => 'optional']));
    }

    // --- required / optional ----------------------------------------------

    public function testRequiredRejectsMissingAndBlankValues(): void
    {
        foreach ([null, '', '   '] as $blank) {
            try {
                $this->validate(['name' => $blank], ['name' => 'required']);
                self::fail('Expected a blank value to be rejected: ' . var_export($blank, true));
            } catch (ValidationException $e) {
                self::assertArrayHasKey('name', $e->errors());
            }
        }
    }

    public function testOptionalTurnsABlankValueIntoNull(): void
    {
        self::assertSame(['note' => null], $this->validate(['note' => ''], ['note' => 'optional']));
    }

    public function testAFieldNotMentionedInTheRulesIsDiscarded(): void
    {
        $result = $this->validate(['name' => 'Keep', 'is_admin' => '1'], ['name' => 'required']);

        // Anything the caller did not ask for must not reach the service —
        // otherwise a crafted extra field could ride along into a write.
        self::assertSame(['name' => 'Keep'], $result);
    }

    // --- the asymmetry that motivated the rename ---------------------------

    /**
     * 'min' and 'max' read as a matching pair but were not one: min compared the
     * numeric value while max compared the string length. Renamed to min_value /
     * max_value / max_length so the comparison is visible at the call site.
     */
    public function testMinValueComparesTheNumberNotItsLength(): void
    {
        self::assertSame(['qty' => 7], $this->validate(['qty' => '7'], ['qty' => 'required|int|min_value:5']));

        $this->expectException(ValidationException::class);
        $this->validate(['qty' => '3'], ['qty' => 'required|int|min_value:5']);
    }

    public function testMaxValueComparesTheNumberNotItsLength(): void
    {
        // Under the old 'max' rule this passed, because strlen('999') is 3.
        $this->expectException(ValidationException::class);
        $this->validate(['qty' => '999'], ['qty' => 'required|int|max_value:100']);
    }

    public function testMaxLengthComparesCharacters(): void
    {
        self::assertSame(['name' => 'abcde'], $this->validate(['name' => 'abcde'], ['name' => 'required|max_length:5']));

        $this->expectException(ValidationException::class);
        $this->validate(['name' => 'abcdef'], ['name' => 'required|max_length:5']);
    }

    public function testMaxLengthCountsCharactersNotBytes(): void
    {
        // 5 accented characters are 10 bytes in UTF-8; mb_strlen must be used or
        // a legitimate name would be rejected.
        self::assertSame(
            ['name' => 'ééééé'],
            $this->validate(['name' => 'ééééé'], ['name' => 'required|max_length:5']),
        );
    }

    // --- individual rules --------------------------------------------------

    public function testEmailAcceptsAValidAddressAndRejectsAnInvalidOne(): void
    {
        self::assertSame(
            ['email' => 'a.b@example.test'],
            $this->validate(['email' => 'a.b@example.test'], ['email' => 'required|email']),
        );

        $this->expectException(ValidationException::class);
        $this->validate(['email' => 'a@b'], ['email' => 'required|email']);
    }

    public function testIntCastsToAnIntegerAndRejectsNonNumbers(): void
    {
        self::assertSame(['n' => 42], $this->validate(['n' => '42'], ['n' => 'required|int']));

        $this->expectException(ValidationException::class);
        $this->validate(['n' => '4.5'], ['n' => 'required|int']);
    }

    public function testDecimalRoundsToTwoPlaces(): void
    {
        self::assertSame(['p' => 10.35], $this->validate(['p' => '10.348'], ['p' => 'required|decimal']));
    }

    public function testDecimalRejectsNonNumericInput(): void
    {
        $this->expectException(ValidationException::class);
        $this->validate(['p' => 'free'], ['p' => 'required|decimal']);
    }

    public function testInAcceptsOnlyListedValues(): void
    {
        self::assertSame(
            ['role' => 'Sales'],
            $this->validate(['role' => 'Sales'], ['role' => 'required|in:Admin,Sales,WarehouseStaff']),
        );

        $this->expectException(ValidationException::class);
        $this->validate(['role' => 'Superuser'], ['role' => 'required|in:Admin,Sales,WarehouseStaff']);
    }

    public function testDateRejectsAnUnparseableValue(): void
    {
        self::assertSame(['d' => '2026-06-10'], $this->validate(['d' => '2026-06-10'], ['d' => 'required|date']));

        $this->expectException(ValidationException::class);
        $this->validate(['d' => 'yesterday-ish'], ['d' => 'required|date']);
    }

    public function testBooleanRecognisesTheFormsAnHtmlCheckboxCanSend(): void
    {
        foreach (['1', 'true', 'on', 'yes'] as $truthy) {
            self::assertTrue(
                $this->validate(['flag' => $truthy], ['flag' => 'optional|boolean'])['flag'],
                $truthy . ' should be true',
            );
        }

        self::assertFalse($this->validate(['flag' => '0'], ['flag' => 'optional|boolean'])['flag']);
    }

    // --- error reporting ---------------------------------------------------

    public function testEveryFailingFieldIsReportedNotJustTheFirst(): void
    {
        try {
            $this->validate(
                ['name' => '', 'email' => 'nope'],
                ['name' => 'required', 'email' => 'required|email'],
            );
            self::fail('Expected validation to fail.');
        } catch (ValidationException $e) {
            // Reporting one error at a time makes a user fix a form by trial and
            // error; VAL-01 asks for feedback, not a guessing game.
            self::assertCount(2, $e->errors());
        }
    }

    public function testFieldNamesAreHumanisedInMessages(): void
    {
        try {
            $this->validate(['reorder_point' => ''], ['reorder_point' => 'required']);
            self::fail('Expected validation to fail.');
        } catch (ValidationException $e) {
            self::assertSame('Reorder point is required.', $e->errors()['reorder_point']);
        }
    }

    /**
     * A foreign key is an implementation detail. The user picked a category;
     * they never saw an id and should not be told one is missing.
     */
    public function testForeignKeyFieldsAreNamedAfterTheThingNotTheColumn(): void
    {
        try {
            $this->validate(['category_id' => ''], ['category_id' => 'required']);
            self::fail('Expected validation to fail.');
        } catch (ValidationException $e) {
            self::assertSame('Category is required.', $e->errors()['category_id']);
        }
    }

    public function testSkuIsRenderedAsAnAcronym(): void
    {
        try {
            $this->validate(['sku' => ''], ['sku' => 'required']);
            self::fail('Expected validation to fail.');
        } catch (ValidationException $e) {
            self::assertSame('SKU is required.', $e->errors()['sku']);
        }
    }

    /**
     * Pins the exact wording the browser has to reproduce.
     *
     * public/assets/form-validate.js renders these same sentences client-side
     * so a user reads one message whether the check ran before or after the
     * POST. That mirroring is a convention, not something PHP can enforce, so
     * this test is the tripwire: change the rule here and it fails, which is
     * the prompt to change labelFor() in the script to match.
     *
     * @return list<array{string, string}>
     */
    public static function labelCases(): array
    {
        return [
            ['sku', 'SKU is required.'],
            ['category_id', 'Category is required.'],
            ['warehouse_id', 'Warehouse is required.'],
            ['product_id', 'Product is required.'],
            ['current_password', 'Current password is required.'],
            ['email', 'Email is required.'],
            ['name', 'Name is required.'],
        ];
    }

    #[DataProvider('labelCases')]
    public function testLabelWordingTheBrowserMustMatch(string $field, string $expected): void
    {
        try {
            $this->validate([$field => ''], [$field => 'required']);
            self::fail('Expected validation to fail.');
        } catch (ValidationException $e) {
            self::assertSame($expected, $e->errors()[$field]);
        }
    }
}
