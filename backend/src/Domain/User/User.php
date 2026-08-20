<?php

declare(strict_types=1);

namespace Cmp\Domain\User;

use Cmp\Domain\Shared\Event\EventRecordingAggregate;
use Cmp\Domain\Shared\Event\RecordsDomainEvents;
use Cmp\Domain\Shared\Time\Instant;
use Cmp\Domain\User\Event\PhoneNumberVerified;
use Cmp\Domain\User\Event\UserRegistered;

/**
 * `CMP-IMP-052` — the first of `BE-017`'s nine aggregates.
 *
 * `BE-021`: **an aggregate shall be constructible into a valid state only.** The
 * constructor is private and the two named constructors below are the only ways
 * in, so there is no partially built `User` for anything to observe. `BE-019`
 * keeps its invariants its own: it reaches into no other aggregate to enforce
 * them, and it has none that need one.
 *
 * `BE-022`: it **records** events rather than dispatching them. `BE-058` puts
 * dispatch after the producing transaction commits, so a listener cannot be part
 * of a registration and cannot undo one by failing.
 *
 * ## What a valid `User` is
 *
 * Four things, and `BAD-RULE-043` is why it is only four: a reference
 * (`FRD-FR-005` ‡), a phone number, a verification standing and an account
 * state. Name, email address, date of birth and address are **not collected**,
 * and `SEC-089`/`SEC-090` make that a positive position rather than an omission —
 * personal data is minimised to what a stated purpose requires, and an element
 * with no stated purpose is a review finding.
 *
 * ## The two transitions it offers, and the one it does not
 *
 * {@see register()} creates the account `UNVERIFIED` and `ACTIVE`, which is
 * `FRD-FR-006` — *"in an unverified state that does not permit participation"*.
 *
 * {@see phoneNumberDemonstrated()} moves the standing to `VERIFIED`, which is
 * `FRD-FR-008` — *"usable only after control of its phone number has been
 * demonstrated"*. It is idempotent, because `API-062` ‡ makes a repeated request
 * with the same key return the original outcome, and an aggregate that raised on
 * the second call would make that impossible to honour.
 *
 * **There is no method that changes the account state.** `BAD-RULE-010` permits a
 * `SUSPENDED` or `DEACTIVATED` account to regain an active session only *"through
 * a defined account-state transition"*, and none is defined: who may perform one,
 * on what grounds and with what appeal is `FRD-GAP-024`, Critical and open on
 * `BAD-DEC-006` and `BAD-DEC-016`. `BE-012` puts an absolute rule beyond reach of
 * override, and the way to put a transition beyond reach is not to write one.
 *
 * ## What it does not decide
 *
 * Whether an `UNVERIFIED` user may hold a session at all is **not answered here**.
 * `FRD-FR-018` routes such a user *"to phone verification rather than into the
 * application"*, and whether that routing happens before or after a session
 * exists is session-establishment behaviour — `CMP-IMP-053`, which is blocked on
 * `SEC-017`, `SEC-031`, `SEC-039` and `SEC-049`. This aggregate answers the two
 * questions it can — {@see permitsAuthenticatedUse()} and
 * {@see permitsParticipation()} — and leaves the sequencing to the service that
 * owns it.
 */
final class User implements EventRecordingAggregate
{
    use RecordsDomainEvents;

    private function __construct(
        private readonly UserReference $reference,
        private readonly PhoneNumber $phoneNumber,
        private VerificationStanding $verificationStanding,
        private readonly AccountState $accountState,
    ) {}

    /**
     * A new account (`FRD-FR-001`, `FRD-FR-006`).
     *
     * `UNVERIFIED` because `FRD-FR-006` requires it, and `ACTIVE` because that is
     * the only state `BAD-RULE-010` gives that permits use at all — an account
     * created `SUSPENDED` would be an account nobody could ever reach, since no
     * transition out of `SUSPENDED` is defined.
     */
    public static function register(
        UserReference $reference,
        PhoneNumber $phoneNumber,
        Instant $at,
    ): self {
        $user = new self(
            $reference,
            $phoneNumber,
            VerificationStanding::Unverified,
            AccountState::Active,
        );

        $user->recordThat(new UserRegistered($reference, $at));

        return $user;
    }

    /**
     * An account read back from the store.
     *
     * Separate from {@see register()} and recording no event: rehydration is not
     * a registration, and an event recorded here would be dispatched every time
     * an account was loaded.
     */
    public static function reconstitute(
        UserReference $reference,
        PhoneNumber $phoneNumber,
        VerificationStanding $verificationStanding,
        AccountState $accountState,
    ): self {
        return new self($reference, $phoneNumber, $verificationStanding, $accountState);
    }

    /**
     * `FRD-FR-008`: control of the number has been demonstrated.
     *
     * `BAD-RULE-006` puts the decision here rather than with the caller: the
     * platform determines verification status and a client never asserts it. What
     * counts as a demonstration is `SEC-015`–`SEC-020` ‡'s and is checked before
     * this is called; this records the consequence.
     *
     * Idempotent by `API-062` ‡'s requirement, and it records an event only on
     * the transition — a second call changes nothing, so there is nothing for a
     * listener to hear about.
     */
    public function phoneNumberDemonstrated(Instant $at): void
    {
        if ($this->verificationStanding === VerificationStanding::Verified) {
            return;
        }

        $this->verificationStanding = VerificationStanding::Verified;

        $this->recordThat(new PhoneNumberVerified($this->reference, $at));
    }

    public function reference(): UserReference
    {
        return $this->reference;
    }

    public function phoneNumber(): PhoneNumber
    {
        return $this->phoneNumber;
    }

    public function verificationStanding(): VerificationStanding
    {
        return $this->verificationStanding;
    }

    public function accountState(): AccountState
    {
        return $this->accountState;
    }

    /**
     * `SEC-051` ‡: no session is established for a caller whose account state
     * does not permit it.
     */
    public function permitsAuthenticatedUse(): bool
    {
        return $this->accountState->permitsAuthenticatedUse();
    }

    /**
     * `FRD-FR-006` / `FRD-FR-008`: participation requires **both** an account
     * state that permits use and a demonstrated number.
     *
     * The conjunction is stated once, here, so that a caller cannot satisfy
     * itself with one half. `BAD-RULE-004` says what participation is.
     */
    public function permitsParticipation(): bool
    {
        return $this->permitsAuthenticatedUse() && $this->verificationStanding->permitsParticipation();
    }
}
