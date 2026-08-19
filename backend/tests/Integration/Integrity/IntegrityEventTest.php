<?php

declare(strict_types=1);

namespace Tests\Integration\Integrity;

use Cmp\Application\Shared\Evidence\EvidentialOutcome;
use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Application\Shared\Integrity\RecordsIntegrityEvents;
use Cmp\Infrastructure\Evidential\DatabaseEvidentialWriter;
use Cmp\Infrastructure\Integrity\EvidentialIntegrityEvents;
use InvalidArgumentException;
use Tests\Integration\Evidence\ClearsTheEvidentialLog;
use Tests\Integration\IntegrationTestCase;

/**
 * `CMP-IMP-468` — `API-039` ‡'s integrity event reaches the evidential log.
 *
 * Level 3 (`TC-030` ‡). What the record contains is level 4's, in
 * `RequestSchemaTest`; that it lands in `ev_` under the application account, and
 * cannot be removed afterwards, is only decidable against a real server.
 */
final class IntegrityEventTest extends IntegrationTestCase
{
    use ClearsTheEvidentialLog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearEvidentialLog();
    }

    protected function tearDown(): void
    {
        $this->clearEvidentialLog();

        parent::tearDown();
    }

    public function test_the_platform_wires_an_evidential_recorder(): void
    {
        // BE-202: operational logging is not a substitute. API-039 ‡ asks for an
        // evidential record, so the bound implementation must write one.
        self::assertInstanceOf(
            EvidentialIntegrityEvents::class,
            $this->app->make(RecordsIntegrityEvents::class),
        );
    }

    public function test_an_attempt_to_assert_an_authoritative_value_is_recorded(): void
    {
        $this->recorder()->record(
            ActorReference::fromString('actor-1'),
            'rides.publish',
            ['fare', 'seat counts'],
        );

        $rows = $this->records();

        self::assertCount(1, $rows);
        self::assertSame(EvidentialIntegrityEvents::ACTION, $rows[0]->action);
        self::assertSame('rides.publish', $rows[0]->subject);
        self::assertSame('actor-1', $rows[0]->actor);
        self::assertSame(EvidentialOutcome::Refused->value, $rows[0]->outcome);
    }

    public function test_the_reason_names_the_canonical_values_in_a_stable_order(): void
    {
        // BE-114 ‡ requires a reason on a refused record. Sorted, so two
        // identical attempts produce identical reasons and NFR-069's repetition
        // is groupable rather than needing a parser.
        $this->recorder()->record(
            ActorReference::fromString('actor-1'),
            'rides.publish',
            ['seat counts', 'fare'],
        );

        self::assertSame('fare, seat counts', $this->records()[0]->reason);
    }

    public function test_an_event_naming_no_value_is_refused(): void
    {
        // Negative test: an integrity event that says nothing was asserted is
        // evidence of nothing, and would dilute the record API-204 ‡ wants usable
        // for detecting repetition.
        $this->expectException(InvalidArgumentException::class);

        $this->recorder()->record(ActorReference::fromString('actor-1'), 'rides.publish', []);
    }

    public function test_the_record_cannot_be_removed_by_the_account_that_wrote_it(): void
    {
        // DB-118 ‡ / DADR-09. An integrity event a caller could provoke and the
        // platform could then tidy away would be worth nothing.
        $this->recorder()->record(ActorReference::fromString('actor-1'), 'rides.publish', ['fare']);

        $refused = false;

        try {
            $this->applicationConnection()->delete('DELETE FROM '.DatabaseEvidentialWriter::TABLE);
        } catch (\Throwable) {
            $refused = true;
        }

        self::assertTrue($refused);
        self::assertCount(1, $this->records());
    }

    private function recorder(): RecordsIntegrityEvents
    {
        return $this->app->make(RecordsIntegrityEvents::class);
    }

    /**
     * @return list<object{actor: string, action: string, subject: string, outcome: string, reason: ?string}>
     */
    private function records(): array
    {
        /** @var list<object{actor: string, action: string, subject: string, outcome: string, reason: ?string}> $rows */
        $rows = $this->applicationConnection()->select(
            'SELECT actor, action, subject, outcome, reason FROM '
            .DatabaseEvidentialWriter::TABLE.' ORDER BY id ASC'
        );

        return $rows;
    }
}
