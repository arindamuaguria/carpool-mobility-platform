<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Persistence\Safety;

use Cmp\Domain\Safety\ContextElement;
use Cmp\Domain\Safety\ContextStanding;
use Cmp\Domain\Safety\IncidentContext;
use Cmp\Domain\Safety\IncidentOutcome;
use Cmp\Domain\Safety\IncidentReference;
use Cmp\Domain\Safety\SafetyIncident;
use Cmp\Domain\Safety\SafetyIncidentRepository;
use Cmp\Domain\Safety\UnroutedIncidents;
use Cmp\Domain\Shared\Time\Clock;
use Cmp\Domain\Shared\Time\Instant;
use Cmp\Domain\User\UserReference;
use Cmp\Infrastructure\Persistence\User\DatabaseUserRepository;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * `op_safety_incidents`, behind the Domain's contract.
 *
 * `BE-087` and `DB-038` ‡/`SADR-09`: the query layer, never the ORM, every value
 * bound. `BE-047` ‡: no transaction opened here.
 *
 * `BE-192` ‡ / `BE-193` ‡ hold visibly — the only things this class touches are
 * its own table, the users table it joins for identity, and the clock. No
 * payment, search, matching, rating or projection component appears, and
 * `SafetyDependencyRulesTest` asserts that rather than trusting it.
 *
 * ## An unrecognised stored standing raises
 *
 * `DB-039`/`DB-045`'s discipline: the standing columns are strings so the value
 * set can change without a migration, and `DB-214` ‡ forbids a `CHECK` encoding
 * the rule. So the enum is the only thing that can refuse an unrecognised value,
 * and it refuses on the way in — an incident whose trip standing is neither
 * recorded, absent nor unavailable is one `DB-078` ‡ cannot answer for, and
 * reading it as though it could would be worse than failing.
 */
final class DatabaseSafetyIncidentRepository implements SafetyIncidentRepository
{
    public const TABLE = 'op_safety_incidents';

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly Clock $clock,
    ) {}

    public function save(SafetyIncident $incident): void
    {
        $existing = $this->connection->select(
            'SELECT id FROM '.self::TABLE.' WHERE external_id = ?',
            [$incident->reference()->toString()],
        );

        $now = $this->clock->now()->toDatabaseString();

        if ($existing !== []) {
            $this->connection->update(
                'UPDATE '.self::TABLE.' SET routed_at = ?, closed_at = ?, outcome = ?, updated_at = ?'
                .' WHERE external_id = ?',
                [
                    $incident->routedAt()?->toDatabaseString(),
                    $incident->closedAt()?->toDatabaseString(),
                    $incident->outcome()?->toString(),
                    $now,
                    $incident->reference()->toString(),
                ],
            );

            return;
        }

        $context = $incident->context();

        $this->connection->insert(
            'INSERT INTO '.self::TABLE
            .' (external_id, user_id, raised_at, trip_standing, vehicle_standing,'
            .' co_travellers_standing, location_standing, routed_at, closed_at, outcome, created_at, updated_at)'
            .' SELECT ?, u.id, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?'
            .' FROM '.DatabaseUserRepository::TABLE.' u WHERE u.external_id = ?',
            [
                $incident->reference()->toString(),
                $incident->raisedAt()->toDatabaseString(),
                $context->standingOf(ContextElement::Trip)->value,
                $context->standingOf(ContextElement::Vehicle)->value,
                $context->standingOf(ContextElement::CoTravellers)->value,
                $context->standingOf(ContextElement::Location)->value,
                $incident->routedAt()?->toDatabaseString(),
                $now,
                $now,
                $incident->raisedBy()->toString(),
            ],
        );
    }

    public function forReference(IncidentReference $reference): ?SafetyIncident
    {
        $rows = $this->selectIncidents('c.external_id = ?', [$reference->toString()], 1);

        return $rows[0] ?? null;
    }

    public function unrouted(int $limit): UnroutedIncidents
    {
        // The index op_safety_incidents_routed_at_raised_at_index serves exactly
        // this, and FRD-FR-190 ‡ is the only reason either exists.
        return UnroutedIncidents::of($limit, ...$this->selectIncidents('c.routed_at IS NULL', [], $limit));
    }

    public function wasRaisedBy(IncidentReference $reference, UserReference $user): bool
    {
        $rows = $this->connection->select(
            'SELECT 1 AS present FROM '.self::TABLE.' c'
            .' INNER JOIN '.DatabaseUserRepository::TABLE.' u ON u.id = c.user_id'
            .' WHERE c.external_id = ? AND u.external_id = ?',
            [$reference->toString(), $user->toString()],
        );

        return $rows !== [];
    }

    /**
     * @param  list<scalar>  $bindings
     * @return list<SafetyIncident>
     */
    private function selectIncidents(string $where, array $bindings, int $limit): array
    {
        /** @var list<object{external_id: string, raiser: string, raised_at: string, trip_standing: string, vehicle_standing: string, co_travellers_standing: string, location_standing: string, routed_at: ?string, closed_at: ?string, outcome: ?string}> $rows */
        $rows = $this->connection->select(
            'SELECT c.external_id, u.external_id AS raiser, c.raised_at, c.trip_standing, c.vehicle_standing,'
            .' c.co_travellers_standing, c.location_standing, c.routed_at, c.closed_at, c.outcome'
            .' FROM '.self::TABLE.' c'
            .' INNER JOIN '.DatabaseUserRepository::TABLE.' u ON u.id = c.user_id'
            .' WHERE '.$where
            // Oldest first: the earliest signal is the one an operator draining a
            // backlog most needs, and DB-024 ‡ keeps the internal key out of the
            // ordering as well as out of the response.
            .' ORDER BY c.raised_at ASC, c.external_id ASC'
            .' LIMIT '.max(1, $limit),
            $bindings,
        );

        return array_map(fn (object $row): SafetyIncident => SafetyIncident::reconstitute(
            IncidentReference::fromString($row->external_id),
            UserReference::fromString($row->raiser),
            Instant::fromString($row->raised_at),
            IncidentContext::of([
                ContextElement::Trip->value => $this->standing($row->trip_standing),
                ContextElement::Vehicle->value => $this->standing($row->vehicle_standing),
                ContextElement::CoTravellers->value => $this->standing($row->co_travellers_standing),
                ContextElement::Location->value => $this->standing($row->location_standing),
            ]),
            $row->routed_at === null ? null : Instant::fromString($row->routed_at),
            $row->closed_at === null ? null : Instant::fromString($row->closed_at),
            $row->outcome === null ? null : IncidentOutcome::fromString($row->outcome),
        ), $rows);
    }

    private function standing(string $stored): ContextStanding
    {
        $standing = ContextStanding::tryFrom($stored);

        if ($standing === null) {
            throw new RuntimeException(sprintf(
                'DB-078 ‡: "%s" is not a context standing. An incident whose context the platform cannot '
                .'interpret is one it cannot answer for.',
                $stored,
            ));
        }

        return $standing;
    }
}
