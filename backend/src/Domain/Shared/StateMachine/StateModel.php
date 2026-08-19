<?php

declare(strict_types=1);

namespace Cmp\Domain\Shared\StateMachine;

use InvalidArgumentException;

/**
 * A declared lifecycle: its permitted states and the transitions between them.
 *
 * `BADR-13`: *"The engine is code; the models are configuration."* This class
 * holds a model that was declared elsewhere — `ARCH-037` puts the definitions in
 * policy configuration, `DB-155` puts them in `cfg_state_models`, and `DB-039`
 * keeps them out of a database `ENUM` because their value sets are business
 * decisions.
 *
 * `SRS-REQ-157` is the reason any of this exists: **six of the ten state models
 * are undefined because a business decision is open** (CMP-DOC-06 §7.2), and
 * making the permitted values and transitions configurable means those six land
 * as configuration rather than as six rounds of redevelopment.
 *
 * A model declares what is **permitted**. It cannot declare what is required:
 * `BE-177` ‡ and `ARCH-038` put the invariants that must hold under any model in
 * code, and a permissive definition must not admit a prohibited outcome. See
 * {@see StateMachine}.
 */
final class StateModel
{
    /** @var list<string> */
    private readonly array $states;

    /** @var list<StateTransition> */
    private readonly array $transitions;

    /**
     * @param  list<string>  $states
     * @param  list<StateTransition>  $transitions
     */
    private function __construct(
        private readonly string $name,
        array $states,
        array $transitions,
    ) {
        $this->states = $states;
        $this->transitions = $transitions;
    }

    /**
     * @param  list<string>  $states
     * @param  list<StateTransition>  $transitions
     */
    public static function of(string $name, array $states, array $transitions): self
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('A state model must be named.');
        }

        if ($states === []) {
            throw new InvalidArgumentException(sprintf(
                'SRS-REQ-158: "%s" declares no states, so every value would be unconfigured and every '
                .'transition refused. An empty model is a mistake, not a strict one.',
                $name,
            ));
        }

        foreach ($transitions as $transition) {
            // A transition naming a state the model does not permit would let
            // SRS-REQ-158 be bypassed by the definition itself.
            foreach ([$transition->from(), $transition->to()] as $state) {
                if (! in_array($state, $states, true)) {
                    throw new InvalidArgumentException(sprintf(
                        'SRS-REQ-158: "%s" declares the transition %s, but "%s" is not one of its permitted states.',
                        $name,
                        $transition->describe(),
                        $state,
                    ));
                }
            }
        }

        return new self($name, array_values(array_unique($states)), array_values($transitions));
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return list<string>
     */
    public function states(): array
    {
        return $this->states;
    }

    /**
     * @return list<StateTransition>
     */
    public function transitions(): array
    {
        return $this->transitions;
    }

    public function permits(string $state): bool
    {
        return in_array($state, $this->states, true);
    }

    /**
     * The declared destination, or null where the transition is undeclared.
     *
     * `BE-176` ‡ / `SRS-REQ-158`: an undeclared transition is **refused**. The
     * engine does the refusing; this only reports what was declared.
     */
    public function destinationOf(string $from, string $trigger): ?string
    {
        foreach ($this->transitions as $transition) {
            if ($transition->matches($from, $trigger)) {
                return $transition->to();
            }
        }

        return null;
    }
}
