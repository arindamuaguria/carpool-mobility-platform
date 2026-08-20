<?php

declare(strict_types=1);

namespace Cmp\Interface\Rest;

/**
 * How a session token travels, in one place.
 *
 * Two statements fix the direction of travel and neither names a header, so this
 * file chooses the names and records why. Both choices are reported as
 * implementation choices.
 *
 * ## Inbound — `Authorization`
 *
 * `SEC-037` ‡ and `API-100`: the token is carried **in a request header** and
 * never in a URI, a query parameter or a body field. `NFR-062` and
 * `SRS-REQ-141` are the reason — a URI reaches a proxy log, a browser history and
 * a referrer, and a query parameter reaches all three.
 *
 * `Authorization: Bearer <token>` is the header whose defined meaning is exactly
 * this, and every intermediary already knows not to log it.
 *
 * ## Outbound — a header, and never the body
 *
 * `SEC-038` ‡ is unusually specific: *"A token shall never appear in a **response
 * body**, in a log, in a diagnostic record or in an error message."* It names the
 * body rather than the response, and that distinction is doing work — a client
 * that can never receive a token can never hold one, and `MOB-144` requires the
 * client to hold *session material* and clear it on session end.
 *
 * So a newly issued token leaves in a **response header** and never in `data`.
 * The alternative reading — that a token may never leave at all — would make
 * `FRD-FR-016`'s *"establish an authenticated session"* unimplementable and
 * `MOB-144` meaningless.
 *
 * ## Why this is not the credential `API-101` ‡ forbids
 *
 * `API-101` ‡ says *"a credential shall never appear in a response"*, and it
 * cites `NFR-053` ‡ — credentials *"not recoverable from **any store the
 * platform controls**"* — and `SRS-REQ-122`, credentials *"not recoverable from
 * the client element"*. Both concern **stored** authentication material coming
 * back out.
 *
 * A freshly minted session token is not stored: `SEC-036` ‡ keeps only its hash,
 * so the platform cannot return this token again even if asked. CMP-DOC-08 draws
 * the same line — `MOB-143` forbids the client holding an *authentication
 * credential* recoverably while `MOB-144` has it holding *session material* and
 * clearing it at session end.
 */
final class SessionCarriage
{
    /**
     * `SEC-037` ‡ / `API-100` — inbound.
     */
    public const REQUEST_HEADER = 'Authorization';

    public const SCHEME = 'Bearer';

    /**
     * `SEC-038` ‡ — outbound, and never the body.
     */
    public const ISSUE_HEADER = 'Session-Token';

    /**
     * The token a request carries, or null where it carries none.
     *
     * `API-017`: the scheme is compared without regard to case, because no
     * meaning here depends on it.
     */
    public static function tokenIn(?string $header): ?string
    {
        if ($header === null) {
            return null;
        }

        $parts = preg_split('/\s+/', trim($header), 2);

        if ($parts === false || count($parts) !== 2) {
            return null;
        }

        if (strcasecmp($parts[0], self::SCHEME) !== 0) {
            return null;
        }

        return trim($parts[1]) === '' ? null : $parts[1];
    }
}
