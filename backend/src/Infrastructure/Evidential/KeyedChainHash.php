<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Evidential;

use Cmp\Application\Shared\Evidence\Evidence;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * The keyed hash that makes alteration detectable.
 *
 * `SADR-07` states the weakness this exists to close, and it is worth quoting in
 * full: *"An unkeyed chain has a specific weakness: anyone who can write the
 * table can rewrite a record **and recompute every subsequent hash**, leaving a
 * chain that verifies perfectly."*
 *
 * `SEC-105` ‡: each record carries a keyed hash over its own content **and its
 * predecessor's hash**. `SEC-106` ‡: the key is held outside the database and is
 * not readable through a database connection — so an attacker who defeats
 * `DB-118` ‡ still cannot forge, because the two things they would need are kept
 * in different places.
 *
 * `SEC-110` ‡ is careful about the ordering of defences, and so is this class'
 * documentation: **the withheld `UPDATE` and `DELETE` privilege remains the
 * primary defence.** The key defends against an attacker who has already
 * defeated it.
 *
 * ## The construction
 *
 * `SEC-107` and CMP-DOC-13 §14.2 specify *"a keyed message authentication
 * construction over a canonical serialisation of the record"* and do not name a
 * hash function. **HMAC-SHA-256 is chosen here**, recorded per record in
 * `chain_algorithm` so that `SEC-174` holds — an algorithm choice must be
 * replaceable without a migration that cannot be staged, and a chain whose
 * records say which construction produced them can be re-verified under either.
 *
 * That choice is a technical decision taken in code and needs routing into
 * CMP-DOC-13 §14.2, which is where §14.2 says such a decision is recorded.
 *
 * ## Canonical serialisation
 *
 * `SEC-108` ‡: *"the same content always produces the same hash regardless of
 * field order or encoding."* Fields are serialised in a fixed order, each
 * length-prefixed. Length-prefixing rather than delimiting is deliberate: with a
 * separator, an actor of `a|b` and an action of `c` produce the same bytes as an
 * actor of `a` and an action of `b|c`, and two different records would then share
 * a hash.
 */
final class KeyedChainHash
{
    /**
     * `SEC-107`, recorded per record so `SEC-174` can stage a replacement.
     */
    public const ALGORITHM = 'hmac-sha256';

    /**
     * What the first record chains to.
     *
     * `DB-110` ‡ requires the previous hash to be `NOT NULL`, so the chain has a
     * defined beginning rather than a nullable special case that every reader
     * would have to remember.
     */
    public const GENESIS = 'cmp.evidential.genesis';

    private readonly string $key;

    public function __construct(#[SensitiveParameter] string $key)
    {
        if (strlen($key) < 32) {
            // SEC-019/SEC-035 require a cryptographically secure source; a short
            // key is the failure mode that looks like it is working.
            throw new InvalidArgumentException(
                'SEC-106 ‡: the evidential chain key must be at least 32 bytes. It is held outside the '
                .'database and injected at deploy time (SADR-14, OPS-098).'
            );
        }

        $this->key = $key;
    }

    /**
     * The hash for a record, over its content and its predecessor's hash.
     *
     * @param  string  $previousHash  the predecessor's hash, or {@see GENESIS}
     */
    public function forRecord(Evidence $evidence, string $previousHash): string
    {
        return hash_hmac('sha256', $this->canonicalise($evidence, $previousHash), $this->key, binary: true);
    }

    /**
     * Whether a recorded hash is the one this content and predecessor produce.
     *
     * Compared in constant time: a verification pass that leaked, through its
     * timing, how much of a forged hash was correct would hand an attacker the
     * rest one byte at a time.
     */
    public function matches(Evidence $evidence, string $previousHash, string $recordedHash): bool
    {
        return hash_equals($this->forRecord($evidence, $previousHash), $recordedHash);
    }

    public function genesis(): string
    {
        return self::GENESIS;
    }

    /**
     * `SEC-108` ‡: fixed field order, each field length-prefixed, so the same
     * content always produces the same bytes.
     */
    private function canonicalise(Evidence $evidence, string $previousHash): string
    {
        $fields = [
            $previousHash,
            $evidence->actor()->toString(),
            $evidence->action(),
            $evidence->subject(),
            $evidence->outcome()->value,
            $evidence->reason() ?? '',
            // The instant, in the single time zone reference (DB-034), to a fixed
            // precision — so two readings of the same moment cannot serialise
            // differently.
            $evidence->occurredAt()->toDatabaseString(),
        ];

        $canonical = '';

        foreach ($fields as $field) {
            $canonical .= strlen($field).':'.$field;
        }

        return $canonical;
    }
}
