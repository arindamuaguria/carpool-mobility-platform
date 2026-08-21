<?php

declare(strict_types=1);

namespace Tests\Integration\Persistence;

use Tests\Integration\IntegrationTestCase;

/**
 * CMP-DOC-13 §19.1 check 7 — **no column exists for a payment instrument
 * credential**.
 *
 * `SEC-232` ‡ makes it one of the seven **non-suppressible** checks, and
 * `SADR-10` is the rule behind it: *"no payment instrument surface anywhere — no
 * field, column, log or response body."* `DB-037` says it of the schema and
 * `BE-097` records that the requirement chain above CMP-DOC-09 does not cover
 * payment credentials at all, which is why the architecture states it directly.
 *
 * **The consolidated obligation register surfaced this as absent**, and it was
 * built in the same change. Nothing blocked it: the check needs a deployed
 * schema and one exists. A non-suppressible obligation that nobody had noticed
 * was missing is exactly what `TC-001`'s consolidation is for.
 *
 * ## It inspects the deployed schema, not the migrations
 *
 * `SEC-135` says *"no column exists"*, which is a fact about the database rather
 * than about the files that built it. A migration could be correct and a column
 * still be there — added by hand, left by a reverted change, or created by a
 * tool. `DB-121` ‡ takes the same position about a grant: assert it against the
 * server.
 *
 * ## Why the check is a name check, and what that is worth
 *
 * A column holding a card number is not distinguishable from one holding a
 * booking reference by looking at its type. What **is** checkable is that no
 * column is *named* for one, and that is worth having for the reason `DB-019`
 * gives about constraint names: a column that holds a thing is, in practice,
 * named for the thing. It catches the mistake as it is actually made — somebody
 * adds `card_number` because the integration needed it — and it catches it in
 * every environment (`SEC-230` ‡).
 *
 * It does **not** catch a payment credential hidden in a column called
 * `reference_3`. Nothing automated would, and `TADR-12` says to record that
 * rather than to pad the check: the remaining defence is `SADR-10` as a review
 * obligation, and `PAY-16/2`'s static rule keeping the UPI response out of every
 * code path.
 *
 * ## The detector is validated
 *
 * `TC-024` ‡ requires a false positive to be **fixed, never disabled**, which
 * presumes somebody can tell a false positive from a true one. So the pattern set
 * is exercised in both directions: a name that must be caught, and a name that
 * must not — `card_number` against `booking_reference`, which contains neither a
 * credential nor anything like one but does contain the word a lazier pattern
 * would trip on.
 */
final class PaymentCredentialAbsenceTest extends IntegrationTestCase
{
    /**
     * Names a payment instrument credential is actually stored under.
     *
     * Deliberately about the **instrument**, not about payment: `op_payments`,
     * `payment_status` and `payment_attempts` are all specified tables and columns
     * (CMP-DOC-11 §6.8), and a check that flagged them would be a check somebody
     * turns off. `SADR-10` forbids the instrument's credential, not the record of
     * a payment.
     *
     * @var list<string>
     */
    private const CREDENTIAL_NAMES = [
        'card_number', 'cardnumber', 'pan', 'card_pan',
        'cvv', 'cvc', 'card_security_code', 'security_code',
        'expiry_month', 'expiry_year', 'card_expiry',
        'cardholder', 'cardholder_name', 'card_holder',
        'upi_pin', 'pin', 'mpin',
        'account_number', 'bank_account', 'ifsc', 'iban', 'sort_code',
        'vpa_secret', 'payment_token', 'card_token', 'instrument_token',
    ];

    public function test_no_deployed_column_is_named_for_a_payment_instrument_credential(): void
    {
        // SEC-135 / SADR-10 / DB-037. Asserted against the server, in the schema
        // the application actually reads.
        $offenders = [];

        foreach ($this->deployedColumns() as $column) {
            if (in_array(strtolower($column->column_name), self::CREDENTIAL_NAMES, true)) {
                $offenders[] = $column->table_name.'.'.$column->column_name;
            }
        }

        self::assertSame(
            [],
            $offenders,
            'SADR-10: no payment instrument surface anywhere — no field, column, log or response body.',
        );
    }

    public function test_the_detector_catches_a_credential_column_name(): void
    {
        // TC-024 ‡ presumes somebody can tell a false positive from a true one,
        // which presumes the detector detects. The true positive, without adding
        // a column to the schema to produce it — DB-119 ‡ withholds DDL from the
        // application account, and a test that needed DDL to prove a schema rule
        // would be a test that weakened one to check another.
        foreach (['card_number', 'CVV', 'Upi_Pin', 'ifsc'] as $name) {
            self::assertContains(
                strtolower($name),
                self::CREDENTIAL_NAMES,
                '"'.$name.'" is a payment instrument credential and the detector does not know it.',
            );
        }
    }

    public function test_the_detector_does_not_catch_a_specified_payment_column(): void
    {
        // The false positive TC-024 ‡ would otherwise have somebody disable the
        // check over. CMP-DOC-11 §6.8 specifies op_payments and
        // op_payment_attempts, and PAY-078 specifies a payment status — none of
        // which is an instrument credential.
        //
        // `booking_reference` is in the list on purpose: it ends in the word a
        // pattern matching on "reference" would trip on, and it holds nothing.
        foreach ([
            'payment_status', 'payment_id', 'payments', 'payment_attempts',
            'booking_reference', 'external_id', 'verified_at', 'amount_minor',
        ] as $name) {
            self::assertNotContains(
                $name,
                self::CREDENTIAL_NAMES,
                '"'.$name.'" is specified by CMP-DOC-11 and the detector would refuse it.',
            );
        }
    }

    public function test_the_check_reads_every_table_the_platform_holds(): void
    {
        // A check that inspected one schema domain would pass while a credential
        // sat in another. DB-002's six domains are all in scope, and the query
        // below is bounded by the database rather than by a list of tables.
        $columns = $this->deployedColumns();

        self::assertNotSame([], $columns, 'The check found no columns at all, so it proved nothing.');

        $tables = array_unique(array_map(
            static fn (object $column): string => $column->table_name,
            $columns,
        ));

        // op_users exists (CMP-IMP-051), so a query returning nothing would mean
        // the inspection is looking in the wrong place rather than that the
        // schema is clean.
        self::assertContains('op_users', $tables);
    }

    /**
     * Every column in the platform's schema, from the server.
     *
     * Read on the **migration** connection: `information_schema` shows an account
     * only what it holds privileges on, and `DB-119` ‡ leaves the application
     * account without DDL — the same reason `IntegrityConstraintsHoldTest` reads
     * triggers there. A check that inspected through the application account
     * would report a clean schema because it could not see the dirty part.
     *
     * @return list<object{table_name: string, column_name: string}>
     */
    private function deployedColumns(): array
    {
        $schema = $this->connectionSetting('mysql_migration', 'database');

        /** @var list<object{table_name: string, column_name: string}> $columns */
        $columns = $this->migrationConnection()->select(
            'SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name'
            .' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ?',
            [$schema],
        );

        return $columns;
    }
}
