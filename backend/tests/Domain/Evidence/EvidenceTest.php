<?php

declare(strict_types=1);

namespace Tests\Domain\Evidence;

use Cmp\Application\Shared\Evidence\Evidence;
use Cmp\Application\Shared\Evidence\EvidentialOutcome;
use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Domain\Shared\Time\Instant;
use Cmp\Infrastructure\Evidential\KeyedChainHash;
use InvalidArgumentException;
use Tests\Domain\DomainTestCase;

/**
 * CMP-IMP-441, CMP-IMP-443 — what a record contains, and the keyed hash over it.
 *
 * Level 2 (`TC-029` ‡). The record's content rules and the hash construction are
 * both decidable without a database; what the database enforces about them is
 * level 3's.
 */
final class EvidenceTest extends DomainTestCase
{
    private const KEY = 'a-test-chain-key-of-at-least-32-bytes';

    public function test_a_record_captures_actor_action_subject_time_and_outcome(): void
    {
        // BE-107 ‡ / DB-109 ‡ / FRD-FR-245 ‡ — what occurred, when, its subject
        // and the party responsible.
        $evidence = $this->anEvidence();

        self::assertSame('actor-1', $evidence->actor()->toString());
        self::assertSame('ride.published', $evidence->action());
        self::assertSame('ride-external-1', $evidence->subject());
        self::assertSame(EvidentialOutcome::Succeeded, $evidence->outcome());
        self::assertSame('2026-08-19 09:30:00.000000', $evidence->occurredAt()->toDatabaseString());
    }

    public function test_a_record_that_cannot_say_what_it_is_about_is_refused(): void
    {
        // Negative test for BE-107 ‡.
        $this->expectException(InvalidArgumentException::class);

        Evidence::of(
            ActorReference::fromString('actor-1'),
            'ride.published',
            '   ',
            EvidentialOutcome::Succeeded,
            $this->anInstant(),
        );
    }

    public function test_a_successful_record_needs_no_reason(): void
    {
        // DB-109 ‡ requires NOT NULL on actor, action, subject, outcome and time —
        // and deliberately not on reason.
        self::assertNull($this->anEvidence()->reason());
    }

    public function test_a_refused_operation_must_carry_its_refusal_reason(): void
    {
        // Negative test for BE-114 ‡: "Refused operations shall be evidenced with
        // their refusal reason."
        $this->expectException(InvalidArgumentException::class);

        Evidence::of(
            ActorReference::fromString('actor-1'),
            'booking.requested',
            'booking-external-1',
            EvidentialOutcome::Refused,
            $this->anInstant(),
        );
    }

    public function test_a_refusal_and_a_failure_are_distinct_outcomes(): void
    {
        // BE-186 ‡: a business refusal is never represented as an internal fault,
        // and an internal fault never as a refusal. The log keeps them apart too.
        self::assertCount(3, EvidentialOutcome::cases());
        self::assertNotSame(EvidentialOutcome::Refused, EvidentialOutcome::Failed);
    }

    public function test_the_hash_is_keyed_so_content_alone_does_not_determine_it(): void
    {
        // SADR-07 / SEC-105 ‡. Without the key an attacker who can write the
        // table can rewrite a record and recompute every subsequent hash, leaving
        // a chain that verifies perfectly.
        $evidence = $this->anEvidence();

        $one = (new KeyedChainHash(self::KEY))->forRecord($evidence, KeyedChainHash::GENESIS);
        $other = (new KeyedChainHash('a-different-key-of-at-least-32-bytes!'))->forRecord($evidence, KeyedChainHash::GENESIS);

        self::assertNotSame($one, $other);
    }

    public function test_the_same_content_always_produces_the_same_hash(): void
    {
        // SEC-108 ‡: canonical serialisation.
        $chain = new KeyedChainHash(self::KEY);

        self::assertSame(
            $chain->forRecord($this->anEvidence(), KeyedChainHash::GENESIS),
            $chain->forRecord($this->anEvidence(), KeyedChainHash::GENESIS),
        );
    }

    public function test_the_hash_covers_the_predecessor(): void
    {
        // SEC-105 ‡: over its own content **and its predecessor's hash**. Without
        // that, records could be reordered freely.
        $chain = new KeyedChainHash(self::KEY);

        self::assertNotSame(
            $chain->forRecord($this->anEvidence(), KeyedChainHash::GENESIS),
            $chain->forRecord($this->anEvidence(), 'some-other-predecessor'),
        );
    }

    public function test_two_different_records_cannot_share_a_hash_through_field_boundaries(): void
    {
        // SEC-108 ‡, the subtle half. With a separator rather than a length
        // prefix, an action of "a|b" with subject "c" would serialise to the same
        // bytes as an action of "a" with subject "b|c" — and two different records
        // would share a hash.
        $chain = new KeyedChainHash(self::KEY);

        $one = Evidence::of(
            ActorReference::fromString('actor-1'),
            'a|b',
            'c',
            EvidentialOutcome::Succeeded,
            $this->anInstant(),
        );
        $other = Evidence::of(
            ActorReference::fromString('actor-1'),
            'a',
            'b|c',
            EvidentialOutcome::Succeeded,
            $this->anInstant(),
        );

        self::assertNotSame(
            $chain->forRecord($one, KeyedChainHash::GENESIS),
            $chain->forRecord($other, KeyedChainHash::GENESIS),
        );
    }

    public function test_an_altered_record_no_longer_matches_its_hash(): void
    {
        // BE-109 ‡ / FRD-FR-247 ‡: alteration is detectable.
        $chain = new KeyedChainHash(self::KEY);
        $recorded = $chain->forRecord($this->anEvidence(), KeyedChainHash::GENESIS);

        $altered = Evidence::of(
            ActorReference::fromString('actor-1'),
            'ride.published',
            'a-different-ride',
            EvidentialOutcome::Succeeded,
            $this->anInstant(),
        );

        self::assertFalse($chain->matches($altered, KeyedChainHash::GENESIS, $recorded));
        self::assertTrue($chain->matches($this->anEvidence(), KeyedChainHash::GENESIS, $recorded));
    }

    public function test_a_short_key_is_refused(): void
    {
        // A short key is the failure mode that looks like it is working.
        $this->expectException(InvalidArgumentException::class);

        new KeyedChainHash('too-short');
    }

    private function anEvidence(): Evidence
    {
        return Evidence::of(
            ActorReference::fromString('actor-1'),
            'ride.published',
            'ride-external-1',
            EvidentialOutcome::Succeeded,
            $this->anInstant(),
        );
    }

    private function anInstant(): Instant
    {
        return Instant::fromString('2026-08-19T09:30:00Z');
    }
}
