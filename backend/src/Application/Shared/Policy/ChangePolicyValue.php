<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Policy;

use Cmp\Application\Shared\Evidence\Evidence;
use Cmp\Application\Shared\Evidence\EvidentialOutcome;
use Cmp\Application\Shared\Evidence\RecordsEvidence;
use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Application\Shared\Transaction\TransactionBoundary;
use Cmp\Domain\Shared\Policy\PolicyKey;
use Cmp\Domain\Shared\Policy\PolicyNotDeclared;
use Cmp\Domain\Shared\Policy\PolicyRegister;
use Cmp\Domain\Shared\Policy\PolicyValue;
use Cmp\Domain\Shared\Policy\PolicyValueInvalid;
use Cmp\Domain\Shared\Time\Clock;
use InvalidArgumentException;

/**
 * Applies a new value to a declared policy key.
 *
 * `BADR-12`: *"Every change is written by an operator action, validated before
 * application, and recorded evidentially."* This is that operation, and it is
 * the only one — there is no path that writes a policy value without validating
 * it and recording who changed it from what.
 *
 * The order matters and is not incidental:
 *
 * 1. **The key must be declared** (`DB-153` ‡, `BE-172` ‡). An undeclared key
 *    raises before anything is read or written. Absence is the mechanism by
 *    which a policy value cannot relax an absolute rule; validating an
 *    undeclared key would mean the register was advisory.
 * 2. **The value must satisfy the declared type** (`BE-174`, `ARCH-148`) —
 *    *"validated on change, and an invalid configuration rejected rather than
 *    applied"*. Rejection happens before the transaction opens, so an invalid
 *    change never reaches the store at all.
 * 3. **A new version is appended** (`DB-152`), never an update in place, with
 *    the actor and the previous value (`BE-173`).
 * 4. **An evidential record is written** (`ARCH-115`, `DB-154`, `BE-173`), in the
 *    same transaction as the change it evidences (`BE-106` ‡).
 * 5. **The cache is cleared** (`BE-170`), so no restart is required — after the
 *    transaction, because a cache cleared inside one that then rolls back would
 *    have advertised a value nobody set.
 *
 * ## Where the previous and new values live
 *
 * `BE-173` asks for the change to be evidenced *"with actor, previous value and
 * new value"*, and {@see Evidence} has no field for a value. That is not an
 * oversight in either place. `BE-107` ‡ fixes the evidential record at six
 * fields, and `BE-201` ‡ is why there is no seventh: a general-purpose context
 * bag is where a payment credential, a precise location or a contact detail
 * arrives. `DB-152` requires the versioned record that holds the two values, and
 * `DB-154` requires the evidential record, separately.
 *
 * So the pair discharges `BE-173` between them: `cfg_policy_versions` holds the
 * actor, the previous value and the new one, and the evidential record names the
 * actor and points at exactly one of those rows through its subject,
 * `<key>@v<version>`. Both are append-only, and neither invents a field.
 * `BE-107` ‡ is integrity-critical and `BE-173` is not, so where they pull
 * against each other the ‡ statement decides.
 *
 * `BE-044` requires authorisation to be evaluated before the domain is invoked,
 * on the single application-layer path of `BADR-14`. That path exists
 * (`CMP-IMP-033`), but this operation has **no stated authorisation rule** — so
 * `SEC-055` ‡ would refuse it deny-by-default, and the rule cannot be written
 * because `BAD-DEC-006` leaves the administrative role set undecided. The actor
 * is recorded here and **not yet checked**, and this operation must not be
 * exposed through any interface surface until it is.
 */
final class ChangePolicyValue
{
    /**
     * `BE-107` ‡ — what occurred. One value, so a query for policy changes is a
     * query for one action.
     */
    public const ACTION = 'policy.value.changed';

    public function __construct(
        private readonly TransactionBoundary $transaction,
        private readonly PolicyRegister $register,
        private readonly RecordsPolicyChanges $recorder,
        private readonly PolicyCache $cache,
        private readonly RecordsEvidence $evidence,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  string  $actor  who is making the change (`BE-173`)
     *
     * @throws PolicyNotDeclared where the register does not declare the key
     * @throws PolicyValueInvalid where the value does not satisfy the declared type
     */
    public function apply(string $keyName, string $newRawValue, string $actor): PolicyValue
    {
        // 1. DB-153 ‡ / BE-172 ‡ — raises where the key is not declared.
        $key = $this->register->key($keyName);

        // 2. BE-174 / ARCH-148 — rejected rather than applied, before the
        //    transaction opens.
        if (! $key->accepts($newRawValue)) {
            throw PolicyValueInvalid::forKey($key, $newRawValue);
        }

        if (trim($actor) === '') {
            throw new InvalidArgumentException(
                'BE-173: a policy change is recorded with the actor who made it. ARCH-146 requires every '
                .'change audited, and a change nobody is recorded as having made is not audited.'
            );
        }

        $version = $this->transaction->transactional(function () use ($key, $newRawValue, $actor): int {
            $this->recorder->declare($key);

            $previous = $this->recorder->currentRawValue($key);

            // 3. DB-152 — a new version, never an update in place.
            $version = $this->recorder->appendVersion($key, $newRawValue, $previous, $actor);

            // 4. ARCH-115 / DB-154 / BE-173 — inside the transaction, because
            //    BE-106 ‡ requires the record and the change it evidences to
            //    commit together or not at all. FRD-FR-248 ‡: if this raises, the
            //    version rolls back with it and no change is reported.
            $this->evidence->record(Evidence::of(
                ActorReference::fromString($actor),
                self::ACTION,
                self::subjectFor($key, $version),
                EvidentialOutcome::Succeeded,
                $this->clock->now(),
            ));

            return $version;
        });

        // 5. BE-170 — invalidated on change; no restart required.
        $this->cache->forget($key);

        return new PolicyValue($key, $newRawValue, $version);
    }

    /**
     * The one version this record is evidence of.
     *
     * `DB-024` ‡: neither part is an internal key. The key name is the declared,
     * external name of the policy and the version is its own per-key sequence, so
     * the pair resolves to exactly one `cfg_policy_versions` row — which is where
     * `DB-152` puts the previous and new values.
     *
     * Public so a reader of the log derives the subject the same way this class
     * writes it.
     */
    public static function subjectFor(PolicyKey $key, int $version): string
    {
        return $key->name().'@v'.$version;
    }
}
