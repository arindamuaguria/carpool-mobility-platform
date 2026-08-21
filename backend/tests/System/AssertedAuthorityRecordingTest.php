<?php

declare(strict_types=1);

namespace Tests\System;

use Cmp\Infrastructure\Evidential\DatabaseEvidentialWriter;
use Cmp\Interface\Rest\Middleware\RequireIdempotencyKey;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Tests\Integration\Evidence\ClearsTheEvidentialLog;
use Tests\TestCase;

/**
 * `FRD-FR-240` ‡ — an attempt to assert an authoritative value is recorded.
 *
 * **Level 5** (`TC-025`): whether an evidential record exists is only answerable
 * against the store, and the record has to survive the whole request path rather
 * than be shown to have been asked for.
 *
 * `API-039` ‡ requires it, `SADR-08` places it among security events, and
 * `BE-202` keeps the operational log from standing in — *"what `API-039` ‡ asks
 * for is an evidential record, because it is evidence of what a caller
 * attempted."*
 */
final class AssertedAuthorityRecordingTest extends TestCase
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

    public function test_the_attempt_is_recorded_evidentially(): void
    {
        // FRD-FR-240 ‡ / API-039 ‡, end to end.
        $this->json('DELETE', '/api/v1/sessions/current', ['fare' => 250], $this->idempotent())
            ->assertStatus(400);

        $rows = $this->evidentialRows();

        self::assertCount(1, $rows);
        self::assertStringContainsString('integrity', $rows[0]->action);

        // BE-107 ‡'s subject: the operation the attempt was made against, which
        // is what an operator investigating one needs.
        self::assertStringContainsString('sessions/current', $rows[0]->subject);
    }

    public function test_the_record_names_the_value_and_not_what_the_caller_sent(): void
    {
        // BE-201 ‡: the canonical value, never the caller's spelling and never
        // their value. An evidential log holding what a caller supplied is a log
        // holding whatever a caller chose to put in it.
        $this->json(
            'DELETE',
            '/api/v1/sessions/current',
            ['seatsRemaining' => 41, 'walletBalance' => '9999.99'],
            $this->idempotent(),
        )->assertStatus(400);

        $encoded = json_encode($this->evidentialRows(), JSON_THROW_ON_ERROR);

        self::assertStringContainsString('seat counts', $encoded);
        self::assertStringContainsString('balances', $encoded);

        self::assertStringNotContainsString('seatsRemaining', $encoded);
        self::assertStringNotContainsString('walletBalance', $encoded);
        self::assertStringNotContainsString('9999.99', $encoded);
        self::assertStringNotContainsString('41', $encoded);
    }

    public function test_an_unauthenticated_attempt_is_recorded_as_unattributed(): void
    {
        // API-039 ‡ says "a request", not "an authenticated request", and
        // NFR-069's abuse case is exactly the caller who has not signed in.
        // BE-107 ‡ needs the actor field filled, and a reserved name says
        // plainly that nobody was named rather than leaving the attempt
        // unrecorded.
        $this->json('DELETE', '/api/v1/sessions/current', ['fare' => 1], $this->idempotent())
            ->assertStatus(400);

        $rows = $this->evidentialRows();

        self::assertCount(1, $rows);
        self::assertSame('unattributed', $rows[0]->actor);
    }

    public function test_an_ordinary_unknown_field_records_nothing(): void
    {
        // API-039 ‡ again, from the other side. A typo is not an integrity event,
        // and a log that recorded every one would be a log nobody could read for
        // the case it exists for.
        $this->json('DELETE', '/api/v1/sessions/current', ['nonsense' => 1], $this->idempotent())
            ->assertStatus(409);

        self::assertSame([], $this->evidentialRows());
    }

    public function test_a_clean_request_records_nothing(): void
    {
        // The record exists because something was attempted. A platform that
        // wrote one per request would drown the log it wrote to be read.
        $this->getJson('/api/v1/health')->assertOk();

        self::assertSame([], $this->evidentialRows());
    }

    /**
     * @return array<string, string>
     */
    private function idempotent(): array
    {
        return [RequireIdempotencyKey::HEADER => 'system-asserted-authority'];
    }

    /**
     * @return list<object{actor: string, action: string, subject: string, reason: ?string}>
     */
    private function evidentialRows(): array
    {
        $connection = $this->app->make(ConnectionResolverInterface::class)->connection('mysql');

        self::assertInstanceOf(Connection::class, $connection);

        /** @var list<object{actor: string, action: string, subject: string, reason: ?string}> $rows */
        $rows = $connection->select(
            'SELECT actor, action, subject, reason FROM '.DatabaseEvidentialWriter::TABLE.' ORDER BY id ASC'
        );

        return $rows;
    }
}
