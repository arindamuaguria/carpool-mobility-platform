<?php

declare(strict_types=1);

namespace Tests\Domain\Shared;

use Cmp\Application\Shared\ApplicationService;
use Cmp\Application\Shared\Authorisation\Actor;
use Cmp\Application\Shared\Authorisation\AuthorisationPolicy;
use Cmp\Application\Shared\Authorisation\AuthorisationRule;
use Cmp\Application\Shared\Authorisation\Authoriser;
use Cmp\Application\Shared\Authorisation\Capability;
use Cmp\Application\Shared\Authorisation\Operation;
use Cmp\Application\Shared\Authorisation\Role;
use Cmp\Application\Shared\Command;
use Cmp\Application\Shared\Failure\BusinessRefused;
use Cmp\Application\Shared\Failure\FailureBranch;
use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Application\Shared\Idempotency\IdempotencyKey;
use Cmp\Application\Shared\Result;
use Cmp\Application\Shared\StateChangingCommand;
use Cmp\Domain\Shared\Refusal\BusinessRefusal;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Domain\Authorisation\Doubles\RecordedRefusals;
use Tests\Domain\DomainTestCase;
use Tests\Domain\Shared\Doubles\TestRefusalReason;

/**
 * CMP-IMP-023, CMP-IMP-033 — the application service base and the authorisation
 * it performs before the domain.
 *
 * Level 2 (`TC-029` ‡): no database, no framework, no network. That this suite
 * can exercise an application service at all is itself the evidence for
 * `BE-043` — the operation is invocable without HTTP context — and for
 * `BADR-14`'s rejection of authorisation inside the domain, which *"makes domain
 * tests carry identity"*.
 */
final class ApplicationServiceTest extends DomainTestCase
{
    #[Test]
    public function a_service_is_invocable_without_any_transport_context(): void
    {
        // BE-043.
        $result = $this->permittedService(static fn (): Result => Result::success('done'))
            ->execute($this->aCommand(), $this->anActor());

        self::assertTrue($result->isSuccess());
        self::assertSame('done', $result->value());
    }

    #[Test]
    public function authorisation_is_evaluated_before_the_domain_is_invoked(): void
    {
        // BE-044 / SEC-053 ‡ / BADR-14. SADR-06 rejected middleware-only
        // authorisation because "a queue worker or a Filament resource bypassing
        // the HTTP stack would bypass authorisation entirely" — so the base does
        // it, and a service cannot omit it.
        $reached = false;

        $service = $this->serviceWith(
            AuthorisationPolicy::of([]),
            function () use (&$reached): Result {
                $reached = true;

                return Result::succeeded();
            },
        );

        $result = $service->execute($this->aCommand(), $this->anActor());

        self::assertTrue($result->isFailure());
        self::assertFalse($reached, 'BE-044: the domain must not be reached when authorisation refuses.');
    }

    #[Test]
    public function an_authorisation_refusal_reaches_the_caller_as_a_business_refusal(): void
    {
        // CMP-DOC-10 §8.6: "Caller not entitled → Business refusal … Never a
        // not-found, which would leak existence."
        $result = $this->serviceWith(AuthorisationPolicy::of([]), static fn (): Result => Result::succeeded())
            ->execute($this->aCommand(), $this->anActor());

        $failure = $result->failure();

        self::assertInstanceOf(BusinessRefused::class, $failure);
        self::assertSame(FailureBranch::BusinessRefusal, $failure->branch());
        self::assertSame('access.not_available_to_you', $failure->identifier());
    }

    #[Test]
    public function a_capability_held_lets_the_operation_through_to_the_domain(): void
    {
        $capability = Capability::named('test.do_the_thing');
        $reached = false;

        $service = $this->serviceWith(
            AuthorisationPolicy::of(['test.operation' => AuthorisationRule::requiringCapability($capability)]),
            function () use (&$reached): Result {
                $reached = true;

                return Result::succeeded();
            },
        );

        $actor = Actor::holding(ActorReference::fromString('actor-1'), [Role::user('driver', [$capability])]);
        $result = $service->execute($this->aCommand(), $actor);

        self::assertTrue($result->isSuccess());
        self::assertTrue($reached);
    }

    #[Test]
    public function a_domain_refusal_is_returned_as_the_business_refusal_branch(): void
    {
        // BE-046: the result distinguishes success from each failure class.
        $result = $this->permittedService(
            static fn (): Result => throw new BusinessRefusal(TestRefusalReason::SeatsNoLongerAvailable),
        )->execute($this->aCommand(), $this->anActor());

        self::assertTrue($result->isFailure());

        $failure = $result->failure();
        self::assertInstanceOf(BusinessRefused::class, $failure);
        self::assertSame('booking.seats_no_longer_available', $failure->identifier());
    }

    #[Test]
    public function a_platform_fault_is_never_converted_into_a_business_refusal(): void
    {
        // Negative test for BE-186 ‡ / API-074 ‡. A broad catch in the base would
        // tell the caller a defect was a final answer.
        $this->expectException(RuntimeException::class);

        $this->permittedService(static fn (): Result => throw new RuntimeException('a defect, not a decision'))
            ->execute($this->aCommand(), $this->anActor());
    }

    #[Test]
    public function a_state_changing_command_cannot_exist_without_an_idempotency_key(): void
    {
        // BE-045 / API-057 ‡.
        $command = new class implements StateChangingCommand
        {
            public function idempotencyKey(): IdempotencyKey
            {
                return IdempotencyKey::fromString('caller-generated-key');
            }

            public function contentFingerprint(): string
            {
                // API-062 ‡ / API-063 ‡: derived from the command's own values.
                return hash('sha256', 'caller-generated-key');
            }
        };

        self::assertInstanceOf(Command::class, $command);
        self::assertSame('caller-generated-key', $command->idempotencyKey()->toString());
    }

    #[Test]
    public function an_empty_idempotency_key_is_refused(): void
    {
        // Negative test for API-057 ‡.
        $this->expectException(InvalidArgumentException::class);

        IdempotencyKey::fromString('  ');
    }

    #[Test]
    public function a_failed_result_carries_no_value(): void
    {
        $result = Result::failed(new BusinessRefused(TestRefusalReason::NotEntitled));

        self::assertTrue($result->isFailure());
        self::assertFalse($result->isSuccess());

        $this->expectException(LogicException::class);
        $result->value();
    }

    #[Test]
    public function a_successful_result_carries_no_failure(): void
    {
        $result = Result::succeeded();

        self::assertTrue($result->isSuccess());

        $this->expectException(LogicException::class);
        $result->failure();
    }

    /**
     * @param  callable(): Result  $work
     */
    private function permittedService(callable $work): ApplicationService
    {
        return $this->serviceWith(
            AuthorisationPolicy::of(['test.operation' => AuthorisationRule::permittingAnyAuthenticatedActor()]),
            $work,
        );
    }

    /**
     * @param  callable(): Result  $work
     */
    private function serviceWith(AuthorisationPolicy $policy, callable $work): ApplicationService
    {
        return new class(new Authoriser($policy, new RecordedRefusals), $work) extends ApplicationService
        {
            /** @var callable(): Result */
            private $work;

            /**
             * @param  callable(): Result  $work
             */
            public function __construct(Authoriser $authoriser, callable $work)
            {
                parent::__construct($authoriser);

                $this->work = $work;
            }

            public function operation(): Operation
            {
                return Operation::named('test.operation');
            }

            protected function handle(Command $command, Actor $actor): Result
            {
                return ($this->work)();
            }
        };
    }

    private function aCommand(): Command
    {
        return new class implements Command {};
    }

    private function anActor(): Actor
    {
        return Actor::holding(ActorReference::fromString('actor-1'), []);
    }
}
