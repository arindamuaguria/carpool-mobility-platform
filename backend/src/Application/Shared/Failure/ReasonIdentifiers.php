<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Failure;

use Cmp\Application\Shared\Authorisation\AuthorisationRefusal;
use Cmp\Application\Shared\Idempotency\IdempotencyRefusal;
use Cmp\Domain\Shared\StateMachine\StateMachineRefusal;

/**
 * `CMP-IMP-464` — every refusal reason the interface may serve, in one register.
 *
 * `AADR-14` decided that refusal reasons are **identified, not free text**, and
 * `API-081` ‡ makes the identifier stable and machine-readable. Stability is not
 * a property of a string; it is a property of a list somebody maintains.
 * `API-084` — *"a reason identifier shall not be removed or repurposed within a
 * version"* — has nothing to hold it to unless the set a version serves is
 * written down, and `API-085` makes **adding** one non-breaking, so the list is
 * expected to grow and never to shrink.
 *
 * `API-083` is why the identifier matters more than the text: the client presents
 * its own localised text keyed by the identifier, and falls back to the default
 * only for an identifier it does not recognise. A reason whose identifier changed
 * would silently lose every translation of it.
 *
 * ## Why this is in the application layer
 *
 * The identifiers come from three enums, and one of them —
 * {@see StateMachineRefusal} — is a Domain type. `BE-002` keeps Domain types out
 * of `Interface`, so a register in the adapter could not name it. This layer may
 * see all three, which makes it the only place the whole set is visible at once.
 *
 * ## Four, and one of them is two cases
 *
 * `StateMachineRefusal` maps `TransitionNotDeclared` **and** `InvariantForbidsIt`
 * to one identifier, deliberately: `API-086` ‡ forbids a refusal reason stating
 * platform state the caller is not entitled to, and *"the model does not declare
 * this"* against *"an invariant forbade it"* would tell a caller which. The
 * distinction is kept internally, where `TransitionRefused::detail()` carries it
 * for an operator.
 *
 * The register grows with the aggregates. `BE-017` fixes nine and none is built,
 * so four is what a platform with no business operations can refuse: an
 * authorisation, a reused idempotency key, and two lifecycle refusals.
 */
final class ReasonIdentifiers
{
    /**
     * Every identifier this version may serve, with what raises it.
     *
     * @return array<string, string> identifier => the condition it names
     */
    public static function all(): array
    {
        return [
            AuthorisationRefusal::NotAvailableToYou->value => 'The actor may not perform the operation. SEC-069 ‡ and API-094 ‡ make absence and '
                .'non-entitlement indistinguishable, so this one identifier covers both and there is '
                .'deliberately no second.',

            IdempotencyRefusal::KeyReusedWithDifferentContent->value => 'API-063 ‡: a key already used for a different request. The original outcome stands and '
                .'is not overwritten.',

            StateMachineRefusal::StateNotDeclared->identifier() => 'SRS-REQ-158: the record is in a state the declared model does not know, so no transition '
                .'from it can be evaluated.',

            StateMachineRefusal::TransitionNotDeclared->identifier() => 'BE-176 ‡ or BE-177 ‡: the transition is not declared, or a coded invariant forbids it. '
                .'One identifier for both, because API-086 ‡ forbids telling a caller which.',
        ];
    }

    /**
     * @return list<string>
     */
    public static function identifiers(): array
    {
        return array_keys(self::all());
    }

    public static function isRegistered(string $identifier): bool
    {
        return array_key_exists($identifier, self::all());
    }
}
