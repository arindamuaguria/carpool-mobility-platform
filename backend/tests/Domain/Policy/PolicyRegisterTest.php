<?php

declare(strict_types=1);

namespace Tests\Domain\Policy;

use Cmp\Domain\Shared\Policy\PolicyKey;
use Cmp\Domain\Shared\Policy\PolicyNotDeclared;
use Cmp\Domain\Shared\Policy\PolicyRegister;
use Cmp\Domain\Shared\Policy\PolicyType;
use Cmp\Domain\Shared\Policy\PolicyValue;
use InvalidArgumentException;
use LogicException;
use Tests\Domain\DomainTestCase;

/**
 * CMP-IMP-031 — the policy register and the typed value.
 *
 * Level 2 (`TC-029` ‡). Which keys exist, what a key accepts and what a value
 * may be read as are all decidable without a database.
 *
 * The keys used here are **test keys**, constructed into a register of this
 * test's own. The platform's register is empty on purpose, and exercising the
 * store must never require adding a key to it — that is the whole point of
 * `DB-153` ‡.
 */
final class PolicyRegisterTest extends DomainTestCase
{
    public function test_an_undeclared_key_has_no_default_and_raises(): void
    {
        // DB-153 ‡ / BE-172 ‡: absence is the mechanism. A default would
        // reintroduce the accessor BADR-12 says must not exist.
        $register = PolicyRegister::of();

        self::assertFalse($register->declares('search.radius_metres'));

        $this->expectException(PolicyNotDeclared::class);
        $register->key('search.radius_metres');
    }

    public function test_the_platform_register_declares_nothing_yet(): void
    {
        // CMP-DOC-09 §13.2: eleven values are configurable because eleven
        // decisions are outstanding. A key is declared on the commit that gives
        // something the code to read it.
        self::assertSame(0, PolicyRegister::of()->count());
    }

    public function test_a_key_records_what_reads_it(): void
    {
        // BE-172 ‡ can only be reviewed if a reviewer can see what each value
        // decides.
        $key = $this->aKey();

        self::assertSame('test.retry_limit', $key->name());
        self::assertSame(PolicyType::Integer, $key->type());
        self::assertNotSame('', $key->purpose());
    }

    public function test_a_key_without_a_stated_purpose_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PolicyKey::of('test.retry_limit', PolicyType::Integer, '   ');
    }

    public function test_a_key_name_states_its_area(): void
    {
        // The register should read as a list of decisions, not of identifiers.
        $this->expectException(InvalidArgumentException::class);

        PolicyKey::of('RetryLimit', PolicyType::Integer, 'a purpose');
    }

    public function test_a_value_is_validated_against_its_declared_type(): void
    {
        // BE-174 / ARCH-148. BADR-12 rejected an untyped store because "a
        // mistyped cancellation window becomes a runtime failure in a payment
        // path".
        $integer = PolicyKey::of('test.retry_limit', PolicyType::Integer, 'p');

        self::assertTrue($integer->accepts('3'));
        self::assertTrue($integer->accepts('-3'));
        self::assertFalse($integer->accepts('3.5'));
        self::assertFalse($integer->accepts('three'));
        self::assertFalse($integer->accepts(''));

        $duration = PolicyKey::of('test.window', PolicyType::Duration, 'p');
        self::assertTrue($duration->accepts('3600'));
        self::assertFalse($duration->accepts('-1'), 'A negative number is not a span.');

        $boolean = PolicyKey::of('test.enabled', PolicyType::Boolean, 'p');
        self::assertTrue($boolean->accepts('true'));
        self::assertTrue($boolean->accepts('false'));
        self::assertFalse($boolean->accepts('1'));
        self::assertFalse($boolean->accepts('yes'));
    }

    public function test_a_decimal_is_carried_exactly_and_never_through_a_float(): void
    {
        // DB-033 ‡: no monetary value in a floating-point type under any
        // circumstance. A policy value may well be monetary.
        $key = PolicyKey::of('test.fee', PolicyType::Decimal, 'p');

        self::assertTrue($key->accepts('0.10'));
        self::assertFalse($key->accepts('1e3'), 'Exponent notation is a float shape, not an exact decimal.');

        $value = new PolicyValue($key, '12345678901234567890.05', 1);

        self::assertSame('12345678901234567890.05', $value->asDecimal());
    }

    public function test_no_policy_type_is_a_float(): void
    {
        // DB-033 ‡ again, structurally: there is no accessor that could lose
        // precision, because there is no type that would.
        self::assertSame(
            ['integer', 'decimal', 'duration', 'boolean', 'text'],
            array_map(static fn (PolicyType $t): string => $t->value, PolicyType::cases()),
        );
    }

    public function test_a_value_cannot_be_read_as_a_type_it_was_not_declared_as(): void
    {
        // BE-166. A silent truncation would be exactly the far-from-the-mistake
        // failure BADR-12 rejects.
        $value = new PolicyValue(PolicyKey::of('test.fee', PolicyType::Decimal, 'p'), '0.10', 1);

        $this->expectException(LogicException::class);
        $value->asInteger();
    }

    public function test_a_value_carries_the_version_it_came_from(): void
    {
        // BE-167: a decision records the version it used, so it can be
        // re-examined later against the rules in force when it was taken.
        $value = new PolicyValue($this->aKey(), '3', 7);

        self::assertSame(7, $value->version());
        self::assertSame(3, $value->asInteger());
    }

    public function test_the_register_is_readable_as_a_catalogue(): void
    {
        // ARCH-146 audits every change; a reviewer needs to see what may be
        // changed at all before that means anything.
        $register = PolicyRegister::of(
            PolicyKey::of('b.value', PolicyType::Text, 'p'),
            PolicyKey::of('a.value', PolicyType::Text, 'p'),
        );

        self::assertSame(['a.value', 'b.value'], array_keys($register->catalogue()));
    }

    private function aKey(): PolicyKey
    {
        return PolicyKey::of('test.retry_limit', PolicyType::Integer, 'how many times a test double retries');
    }
}
