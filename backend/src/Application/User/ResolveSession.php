<?php

declare(strict_types=1);

namespace Cmp\Application\User;

use Cmp\Domain\Shared\Policy\PolicyKey;
use Cmp\Domain\Shared\Policy\PolicyStore;
use Cmp\Domain\Shared\Time\Clock;
use Cmp\Domain\User\Session;
use Cmp\Domain\User\SessionRepository;

/**
 * `CMP-IMP-055` — admits a user holding a valid existing session.
 *
 * `FRD-FR-017`: *"The system shall admit a user holding a valid existing session
 * **without re-authenticating them**."* This is the whole of that: a request
 * arrives carrying a token, and either it names a usable session or it does not.
 *
 * `SEC-042` makes it *"a hash-and-lookup against the store on every request"* —
 * every request, because `SEC-045` ‡ keeps entitlement out of the token and there
 * is therefore nothing to trust between one request and the next.
 *
 * ## The three refusals are one refusal
 *
 * `SEC-048` ‡: a terminated, expired or unknown token is refused **identically**.
 * The order below is not a precedence anybody can observe — all three raise the
 * same {@see SessionRefused} carrying the same {@see SessionRefusal} — and it
 * exists so the **internal** cause is the most specific true one, which is what
 * an operator reading `DB-044` ‡'s retained record needs.
 *
 * ## Where the bound comes from
 *
 * `SEC-039` ‡ holds the session lifetime as policy configuration, decided on
 * 2026-08-20 as twenty-four hours. It is read here, per request, rather than held
 * — `BE-170` invalidates the cache on change so no restart is required, and a
 * value captured once would make a shortened bound apply only to new sessions.
 *
 * If the value has not been set, {@see PolicyStore::read()} raises rather than
 * defaulting: `SRS-REQ-158` rejects an unconfigured value and `SRS-REQ-113`
 * forbids synthesising one. A platform whose session lifetime nobody configured
 * refuses every request, loudly, rather than choosing a lifetime for itself.
 *
 * ## What this does not do
 *
 * It does not authorise. `SEC-045` ‡ and `SEC-053` ‡ evaluate entitlement against
 * platform state on every operation, in the application layer, and a resolved
 * session is an input to that rather than a substitute for it. It also does not
 * check the account state: `SEC-051` ‡ governs **establishing** a session, and
 * whether an account suspended after establishment invalidates its live sessions
 * is not stated by any requirement — `FRD-GAP-024` owns account-state transitions
 * and is open, so nothing is inferred here.
 */
final class ResolveSession
{
    /**
     * `SEC-039` ‡ / `API-104`. Declared in `PolicyServiceProvider`, which is the
     * one place a policy key is declared (`DB-153` ‡).
     */
    public const LIFETIME_KEY = 'session.lifetime';

    public function __construct(
        private readonly SessionRepository $sessions,
        private readonly HashesSessionTokens $tokens,
        private readonly PolicyStore $policy,
        private readonly Clock $clock,
        private readonly PolicyKey $lifetime,
    ) {}

    /**
     * @throws SessionRefused where the token names no usable session
     */
    public function forToken(string $token): Session
    {
        $session = $this->sessions->forTokenHash($this->tokens->hash($token));

        if ($session === null) {
            throw SessionRefused::because(SessionRefusalCause::Unknown);
        }

        if ($session->isTerminated()) {
            throw SessionRefused::because(SessionRefusalCause::Terminated);
        }

        if ($session->hasExpiredAt($this->clock->now(), $this->lifetimeInSeconds())) {
            throw SessionRefused::because(SessionRefusalCause::Expired);
        }

        return $session;
    }

    /**
     * Whether a token names a usable session, without raising.
     *
     * Runs the same evaluation, so it cannot report as usable a session
     * {@see forToken()} would then refuse.
     */
    public function permits(string $token): bool
    {
        try {
            $this->forToken($token);
        } catch (SessionRefused) {
            return false;
        }

        return true;
    }

    private function lifetimeInSeconds(): int
    {
        return $this->policy->read($this->lifetime)->asDurationInSeconds();
    }
}
