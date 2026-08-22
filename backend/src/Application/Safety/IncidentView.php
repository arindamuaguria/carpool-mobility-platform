<?php

declare(strict_types=1);

namespace Cmp\Application\Safety;

use Cmp\Domain\Safety\ContextElement;
use Cmp\Domain\Safety\SafetyIncident;

/**
 * A safety incident as anything outside the Domain may see it.
 *
 * `BE-002` keeps a Domain type out of the `Interface` layer, and `API-050` ‡
 * puts what a representation discloses outside the adapter. Both meet here.
 *
 * ## What it says, and what it is careful not to
 *
 * The reference, when it was raised, whether it has reached the operator queue,
 * and the standing of each context element. `FRD-FR-187` ‡'s marking is the
 * substance of it — a raiser reading their own incident can see that the
 * platform holds a record and what it does not know about the circumstances.
 *
 * **Nothing here states or implies a response.** `NFR-137` forbids implying a
 * protection the platform does not provide; `UX-165` ‡ forbids stating that help
 * is on the way; CMP-DOC-10 §12.4 says of dispatch that *"the interface states
 * nothing"* about it, because `GAP-004` is open. So `routed` says the record
 * reached a queue and says nothing about anybody acting on it, and there is no
 * status, no ETA and no assurance of any kind. `SafetyIncidentEndpointTest`
 * checks the served bytes against that.
 *
 * The outcome is **not** disclosed either. Nothing writes one (`BAD-DEC-011`),
 * and when something does it will be the response protocol's — `API-171` ‡
 * already keeps closure away from the client interface.
 */
final class IncidentView
{
    /**
     * @param  array<string, string>  $context
     */
    private function __construct(
        private readonly string $id,
        private readonly string $raisedAt,
        private readonly bool $routed,
        private readonly array $context,
    ) {}

    public static function of(SafetyIncident $incident): self
    {
        $context = [];

        foreach (ContextElement::cases() as $element) {
            $context[$element->value] = $incident->context()->standingOf($element)->value;
        }

        return new self(
            $incident->reference()->toString(),
            $incident->raisedAt()->toIso8601(),
            $incident->isRouted(),
            $context,
        );
    }

    /**
     * @return array{id: string, raised_at: string, routed: bool, context: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'raised_at' => $this->raisedAt,
            'routed' => $this->routed,
            'context' => $this->context,
        ];
    }
}
