<?php

declare(strict_types=1);

namespace Cmp\Application\User;

use Cmp\Application\Shared\Authorisation\AuthorisationRefusalCause;

/**
 * Why a session would not serve — **for the record, not for the caller**.
 *
 * `SEC-048` ‡ makes a terminated, an expired and an unknown token
 * indistinguishable to a caller, and {@see SessionRefusal} is that single
 * outward answer. This is the inward one.
 *
 * The same shape as {@see AuthorisationRefusalCause},
 * and for the same reason: `SEC-050` evidences session events, and a record that
 * could not say which of the three happened would be of little use to whoever
 * reads it. `DB-044` ‡ retains a terminated session precisely so that **reuse is
 * detectable**, and detection means nothing if the detector cannot report what it
 * detected.
 */
enum SessionRefusalCause
{
    /**
     * No session carries this token hash. Either it was never issued, or it was
     * issued so long ago that `SEC-047`'s retention has lapsed — which are the
     * same thing to a lookup.
     */
    case Unknown;

    /**
     * `SEC-040` ‡: the session was terminated and the record kept. This is the
     * case `DB-044` ‡ exists to make visible.
     */
    case Terminated;

    /**
     * `SEC-039` ‡: the session outlived its bound.
     */
    case Expired;

    public function describe(): string
    {
        return match ($this) {
            self::Unknown => 'no session carries this token hash (SEC-048 ‡)',
            self::Terminated => 'the session was terminated and its record retained (SEC-040 ‡, DB-044 ‡)',
            self::Expired => 'the session outlived its configured lifetime (SEC-039 ‡)',
        };
    }
}
