<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Integrity;

use Cmp\Application\Shared\Evidence\Evidence;
use Cmp\Application\Shared\Evidence\EvidentialOutcome;
use Cmp\Application\Shared\Evidence\RecordsEvidence;
use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Application\Shared\Integrity\RecordsIntegrityEvents;
use Cmp\Domain\Shared\Time\Clock;
use Cmp\Infrastructure\Authorisation\EvidentialAuthorisationRefusals;
use InvalidArgumentException;

/**
 * Writes an integrity event to the evidential log.
 *
 * `API-039` ‡ / `FRD-FR-241` / `SADR-08`. The same shape as
 * {@see EvidentialAuthorisationRefusals}, and
 * for the same reason: the record `API-039` ‡ asks for is evidential, and
 * `BE-202` forbids operational logging standing in for one.
 *
 * ## What the record says, and what it deliberately omits
 *
 * - **action** — `integrity.authoritative_value_asserted`, one value so the whole
 *   class of attempt is one query.
 * - **subject** — the operation the caller was invoking.
 * - **outcome** — `Refused`. `API-038` ‡ refused the request in whole; the record
 *   says so rather than describing an attempt whose outcome a reader must infer.
 * - **reason** — the **canonical** value names, never the caller's spelling and
 *   never what they sent. `BE-201` ‡ keeps caller-supplied content out of a log,
 *   and seven short names are bounded where an arbitrary field list is not.
 *
 * The value the caller tried to assert is the one thing a reader might want and
 * must not have: it is caller-supplied data whose whole significance is that the
 * platform refused to believe it.
 */
final class EvidentialIntegrityEvents implements RecordsIntegrityEvents
{
    public const ACTION = 'integrity.authoritative_value_asserted';

    public function __construct(
        private readonly RecordsEvidence $evidence,
        private readonly Clock $clock,
    ) {}

    public function record(ActorReference $actor, string $operation, array $authoritativeValues): void
    {
        if ($authoritativeValues === []) {
            throw new InvalidArgumentException(
                'API-039 ‡: an integrity event records which authoritative values were asserted. '
                .'An event naming none is not evidence of anything.'
            );
        }

        $this->evidence->record(Evidence::of(
            $actor,
            self::ACTION,
            $operation,
            EvidentialOutcome::Refused,
            $this->clock->now(),
            // BE-114 ‡ needs a reason on a refused record; the canonical names
            // are it. Sorted so that two identical attempts produce identical
            // reasons and a reader can group them.
            implode(', ', self::sorted($authoritativeValues)),
        ));
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
