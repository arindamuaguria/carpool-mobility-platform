<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionUnionType;
use SplFileInfo;

/**
 * `CMP-IMP-037`, the half of it that does not need an aggregate.
 *
 * `BE-037`: *"Repository interfaces shall be declared in `Domain` and shall
 * return domain objects."* `BADR-05` is behind it — the ORM is an infrastructure
 * detail, and a repository is the seam that keeps it there.
 *
 * ## What this covers, and what CMP-IMP-037 still owes
 *
 * The work item asks for repository contracts, their infrastructure
 * implementations, and no ORM leakage. **The contracts are per-aggregate**, and
 * `BE-017` fixes nine aggregates of which none is built — so there is no
 * `RideRepository` to write, and writing one for an aggregate that does not exist
 * would be inventing the aggregate.
 *
 * What can land now is the rule, and landing it now is worth more than landing it
 * later: the first repository someone writes is checked by a rule that was
 * already there, rather than by a reviewer noticing. One contract exists to run
 * it on — `StateModelRepository`, which the state machine engine needs — so the
 * rule is not vacuous.
 *
 * ## Why return types, when rule 3 already bans the ORM
 *
 * `StructuralRulesTest` rule 3 keeps Eloquent out of every namespace but three.
 * That stops the ORM appearing in a Domain contract; it does not stop a contract
 * returning an `array` of rows, which is the same leak wearing plainer clothes —
 * the caller then knows the shape of a table. `BE-037`'s *"shall return domain
 * objects"* is the stronger requirement, and it is checkable by reflection.
 */
final class RepositoryRulesTest extends TestCase
{
    use ReadsMethods;

    /**
     * Types a repository may return besides a domain object.
     *
     * `bool` for an existence question, `void` for a write, `int` for a count.
     * `array` is **not** here: an array of rows is the ORM leaking through a
     * contract that promised not to leak it, and an array of domain objects
     * should be expressed as a collection type in the Domain when one is needed.
     *
     * @var list<string>
     */
    private const PERMITTED_SCALARS = ['void', 'bool', 'int', 'string', 'null'];

    public function test_every_repository_contract_is_declared_in_the_domain(): void
    {
        // BE-037 / BADR-05. A contract declared in Infrastructure would be the
        // Domain depending on Infrastructure to describe its own storage needs.
        $offenders = [];

        foreach (self::sourceFiles() as $relative => $contents) {
            if (! self::declaresARepositoryContract($relative, $contents)) {
                continue;
            }

            if (! str_starts_with($relative, 'src/Domain/')) {
                $offenders[] = $relative;
            }
        }

        self::assertSame([], $offenders, 'BE-037: a repository interface is declared in Domain.');
    }

    public function test_every_repository_contract_returns_domain_objects(): void
    {
        // BE-037's second half, and the one rule 3 does not already cover.
        $offenders = [];
        $checked = 0;

        foreach (self::repositoryContracts() as $contract) {
            foreach ((new ReflectionClass($contract))->getMethods() as $method) {
                $checked++;

                foreach (self::returnTypeNames($method->getReturnType()) as $type) {
                    if (in_array($type, self::PERMITTED_SCALARS, true)) {
                        continue;
                    }

                    if (str_starts_with($type, 'Cmp\Domain\\')) {
                        continue;
                    }

                    $offenders[] = $contract.'::'.$method->getName().'(): '.$type;
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            'BE-037: a repository returns domain objects, never a row, an array of rows or a framework type.',
        );

        // A rule with nothing to run on proves nothing. StateModelRepository is
        // what keeps this honest until BE-017's aggregates arrive.
        self::assertGreaterThan(0, $checked);
    }

    public function test_every_repository_implementation_is_infrastructure(): void
    {
        // BADR-05: the ORM is an infrastructure detail, so the class that knows
        // about storage lives where storage lives. Deptrac enforces the direction;
        // this enforces the placement, which the direction alone permits to drift
        // (an implementation in Application would not violate a layer rule).
        $offenders = [];

        foreach (self::repositoryContracts() as $contract) {
            foreach (self::sourceFiles() as $relative => $contents) {
                $short = substr((string) strrchr($contract, '\\'), 1);

                if (! str_contains($contents, 'implements '.$short)) {
                    continue;
                }

                if (! str_starts_with($relative, 'src/Infrastructure/Persistence/')) {
                    $offenders[] = $relative.' implements '.$short;
                }
            }
        }

        self::assertSame([], $offenders, 'BADR-05: a repository implementation lives with the persistence it hides.');
    }

    public function test_no_repository_contract_names_a_framework_type(): void
    {
        // BE-002, stated for repositories because they are the contract most
        // tempted to leak one — a connection, a builder, a collection.
        foreach (self::sourceFiles() as $relative => $contents) {
            if (! self::declaresARepositoryContract($relative, $contents)) {
                continue;
            }

            foreach (['Illuminate\\', 'Symfony\\', 'Doctrine\\'] as $framework) {
                self::assertStringNotContainsString(
                    $framework,
                    $contents,
                    $relative.' names a framework type in a Domain contract.',
                );
            }
        }
    }

    /**
     * `FRD-FR-005` ‡ — *"The system shall assign each account a unique and
     * **persistent** identifier that is **never reused**."*
     *
     * Uniqueness is a database constraint and `DB-022` ‡ declares it. **Persistence
     * is not a constraint at all**: nothing stopped a repository from writing a new
     * external identifier over a row that already carried one, and no constraint
     * would have objected.
     *
     * `DB-023` ‡ makes the identifier random, so such a rewrite would collide with
     * nothing and fail nowhere. It would simply stop being the same identifier — and
     * the old value, now belonging to no row, would be free to appear again on
     * another, which is exactly the reuse the statement forbids. Every reference a
     * client, an operator or an evidential record held would quietly point at a
     * different account.
     *
     * So the checkable form of the statement is: **nothing assigns one twice**.
     * Reading *by* an external identifier is what it is for — `WHERE external_id = ?`
     * appears in four repositories — so only the `SET` clause is examined.
     *
     * Scoped to `UPDATE` rather than `INSERT`: assignment at creation is the
     * assignment `FRD-FR-005` ‡ requires.
     */
    public function test_no_statement_rewrites_an_external_identifier(): void
    {
        $offenders = [];
        $examined = 0;

        foreach (self::sourceFiles() as $relative => $contents) {
            foreach (self::assignmentClausesIn(self::codeOf($contents)) as $clause) {
                $examined++;

                if (str_contains($clause, 'external_id')) {
                    $offenders[] = $relative.' → SET '.$clause;
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            'FRD-FR-005 ‡: an account identifier is persistent and never reused, so nothing assigns one over a '
            .'row that already carries it. DB-023 ‡ means such a rewrite would collide with nothing and fail '
            .'nowhere — it would only stop being the same identifier.',
        );

        // A rule that examined no UPDATE proves nothing. Five statements exist.
        self::assertGreaterThan(0, $examined, 'No UPDATE statement was examined, so the rule ran on nothing.');
    }

    /**
     * `TC-041` / `TC-024` ‡ — the detector fires on the shape the rule exists for,
     * and not on the prose that describes it.
     *
     * The second case is the one that matters: a detector that read the whole
     * statement would flag `WHERE external_id = ?` in every repository in the
     * platform, and the quickest way to quieten it would be to stop looking at
     * repositories.
     */
    public function test_the_assignment_detector_reads_the_set_clause_and_not_the_predicate(): void
    {
        self::assertSame(
            ['external_id = ?, updated_at = ?'],
            self::assignmentClausesIn('$c->update("UPDATE t SET external_id = ?, updated_at = ? WHERE id = ?");'),
        );

        self::assertSame(
            ['verification_standing = ?, updated_at = ?'],
            self::assignmentClausesIn(
                '$c->update("UPDATE t SET verification_standing = ?, updated_at = ? WHERE external_id = ?");',
            ),
        );

        // Concatenation is how every statement here is written, and the WHERE
        // arrives in a later literal than the SET.
        $concatenated = "\$c->update('UPDATE '.self::TABLE.' SET routed_at = ?'.' WHERE external_id = ?');";
        $clauses = self::assignmentClausesIn($concatenated);

        self::assertCount(1, $clauses);
        self::assertStringContainsString('routed_at', $clauses[0]);
        self::assertStringNotContainsString(
            'external_id',
            $clauses[0],
            'The predicate is not part of the assignment, however the statement is spelled.',
        );

        // A read of the identifier, and a grant naming the privilege: neither is an
        // assignment.
        self::assertSame([], self::assignmentClausesIn('$c->select("SELECT external_id FROM op_users WHERE id = ?");'));
        self::assertSame([], self::assignmentClausesIn("return 'SELECT, INSERT, UPDATE, DELETE';"));

        // The prohibition written about rather than committed: comments are gone
        // before the detector sees a file — the correction `TC-037` ‡ rule 11
        // needed.
        self::assertSame(
            [],
            self::assignmentClausesIn(self::codeOf('<?php // Nothing issues UPDATE t SET external_id = ? — FRD-FR-005 ‡.')),
        );
    }

    /**
     * Every `SET …` clause of every `UPDATE` in a file, as written.
     *
     * Bounded at `WHERE` so that a predicate naming a column is never mistaken for
     * an assignment to it, and at the end of the PHP statement otherwise — which
     * over-captures an `UPDATE` carrying no `WHERE` at all. That is the safe
     * direction: such a statement rewrites every row in its table, and a rule that
     * errs towards flagging it is not a rule that wants loosening.
     *
     * @return list<string>
     */
    private static function assignmentClausesIn(string $code): array
    {
        $clauses = [];

        foreach (explode(';', $code) as $statement) {
            preg_match_all('/\bUPDATE\s.*?\sSET\s(.*?)(?:\sWHERE\s|\z)/is', $statement, $matches);

            /** @var list<string> $found */
            $found = $matches[1];

            foreach ($found as $clause) {
                $clauses[] = trim($clause);
            }
        }

        return $clauses;
    }

    /**
     * @return list<class-string>
     */
    private static function repositoryContracts(): array
    {
        $contracts = [];

        foreach (self::sourceFiles() as $relative => $contents) {
            if (! self::declaresARepositoryContract($relative, $contents)) {
                continue;
            }

            $namespace = self::namespaceOf($contents);
            $name = basename($relative, '.php');

            if ($namespace === null) {
                continue;
            }

            /** @var class-string $contract */
            $contract = $namespace.'\\'.$name;

            if (interface_exists($contract)) {
                $contracts[] = $contract;
            }
        }

        return $contracts;
    }

    /**
     * A repository contract, recognised the way a reader recognises one: an
     * interface whose name ends in `Repository`.
     *
     * `TC-041` — narrow. A class named `…Repository` that is not an interface is
     * an implementation, and a contract named something else is not a repository
     * by this project's own naming.
     */
    private static function declaresARepositoryContract(string $relative, string $contents): bool
    {
        return str_ends_with($relative, 'Repository.php')
            && preg_match('/^interface\s+\w+Repository\b/m', $contents) === 1;
    }

    /**
     * @return list<string>
     */
    private static function returnTypeNames(?\ReflectionType $type): array
    {
        if ($type instanceof ReflectionNamedType) {
            return [$type->getName()];
        }

        if ($type instanceof ReflectionUnionType) {
            $names = [];

            foreach ($type->getTypes() as $member) {
                $names = [...$names, ...self::returnTypeNames($member)];
            }

            return $names;
        }

        // An untyped return is itself a leak: nothing constrains what comes back.
        return ['mixed'];
    }

    private static function namespaceOf(string $contents): ?string
    {
        return preg_match('/^namespace\s+([^;]+);/m', $contents, $matches) === 1 ? trim($matches[1]) : null;
    }

    /**
     * @return array<string, string> relative path => contents
     */
    private static function sourceFiles(): array
    {
        $root = dirname(__DIR__, 2).'/';
        $files = [];

        /** @var iterable<SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'src', RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            self::assertIsString($contents);

            $files[str_replace('\\', '/', substr($file->getPathname(), strlen($root)))] = $contents;
        }

        return $files;
    }
}
