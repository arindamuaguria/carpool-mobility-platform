<?php

declare(strict_types=1);

namespace Tests\Domain\Persistence;

use Cmp\Infrastructure\Persistence\Schema\SchemaConventions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Domain\DomainTestCase;

/**
 * CMP-IMP-039, CMP-IMP-044, CMP-IMP-045 — the schema conventions of
 * CMP-DOC-11 §4 and §5.
 *
 * Level 2 (`TC-029` ‡): `DB-014` requires domain membership to be verifiable
 * from the table name alone, so the rules that decide it need no database.
 */
final class SchemaConventionsTest extends DomainTestCase
{
    #[Test]
    public function the_six_storage_domains_are_exactly_those_of_db_002(): void
    {
        self::assertSame(
            ['op_', 'led_', 'ev_', 'proj_', 'mch_', 'cfg_'],
            SchemaConventions::DOMAIN_PREFIXES,
        );
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function tableDomainProvider(): array
    {
        return [
            'operational' => ['op_rides', 'op_'],
            'ledger' => ['led_ledger_entries', 'led_'],
            'evidential' => ['ev_evidential_records', 'ev_'],
            'projection' => ['proj_ride_summaries', 'proj_'],
            'machinery' => ['mch_idempotency_registries', 'mch_'],
            'configuration' => ['cfg_policy_values', 'cfg_'],
            'framework default, no domain' => ['users', null],
            'plausible but undeclared' => ['operational_rides', null],
        ];
    }

    #[Test]
    #[DataProvider('tableDomainProvider')]
    public function domain_membership_is_readable_from_the_table_name_alone(string $table, ?string $domain): void
    {
        // DB-002, DB-014.
        self::assertSame($domain, SchemaConventions::domainOf($table));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function tableNameProvider(): array
    {
        return [
            'well formed' => ['op_ride_requests', true],
            'no domain prefix' => ['ride_requests', false],
            'singular' => ['op_ride_request', false],
            'upper case' => ['op_RideRequests', false],
            'camel case' => ['op_rideRequests', false],
            'prefix alone' => ['op_', false],
        ];
    }

    #[Test]
    #[DataProvider('tableNameProvider')]
    public function a_table_name_is_prefixed_lower_snake_case_and_plural(string $table, bool $wellFormed): void
    {
        // DB-002, DB-015.
        self::assertSame($wellFormed, SchemaConventions::isWellFormedTableName($table));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function columnNameProvider(): array
    {
        return [
            'well formed' => ['seats_offered', true],
            'single word' => ['status', true],
            'with digits' => ['hash_256', true],
            'camel case' => ['seatsOffered', false],
            'upper case' => ['Seats_Offered', false],
            'trailing underscore' => ['seats_', false],
            'leading underscore' => ['_seats', false],
        ];
    }

    #[Test]
    #[DataProvider('columnNameProvider')]
    public function a_column_name_is_lower_snake_case(string $column, bool $wellFormed): void
    {
        // DB-016. The requirement's "singular" half is a level-6 review
        // obligation: `seats_offered` is a count and is correctly plural.
        self::assertSame($wellFormed, SchemaConventions::isWellFormedColumnName($column));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function foreignKeyProvider(): array
    {
        return [
            'operational' => ['op_rides', 'ride_id'],
            'compound entity' => ['op_ride_requests', 'ride_request_id'],
            'machinery' => ['mch_jobs', 'job_id'],
        ];
    }

    #[Test]
    #[DataProvider('foreignKeyProvider')]
    public function a_foreign_key_column_is_named_for_the_referenced_entity(string $referenced, string $expected): void
    {
        // DB-017.
        self::assertSame($expected, SchemaConventions::expectedForeignKeyColumn($referenced));
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function geographyProvider(): array
    {
        return [
            'a city in a table name' => ['op_rides_city', ['city']],
            'a corridor in an index name' => ['op_rides_corridor_index', ['corridor']],
            'a region in a constraint name' => ['op_rides_check_region_is_served', ['region']],
            'a market' => ['proj_market_summaries', ['market']],
            'capacity is not a city' => ['lawful_capacity', []],
            'a state is an aggregate state' => ['payment_state', []],
            'a zone is a time zone' => ['time_zone', []],
            'clean' => ['op_ride_seat_allocations', []],
        ];
    }

    /**
     * @param  list<string>  $expected
     */
    #[Test]
    #[DataProvider('geographyProvider')]
    public function no_identifier_incorporates_a_geography(string $identifier, array $expected): void
    {
        // DB-020 ‡, DB-197 ‡. The negative cases are the point: TC-041 requires
        // a rule narrow enough to produce no false positive in correct code, and
        // `capacity` contains `city`.
        self::assertSame($expected, SchemaConventions::geographicTokensIn($identifier));
    }

    #[Test]
    public function no_launch_market_has_been_named_in_the_schema_rules(): void
    {
        // A market is a business decision. None has been taken, and inventing one
        // here — even as a forbidden word — would be recording a decision nobody
        // made.
        self::assertSame([], SchemaConventions::GEOGRAPHIC_PROPER_NOUNS);
    }
}
