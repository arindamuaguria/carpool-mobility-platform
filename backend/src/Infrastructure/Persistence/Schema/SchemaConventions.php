<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Persistence\Schema;

/**
 * The schema conventions of CMP-DOC-11 §4 and §5, as predicates.
 *
 * Pure: no database, no framework. `DB-014` requires domain membership to be
 * verifiable **from the table name alone**, so the rules that decide it are
 * functions of a string and are tested at level 2.
 */
final class SchemaConventions
{
    /**
     * The six storage domains (`DB-002`). Membership is carried by the name
     * prefix and by nothing else.
     */
    public const DOMAIN_PREFIXES = [
        'op_',    // DB-003 ‡ authoritative operational state
        'led_',   // DB-005 ‡ movements of value
        'ev_',    // DB-004 ‡ evidential records
        'proj_',  // DB-006  derived read models
        'mch_',   // DB-007  job state, idempotency registry, provider calls
        'cfg_',   // DB-008  policy configuration
    ];

    /** `DB-010`: a Unicode character set covering the full range. */
    public const CHARACTER_SET = 'utf8mb4';

    /** `DB-010`: a deterministic collation — accent- and case-sensitive. */
    public const COLLATION = 'utf8mb4_0900_as_cs';

    /** `DB-009` ‡. */
    public const ENGINE = 'InnoDB';

    /**
     * Geographic tokens forbidden in any identifier (`DB-020` ‡, `DB-197` ‡,
     * `DADR-14`, `SRS-REQ-101`).
     *
     * Matched against whole snake-case segments, never as substrings: `capacity`
     * contains `city`, and `TC-041` requires a rule narrow enough to produce no
     * false positive in correct code.
     *
     * `state` and `zone` are deliberately absent. An aggregate's state and a time
     * zone are legitimate and expected; including them would fire on correct
     * code, and `TC-042` requires a rule to be narrowed rather than suppressed.
     */
    public const GEOGRAPHIC_TOKENS = [
        'city', 'cities',
        'corridor', 'corridors',
        'region', 'regions', 'regional',
        'market', 'markets',
        'town', 'towns',
        'village', 'villages',
        'district', 'districts',
        'province', 'provinces',
        'metro',
        'pincode', 'postcode', 'zipcode',
    ];

    /**
     * Proper nouns forbidden in any identifier — a launch market's name is as
     * much a geography as the word `city` (`DADR-14`).
     *
     * Empty by design. No market has been selected, and inventing one here would
     * be inventing a business decision. A market chosen later is added here in
     * the same change that names it.
     *
     * @var list<string>
     */
    public const GEOGRAPHIC_PROPER_NOUNS = [];

    /**
     * `DB-002`, `DB-014`, `DB-015`: prefixed by domain, lower snake case, plural.
     */
    public static function isWellFormedTableName(string $table): bool
    {
        return self::domainOf($table) !== null
            && preg_match('/^[a-z][a-z0-9]*(_[a-z0-9]+)+s$/', $table) === 1;
    }

    /**
     * The storage domain a table belongs to, or null where the name does not
     * declare one (`DB-002`, `DB-014`).
     */
    public static function domainOf(string $table): ?string
    {
        foreach (self::DOMAIN_PREFIXES as $prefix) {
            if (str_starts_with($table, $prefix)) {
                return $prefix;
            }
        }

        return null;
    }

    /**
     * `DB-016`: lower snake case.
     *
     * The requirement also asks for singular names. That is not decidable
     * mechanically without false positives — `seats_offered` is a count and is
     * correctly plural — so it is a level-6 review obligation (`TC-032`) rather
     * than a rule here.
     */
    public static function isWellFormedColumnName(string $column): bool
    {
        return preg_match('/^[a-z][a-z0-9]*(_[a-z0-9]+)*$/', $column) === 1;
    }

    /**
     * `DB-017`: a foreign key column is named for the referenced entity followed
     * by `_id` — `op_rides` is referenced by `ride_id`.
     */
    public static function expectedForeignKeyColumn(string $referencedTable): string
    {
        $prefix = self::domainOf($referencedTable) ?? '';
        $entity = substr($referencedTable, strlen($prefix));

        return rtrim($entity, 's').'_id';
    }

    /**
     * Geographic tokens present in an identifier (`DB-020` ‡).
     *
     * @return list<string>
     */
    public static function geographicTokensIn(string $identifier): array
    {
        $forbidden = array_map(
            static fn (string $token): string => strtolower($token),
            [...self::GEOGRAPHIC_TOKENS, ...self::GEOGRAPHIC_PROPER_NOUNS],
        );

        $found = [];

        foreach (explode('_', strtolower($identifier)) as $segment) {
            if ($segment !== '' && in_array($segment, $forbidden, true)) {
                $found[] = $segment;
            }
        }

        return array_values(array_unique($found));
    }
}
