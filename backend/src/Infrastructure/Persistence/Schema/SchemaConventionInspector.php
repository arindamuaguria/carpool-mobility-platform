<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Persistence\Schema;

use Cmp\Application\Shared\Schema\InspectsSchemaConventions;
use Cmp\Application\Shared\Schema\SchemaViolation;
use Illuminate\Database\ConnectionInterface;

/**
 * Inspects the deployed schema against the conventions of CMP-DOC-11 §4 and §5.
 *
 * `DB-014`: domain membership is verifiable from the table name alone and is
 * checked at migration time. `DB-216`: migration review verifies domain prefix,
 * index justification and constraint registration — this covers the mechanical
 * part of that review; the rest stays a level-6 obligation (`TC-032`).
 *
 * Reads `information_schema` rather than a migration file, so the rules hold
 * against the schema that actually exists — including anything created outside a
 * migration.
 */
final class SchemaConventionInspector implements InspectsSchemaConventions
{
    /**
     * The domains holding a record another party is entitled to as evidence
     * (`DB-030` ‡). See {@see inspectDeleteRules()} for why these three and not
     * the other three.
     *
     * @var list<string>
     */
    private const EVIDENCE_BEARING_DOMAINS = ['op_', 'ev_', 'led_'];

    public function __construct(private readonly ConnectionInterface $connection) {}

    /**
     * @return list<SchemaViolation>
     */
    public function inspect(): array
    {
        $schema = $this->schemaName();

        return [
            ...$this->inspectTables($schema),
            ...$this->inspectColumns($schema),
            ...$this->inspectIndexes($schema),
            ...$this->inspectConstraints($schema),
            ...$this->inspectForeignKeys($schema),
            ...$this->inspectDeleteRules($schema),
            ...$this->inspectForeignKeyEnforcement(),
        ];
    }

    /**
     * @return list<SchemaViolation>
     */
    private function inspectTables(string $schema): array
    {
        $violations = [];

        foreach ($this->rows(
            'SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?',
            [$schema, 'BASE TABLE'],
        ) as $table) {
            $name = (string) $table['TABLE_NAME'];
            $engine = (string) ($table['ENGINE'] ?? 'unknown');
            $collation = (string) ($table['TABLE_COLLATION'] ?? 'unknown');

            if (SchemaConventions::domainOf($name) === null) {
                $violations[] = new SchemaViolation(
                    'DB-002/DB-014',
                    $name,
                    'the table belongs to no storage domain; a name begins with one of '
                    .implode(', ', SchemaConventions::DOMAIN_PREFIXES),
                );
            } elseif (! SchemaConventions::isWellFormedTableName($name)) {
                $violations[] = new SchemaViolation(
                    'DB-015',
                    $name,
                    'a table name is lower snake case, prefixed by domain, and plural',
                );
            }

            if ($engine !== SchemaConventions::ENGINE) {
                $violations[] = new SchemaViolation(
                    'DB-009',
                    $name,
                    sprintf('engine is %s; every table uses %s', $engine, SchemaConventions::ENGINE),
                );
            }

            if ($collation !== SchemaConventions::COLLATION) {
                $violations[] = new SchemaViolation(
                    'DB-010',
                    $name,
                    sprintf('collation is %s; the schema uses %s', $collation, SchemaConventions::COLLATION),
                );
            }

            $violations = [...$violations, ...$this->geographyViolations('DB-020', $name, $name)];
        }

        return $violations;
    }

    /**
     * @return list<SchemaViolation>
     */
    private function inspectColumns(string $schema): array
    {
        $violations = [];

        foreach ($this->rows(
            'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ?',
            [$schema],
        ) as $column) {
            $table = (string) $column['TABLE_NAME'];
            $name = (string) $column['COLUMN_NAME'];
            $subject = $table.'.'.$name;

            if (! SchemaConventions::isWellFormedColumnName($name)) {
                $violations[] = new SchemaViolation('DB-016', $subject, 'a column name is lower snake case');
            }

            $violations = [...$violations, ...$this->geographyViolations('DB-020', $subject, $name)];
        }

        return $violations;
    }

    /**
     * `DB-018`: an index name states its table and its columns, so that a
     * duplicate index is visible by name. `DB-197` ‡: no index incorporates a
     * geography.
     *
     * @return list<SchemaViolation>
     */
    private function inspectIndexes(string $schema): array
    {
        $violations = [];

        foreach ($this->rows(
            'SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND INDEX_NAME <> ?',
            [$schema, 'PRIMARY'],
        ) as $row) {
            $table = (string) $row['TABLE_NAME'];
            $index = (string) $row['INDEX_NAME'];
            $column = $row['COLUMN_NAME'] === null ? null : (string) $row['COLUMN_NAME'];
            $subject = $table.'.'.$index;

            if (! str_starts_with($index, $table.'_')) {
                $violations[] = new SchemaViolation(
                    'DB-018',
                    $subject,
                    'an index name states its table, so that a duplicate index is visible by name',
                );
            }

            if ($column !== null && ! str_contains($index, $column)) {
                $violations[] = new SchemaViolation(
                    'DB-018',
                    $subject,
                    sprintf('an index name states its columns; %s is not named', $column),
                );
            }

            $violations = [...$violations, ...$this->geographyViolations('DB-197', $subject, $index)];
        }

        return $violations;
    }

    /**
     * `DB-019` / `DB-203`: a constraint name states the rule it enforces, so that
     * a violation message names the rule.
     *
     * @return list<SchemaViolation>
     */
    private function inspectConstraints(string $schema): array
    {
        $violations = [];

        foreach ($this->rows(
            'SELECT TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND CONSTRAINT_TYPE <> ?',
            [$schema, 'PRIMARY KEY'],
        ) as $row) {
            $table = (string) $row['TABLE_NAME'];
            $constraint = (string) $row['CONSTRAINT_NAME'];
            $type = (string) $row['CONSTRAINT_TYPE'];
            $subject = $table.'.'.$constraint;

            if (! str_starts_with($constraint, $table.'_')) {
                $violations[] = new SchemaViolation(
                    'DB-019',
                    $subject,
                    'a constraint name begins with its table, so that a violation message locates the rule',
                );
            }

            if ($type === 'CHECK' && preg_match('/^'.preg_quote($table, '/').'_check_[a-z0-9_]{3,}$/', $constraint) !== 1) {
                $violations[] = new SchemaViolation(
                    'DB-019/DB-203',
                    $subject,
                    'a CHECK constraint is named <table>_check_<the rule it enforces>',
                );
            }

            $violations = [...$violations, ...$this->geographyViolations('DB-197', $subject, $constraint)];
        }

        return $violations;
    }

    /**
     * `DB-017`: a foreign key column is named for the referenced entity followed
     * by `_id`. `DB-012` ‡: no table outside `proj_` holds a foreign key into
     * `proj_`. `DB-013`: no `mch_` table holds a foreign key into `op_`.
     *
     * @return list<SchemaViolation>
     */
    private function inspectForeignKeys(string $schema): array
    {
        $violations = [];

        foreach ($this->rows(
            'SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$schema],
        ) as $row) {
            $table = (string) $row['TABLE_NAME'];
            $column = (string) $row['COLUMN_NAME'];
            $referenced = (string) $row['REFERENCED_TABLE_NAME'];
            $subject = $table.'.'.$column;
            $expected = SchemaConventions::expectedForeignKeyColumn($referenced);

            if ($column !== $expected) {
                $violations[] = new SchemaViolation(
                    'DB-017',
                    $subject,
                    sprintf('a foreign key to %s is named %s', $referenced, $expected),
                );
            }

            if (SchemaConventions::domainOf($referenced) === 'proj_' && SchemaConventions::domainOf($table) !== 'proj_') {
                $violations[] = new SchemaViolation(
                    'DB-012',
                    $subject,
                    'no table outside proj_ holds a foreign key into proj_; a projection must stay droppable',
                );
            }

            if (SchemaConventions::domainOf($table) === 'mch_' && SchemaConventions::domainOf($referenced) === 'op_') {
                $violations[] = new SchemaViolation(
                    'DB-013',
                    $subject,
                    'no mch_ table holds a foreign key into op_; the association is by recorded identifier',
                );
            }
        }

        return $violations;
    }

    /**
     * `DB-030` ‡: *"No foreign key shall cascade a delete into a record another
     * party is entitled to as evidence."*
     *
     * `DADR-12` gives the reason in plainer words — retention removal *"never
     * deletes a row that another party is entitled to as evidence"*, because
     * **row deletion would break the evidential hash chain**. A cascade is the
     * way such a deletion happens without anybody writing it: a parent goes, and
     * the database takes the evidence with it.
     *
     * The rule is narrowed to the domains that hold such a record, each with its
     * reason for being in or out:
     *
     * - `op_` — the live operational record a counterparty is party to.
     * - `ev_` — the chain itself; `DB-125` ‡ forbids deleting a record at all.
     * - `led_` — the financial record, which `DB-030` ‡'s *"another party"* is
     *   most obviously entitled to.
     *
     * and the three that are out: `proj_` is rebuildable from authoritative state
     * (`ARCH-113`), `mch_` is machinery and `DB-156` makes it prunable, and `cfg_`
     * is configuration nobody is a party to.
     *
     * `SET NULL` is refused alongside `CASCADE`. It does not delete the row, but
     * it destroys the association that made the row evidence of anything, which
     * is the same loss by a quieter route.
     *
     * @return list<SchemaViolation>
     */
    private function inspectDeleteRules(string $schema): array
    {
        $violations = [];

        foreach ($this->rows(
            'SELECT CONSTRAINT_NAME, TABLE_NAME, REFERENCED_TABLE_NAME, DELETE_RULE'
            .' FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ?',
            [$schema],
        ) as $row) {
            $rule = strtoupper((string) $row['DELETE_RULE']);

            if ($rule !== 'CASCADE' && $rule !== 'SET NULL') {
                continue;
            }

            $referenced = (string) $row['REFERENCED_TABLE_NAME'];

            if (! in_array(SchemaConventions::domainOf($referenced), self::EVIDENCE_BEARING_DOMAINS, true)) {
                continue;
            }

            $violations[] = new SchemaViolation(
                'DB-030',
                (string) $row['TABLE_NAME'].'.'.(string) $row['CONSTRAINT_NAME'],
                sprintf(
                    'ON DELETE %s into %s; a record another party is entitled to as evidence is not removed by a '
                    .'cascade, and DADR-12 removes personal data in place instead',
                    $rule,
                    $referenced,
                ),
            );
        }

        return $violations;
    }

    /**
     * `DB-029`: *"Foreign keys shall be declared and enforced **by the
     * database**."*
     *
     * Declared is what {@see inspectForeignKeys()} reads. Enforced is this: a
     * foreign key the server has been told to ignore is a foreign key in name
     * only, and `DB-012` ‡, `DB-013` and `DB-030` ‡ all rest on the server
     * actually applying what the schema declares.
     *
     * The same reasoning as `OPS-122` ‡, which requires `CHECK` enforcement to be
     * verified *"by attempting a violating write, not by reading a version
     * string"* — the difference being that `foreign_key_checks` is a switch rather
     * than a capability, so reading it is reading the thing itself.
     *
     * @return list<SchemaViolation>
     */
    private function inspectForeignKeyEnforcement(): array
    {
        $rows = $this->rows('SELECT @@SESSION.foreign_key_checks AS enforced', []);
        $enforced = $rows === [] ? null : (int) $rows[0]['enforced'];

        if ($enforced === 1) {
            return [];
        }

        return [new SchemaViolation(
            'DB-029',
            'session.foreign_key_checks',
            'foreign key checks are disabled, so every declared key is unenforced and DB-012 ‡, DB-013 and DB-030 ‡ '
            .'hold only on paper',
        )];
    }

    /**
     * @return list<SchemaViolation>
     */
    private function geographyViolations(string $statement, string $subject, string $identifier): array
    {
        $tokens = SchemaConventions::geographicTokensIn($identifier);

        if ($tokens === []) {
            return [];
        }

        return [new SchemaViolation(
            $statement,
            $subject,
            sprintf(
                'the identifier incorporates a geography (%s); the schema is expressed independently of any city, corridor, region or market',
                implode(', ', $tokens),
            ),
        )];
    }

    /**
     * `DB-038` ‡: every value is bound; no statement is built by concatenation.
     *
     * @param  list<string>  $bindings
     * @return list<array<string, scalar|null>>
     */
    private function rows(string $query, array $bindings): array
    {
        /** @var list<array<string, scalar|null>> $rows */
        $rows = array_map(
            static fn (object $row): array => (array) $row,
            $this->connection->select($query, $bindings),
        );

        return $rows;
    }

    private function schemaName(): string
    {
        $rows = $this->rows('SELECT DATABASE() AS name', []);

        return (string) ($rows[0]['name'] ?? '');
    }
}
