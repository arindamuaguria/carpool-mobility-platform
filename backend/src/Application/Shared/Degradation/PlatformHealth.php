<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Degradation;

use Cmp\Domain\Shared\Degradation\CapabilityStanding;
use Cmp\Domain\Shared\Degradation\Support;

/**
 * What the platform can currently do, and what it cannot.
 *
 * `BE-203`: *"health indication distinguishing platform from dependencies."* The
 * distinction is the point — an operator paged at three in the morning needs to
 * know whether to look at the platform or at somebody else's service, and a
 * single green-or-red light answers neither question.
 *
 * `FRD-FR-257` requires the affected actor to be told *"what is unavailable **and
 * what remains available**"*, and `API-091` requires that state to be propagated
 * *"so that the client can disclose what remains available"*. Both halves are
 * here: {@see standings()} lists **every** declared capability with how it stands,
 * so a reader gets the second half by reading the same list rather than by
 * subtracting the first half from something it has to know separately.
 */
final class PlatformHealth
{
    /**
     * @param  array<string, CapabilityStanding>  $standings  every declared capability, by name
     * @param  list<Support>  $missing  what is not answering
     */
    private function __construct(
        private readonly array $standings,
        private readonly array $missing,
    ) {}

    /**
     * @param  array<string, CapabilityStanding>  $standings
     * @param  list<Support>  $missing
     */
    public static function of(array $standings, array $missing): self
    {
        return new self($standings, array_values($missing));
    }

    /**
     * Every declared capability and how it stands — `FRD-FR-257`'s both halves.
     *
     * @return array<string, CapabilityStanding>
     */
    public function standings(): array
    {
        return $this->standings;
    }

    /**
     * What is not answering (`FRD-FR-255`).
     *
     * @return list<Support>
     */
    public function missing(): array
    {
        return $this->missing;
    }

    /**
     * What is not answering, in a shape an adapter can serialise.
     *
     * `BE-002` keeps the `Interface` layer from naming a `Domain` type, and
     * `Support` is one — a controller that mapped over {@see missing()} would be
     * reaching two layers inward, and the layer graph caught exactly that when
     * this surface was first written.
     *
     * `API-050` ‡ wants it here anyway: *what a representation discloses* is not
     * an adapter's decision. Two fields leave, both already the platform's own
     * terms, and no provider appears in either (`API-090`).
     *
     * @return list<array{kind: string, name: string}>
     */
    public function missingDescribed(): array
    {
        return array_map(
            static fn (Support $support): array => [
                'kind' => $support->kind()->value,
                'name' => $support->name(),
            ],
            $this->missing,
        );
    }

    /**
     * Every declared capability and its standing, as strings.
     *
     * Here for the same two reasons as {@see missingDescribed()}: `CapabilityStanding`
     * is a `Domain` enum, and `API-050` ‡ reserves the disclosure decision for
     * this layer.
     *
     * @return array<string, string>
     */
    public function standingsDescribed(): array
    {
        return array_map(
            static fn (CapabilityStanding $standing): string => $standing->value,
            $this->standings,
        );
    }

    /**
     * Whether the platform itself is answering.
     *
     * **Always true where this object exists at all**, and that is the honest
     * answer rather than a placeholder: computing any of the above required the
     * platform to run, so a `PlatformHealth` that could report otherwise would be
     * reporting on something that never produced it.
     *
     * `BE-203`'s distinction is therefore between *this*, which is a fact about
     * the responding instance, and {@see missing()}, which is about everything
     * else. A caller receiving no response at all is the other case, and no
     * response can convey it.
     */
    public function platformIsAnswering(): bool
    {
        return true;
    }

    /**
     * Whether every declared capability may be offered (`FRD-FR-256` ‡).
     */
    public function isFullyAvailable(): bool
    {
        foreach ($this->standings as $standing) {
            if (! $standing->mayBeOffered()) {
                return false;
            }
        }

        return true;
    }

    /**
     * The capabilities a client may currently use.
     *
     * @return list<string>
     */
    public function offered(): array
    {
        $offered = [];

        foreach ($this->standings as $name => $standing) {
            if ($standing->mayBeOffered()) {
                $offered[] = $name;
            }
        }

        return $offered;
    }
}
