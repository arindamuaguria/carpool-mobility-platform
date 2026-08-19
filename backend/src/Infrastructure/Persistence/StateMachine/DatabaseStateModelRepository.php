<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Persistence\StateMachine;

use Cmp\Domain\Shared\StateMachine\StateModel;
use Cmp\Domain\Shared\StateMachine\StateModelNotDeclared;
use Cmp\Domain\Shared\StateMachine\StateModelRepository;
use Cmp\Domain\Shared\StateMachine\StateTransition;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use JsonException;

/**
 * Reads declared state models from `cfg_state_models`.
 *
 * The definition in force is the **highest version** (`DB-152`), read whole and
 * built into a {@see StateModel} — which validates it: a transition naming a
 * state the model does not permit is refused at construction, so a definition
 * that could bypass `SRS-REQ-158` never reaches the engine.
 *
 * `BE-169` applies to a model as to any other policy value — it is read on every
 * transition — so a model read once is memoised for the rest of the process, and
 * {@see forget()} clears it.
 */
final class DatabaseStateModelRepository implements StateModelRepository
{
    public const TABLE = 'cfg_state_models';

    /** @var array<string, StateModel|null> */
    private array $memoised = [];

    public function __construct(private readonly ConnectionInterface $connection) {}

    public function modelNamed(string $name): StateModel
    {
        return $this->resolve($name) ?? throw StateModelNotDeclared::named($name);
    }

    public function hasModelNamed(string $name): bool
    {
        return $this->resolve($name) !== null;
    }

    public function forget(string $name): void
    {
        unset($this->memoised[$name]);
    }

    private function resolve(string $name): ?StateModel
    {
        if (array_key_exists($name, $this->memoised)) {
            return $this->memoised[$name];
        }

        /** @var list<object{definition: string}> $rows */
        $rows = $this->connection->select(
            'SELECT definition FROM '.self::TABLE.' WHERE model_name = ? ORDER BY version DESC LIMIT 1',
            [$name],
        );

        return $this->memoised[$name] = $rows === [] ? null : $this->build($name, $rows[0]->definition);
    }

    /**
     * `BE-174` / `ARCH-148`: a definition that does not satisfy its constraints is
     * rejected rather than applied. A stored definition that has since become
     * unreadable is rejected here for the same reason — the alternative is an
     * engine running on a model nobody can state.
     */
    private function build(string $name, string $definition): StateModel
    {
        try {
            /** @var array{states?: list<string>, transitions?: list<array{from: string, trigger: string, to: string}>} $decoded */
            $decoded = json_decode($definition, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                sprintf('The stored definition of "%s" is not readable.', $name),
                previous: $exception,
            );
        }

        $transitions = array_map(
            static fn (array $transition): StateTransition => StateTransition::of(
                $transition['from'],
                $transition['trigger'],
                $transition['to'],
            ),
            $decoded['transitions'] ?? [],
        );

        return StateModel::of($name, $decoded['states'] ?? [], array_values($transitions));
    }
}
