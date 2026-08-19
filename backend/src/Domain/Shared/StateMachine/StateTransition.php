<?php

declare(strict_types=1);

namespace Cmp\Domain\Shared\StateMachine;

use InvalidArgumentException;

/**
 * One declared transition: from a state, on a trigger, to a state.
 *
 * `ARCH-037`: definitions are read from policy configuration. `BADR-13`: *"The
 * engine is code; the models are configuration."* This is a piece of a model,
 * so it carries no rule — only what was declared.
 */
final class StateTransition
{
    private function __construct(
        private readonly string $from,
        private readonly string $trigger,
        private readonly string $to,
    ) {}

    public static function of(string $from, string $trigger, string $to): self
    {
        foreach (['from' => $from, 'trigger' => $trigger, 'to' => $to] as $part => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException(sprintf('A transition must name its %s.', $part));
            }
        }

        return new self($from, $trigger, $to);
    }

    public function from(): string
    {
        return $this->from;
    }

    public function trigger(): string
    {
        return $this->trigger;
    }

    public function to(): string
    {
        return $this->to;
    }

    public function matches(string $from, string $trigger): bool
    {
        return $this->from === $from && $this->trigger === $trigger;
    }

    public function describe(): string
    {
        return sprintf('%s --%s--> %s', $this->from, $this->trigger, $this->to);
    }
}
