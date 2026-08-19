<?php

declare(strict_types=1);

namespace Tests\Integration\Authorisation;

use Cmp\Application\Shared\Authorisation\Actor;
use Cmp\Application\Shared\Authorisation\AuthorisationPolicy;
use Cmp\Application\Shared\Authorisation\AuthorisationRefusalCause;
use Cmp\Application\Shared\Authorisation\Authoriser;
use Cmp\Application\Shared\Authorisation\Operation;
use Cmp\Application\Shared\Authorisation\RecordsAuthorisationRefusals;
use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Infrastructure\Authorisation\LoggingAuthorisationRefusals;
use Psr\Log\AbstractLogger;
use Tests\Integration\IntegrationTestCase;

/**
 * CMP-IMP-033 — a refused authorisation reaches a sink.
 *
 * Level 3: the wiring is the thing under test, and it only exists once the
 * container has built it.
 *
 * **`SEC-057` ‡ is not discharged by this.** The record it asks for is
 * evidential, and `BE-202` says operational logging *"shall not substitute for"*
 * the evidential log. What is proven here is that every refusal reaches the
 * recorder and carries what the evidential record will need — so `CMP-IMP-439`
 * is a binding change rather than a hunt through every place a refusal happens.
 */
final class AuthorisationRefusalRecordingTest extends IntegrationTestCase
{
    public function test_the_platform_wires_a_recorder(): void
    {
        self::assertInstanceOf(
            LoggingAuthorisationRefusals::class,
            $this->app->make(RecordsAuthorisationRefusals::class),
        );
    }

    public function test_a_refusal_reaches_the_recorder_with_what_the_evidential_record_will_need(): void
    {
        // SEC-057 ‡ / BE-183 / API-088 ‡ / NFR-060.
        $logger = $this->spyLogger();
        $authoriser = new Authoriser(AuthorisationPolicy::of([]), new LoggingAuthorisationRefusals($logger));

        $authoriser->permits(
            Operation::named('test.operation'),
            Actor::holding(ActorReference::fromString('actor-1'), []),
        );

        self::assertCount(1, $logger->entries);

        $entry = $logger->entries[0];
        self::assertSame('warning', $entry['level']);
        self::assertSame('authorisation.refused', $entry['message']);
        self::assertSame('test.operation', $entry['context']['operation']);
        self::assertSame('actor-1', $entry['context']['actor']);
        self::assertSame(AuthorisationRefusalCause::NoRuleStated->name, $entry['context']['cause']);
    }

    public function test_the_record_says_plainly_that_it_is_not_the_evidential_one(): void
    {
        // BE-202: "Operational logging shall be distinct from the evidential log
        // and shall not substitute for it." Someone reading this log later must
        // not mistake it for the record SEC-057 ‡ requires.
        $logger = $this->spyLogger();

        (new LoggingAuthorisationRefusals($logger))->record(
            Operation::named('test.operation'),
            Actor::holding(ActorReference::fromString('actor-1'), []),
            AuthorisationRefusalCause::NotAParty,
        );

        self::assertFalse($logger->entries[0]['context']['evidential']);
    }

    public function test_the_record_carries_nothing_be_201_forbids(): void
    {
        // BE-201 ‡: no payment credential, no precise location, no contact
        // detail. The entry carries an operation, an opaque actor reference and a
        // cause — DB-024 ‡ makes the reference opaque, so it is not a name or a
        // number either.
        $logger = $this->spyLogger();

        (new LoggingAuthorisationRefusals($logger))->record(
            Operation::named('test.operation'),
            Actor::holding(ActorReference::fromString('actor-1'), []),
            AuthorisationRefusalCause::CapabilityNotHeld,
        );

        self::assertSame(
            ['operation', 'actor', 'cause', 'detail', 'evidential'],
            array_keys($logger->entries[0]['context']),
        );
    }

    /**
     * @return AbstractLogger&object{entries: list<array{level: string, message: string, context: array<string, mixed>}>}
     */
    private function spyLogger(): AbstractLogger
    {
        return new class extends AbstractLogger
        {
            /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
            public array $entries = [];

            /**
             * @param  mixed  $level
             * @param  array<string, mixed>  $context
             */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->entries[] = [
                    'level' => is_string($level) ? $level : 'unknown',
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };
    }
}
