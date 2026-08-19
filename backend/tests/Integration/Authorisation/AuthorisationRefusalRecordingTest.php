<?php

declare(strict_types=1);

namespace Tests\Integration\Authorisation;

use Cmp\Application\Shared\Authorisation\Actor;
use Cmp\Application\Shared\Authorisation\AuthorisationPolicy;
use Cmp\Application\Shared\Authorisation\AuthorisationRefusalCause;
use Cmp\Application\Shared\Authorisation\Authoriser;
use Cmp\Application\Shared\Authorisation\Operation;
use Cmp\Application\Shared\Authorisation\RecordsAuthorisationRefusals;
use Cmp\Application\Shared\Evidence\EvidentialOutcome;
use Cmp\Application\Shared\Evidence\VerifiesEvidentialChain;
use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Infrastructure\Authorisation\EvidentialAuthorisationRefusals;
use Cmp\Infrastructure\Evidential\DatabaseEvidentialWriter;
use Tests\Integration\Evidence\ClearsTheEvidentialLog;
use Tests\Integration\IntegrationTestCase;

/**
 * `SEC-057` ‡ — a refused authorisation reaches the **evidential** log.
 *
 * Level 3: the wiring is the thing under test, and it only exists once the
 * container has built it. What the record contains is proven at level 2 in
 * `EvidentialAuthorisationRefusalsTest` (`TC-033`); what is proven here is that
 * the platform's own composition writes it, that it lands in `ev_` under the
 * application account, and that the chain still verifies afterwards.
 *
 * `BE-202` — *"operational logging shall not substitute for"* the evidential log
 * — is now satisfied by having the evidential record rather than by a docblock
 * saying it is missing.
 */
final class AuthorisationRefusalRecordingTest extends IntegrationTestCase
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

    public function test_the_platform_wires_the_evidential_recorder(): void
    {
        // The interim operational-log recorder is gone. A second implementation
        // would mean a refusal could be recorded somewhere BE-202 says is not the
        // record SEC-057 ‡ asks for.
        self::assertInstanceOf(
            EvidentialAuthorisationRefusals::class,
            $this->app->make(RecordsAuthorisationRefusals::class),
        );
    }

    public function test_a_refusal_is_written_to_the_evidential_log(): void
    {
        // SEC-057 ‡, end to end, through the platform's own recorder.
        $authoriser = new Authoriser(
            AuthorisationPolicy::of([]),
            $this->app->make(RecordsAuthorisationRefusals::class),
        );

        $authoriser->permits(
            Operation::named('test.operation'),
            Actor::holding(ActorReference::fromString('actor-1'), []),
        );

        $rows = $this->records();

        self::assertCount(1, $rows);
        self::assertSame(EvidentialAuthorisationRefusals::ACTION, $rows[0]->action);
        self::assertSame('test.operation', $rows[0]->subject);
        self::assertSame('actor-1', $rows[0]->actor);
        self::assertSame(EvidentialOutcome::Refused->value, $rows[0]->outcome);
        self::assertSame(AuthorisationRefusalCause::NoRuleStated->describe(), $rows[0]->reason);
    }

    public function test_the_refusal_record_chains_like_any_other(): void
    {
        // SEC-105 ‡ / BE-115: there is one chain, and a refusal is not a
        // second-class entry in it. A verification pass that passed only because
        // the record was absent would prove nothing.
        $recorder = $this->app->make(RecordsAuthorisationRefusals::class);

        foreach (AuthorisationRefusalCause::cases() as $cause) {
            $recorder->record(
                Operation::named('test.operation'),
                Actor::holding(ActorReference::fromString('actor-1'), []),
                $cause,
            );
        }

        self::assertCount(count(AuthorisationRefusalCause::cases()), $this->records());

        $verification = $this->app->make(VerifiesEvidentialChain::class)->verify();

        self::assertTrue($verification->isIntact(), $verification->describe());
    }

    public function test_the_record_is_written_by_the_account_that_cannot_alter_it(): void
    {
        // DB-118 ‡ / DADR-09: the application account holds SELECT and INSERT on
        // ev_ and neither UPDATE nor DELETE. The refusal path runs under that
        // account like everything else, which is why the record it writes cannot
        // afterwards be tidied away by the code that wrote it.
        $this->app->make(RecordsAuthorisationRefusals::class)->record(
            Operation::named('test.operation'),
            Actor::holding(ActorReference::fromString('actor-1'), []),
            AuthorisationRefusalCause::NotAParty,
        );

        $rows = $this->records();
        self::assertCount(1, $rows);

        $refused = false;

        try {
            $this->applicationConnection()->delete(
                'DELETE FROM '.DatabaseEvidentialWriter::TABLE.' WHERE id = ?',
                [$rows[0]->id],
            );
        } catch (\Throwable) {
            $refused = true;
        }

        self::assertTrue($refused, 'DB-118 ‡: the application account must not be able to delete a refusal record.');
        self::assertCount(1, $this->records());
    }

    /**
     * @return list<object{id: int, actor: string, action: string, subject: string, outcome: string, reason: ?string}>
     */
    private function records(): array
    {
        /** @var list<object{id: int, actor: string, action: string, subject: string, outcome: string, reason: ?string}> $rows */
        $rows = $this->applicationConnection()->select(
            'SELECT id, actor, action, subject, outcome, reason FROM '
            .DatabaseEvidentialWriter::TABLE.' ORDER BY id ASC'
        );

        return $rows;
    }
}
