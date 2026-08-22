<?php

declare(strict_types=1);

namespace Cmp\Domain\Safety;

use InvalidArgumentException;

/**
 * What the platform knows about an incident's circumstances, and what it
 * admits it does not.
 *
 * `FRD-FR-187` ‡: *"record a safety incident even where part of its context is
 * unavailable, **marking the missing context as unavailable**."* This is the
 * marking, and it is a **closed register**: a standing for every case of {@see
 * ContextElement}, never a subset.
 *
 * ## Why it cannot be built incomplete
 *
 * {@see of()} refuses a map missing an element. The alternative — defaulting an
 * absent key to `unavailable` — would be the same shape as the bug `DB-078` ‡
 * exists to prevent: an element nobody considered would present as one the
 * platform tried and failed to obtain, and an operator would read *"we do not
 * know"* where the truth is *"nobody wrote that part"*.
 *
 * ## Nothing here refuses an incident
 *
 * `DB-077` ‡ / `API-164` ‡ / `BD-04`: a signal is never lost to a validation
 * failure on context. So this type cannot be constructed *wrongly by a caller* —
 * only by a programmer, and {@see unavailable()} is what the raising path
 * actually uses. There is no path on which a context problem reaches a user as a
 * refusal.
 */
final class IncidentContext
{
    /**
     * @param  array<string, ContextStanding>  $standings  keyed by ContextElement value
     */
    private function __construct(private readonly array $standings) {}

    /**
     * @param  array<string, ContextStanding>  $standings  keyed by ContextElement value
     */
    public static function of(array $standings): self
    {
        foreach (ContextElement::cases() as $element) {
            if (! isset($standings[$element->value])) {
                throw new InvalidArgumentException(sprintf(
                    'DB-078 ‡: every context element carries a standing, and %s has none. An element with no '
                    .'standing is indistinguishable from one the platform tried and failed to obtain.',
                    $element->value,
                ));
            }
        }

        $known = [];

        foreach (ContextElement::cases() as $element) {
            $known[$element->value] = $standings[$element->value];
        }

        return new self($known);
    }

    /**
     * Everything unknown, which is what the platform can honestly say today.
     *
     * Not a placeholder and not a default: `Trip`, `Vehicle` and the aggregates
     * behind co-travellers are unbuilt or blocked, and location is FEAT-019's.
     * `ContextStanding::Unavailable` is the true answer for each, and
     * `FRD-FR-187` ‡ is the requirement that says recording it is enough.
     */
    public static function unavailable(): self
    {
        $standings = [];

        foreach (ContextElement::cases() as $element) {
            $standings[$element->value] = ContextStanding::Unavailable;
        }

        return new self($standings);
    }

    public function standingOf(ContextElement $element): ContextStanding
    {
        return $this->standings[$element->value];
    }

    /**
     * @return array<string, ContextStanding>
     */
    public function all(): array
    {
        return $this->standings;
    }

    /**
     * True where nothing about the circumstances could be obtained.
     *
     * `FRD-FR-187` ‡'s case, and an operator's first question about a record
     * they have been handed.
     */
    public function isWhollyUnavailable(): bool
    {
        foreach ($this->standings as $standing) {
            if ($standing->isKnown()) {
                return false;
            }
        }

        return true;
    }
}
