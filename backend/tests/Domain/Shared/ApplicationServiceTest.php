<?php

declare(strict_types=1);

namespace Tests\Domain\Shared;

use Cmp\Application\Shared\ApplicationService;
use Cmp\Application\Shared\Command;
use Cmp\Application\Shared\Failure\BusinessRefused;
use Cmp\Application\Shared\Failure\FailureBranch;
use Cmp\Application\Shared\Idempotency\IdempotencyKey;
use Cmp\Application\Shared\Result;
use Cmp\Application\Shared\StateChangingCommand;
use Cmp\Domain\Shared\Refusal\BusinessRefusal;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Domain\DomainTestCase;
use Tests\Domain\Shared\Doubles\TestRefusalReason;

/**
 * CMP-IMP-023 — the application service base with command and result types.
 *
 * Level 2 (`TC-029` ‡): no database, no framework, no network. That this suite
 * can exercise an application service at all is itself the evidence for `BE-043`
 * — the operation is invocable without HTTP context.
 */
final class ApplicationServiceTest extends DomainTestCase
{
    #[Test]
    public function a_service_is_invocable_without_any_transport_context(): void
    {
        // BE-043.
        $service = new class extends ApplicationService
        {
            protected function handle(Command $command): Result
            {
                return Result::success('done');
            }
        };

        $result = $service->execute(new class implements Command {});

        self::assertTrue($result->isSuccess());
        self::assertSame('done', $result->value());
    }

    #[Test]
    public function a_domain_refusal_is_returned_as_the_business_refusal_branch(): void
    {
        // BE-046: the result distinguishes success from each failure class.
        $service = new class extends ApplicationService
        {
            protected function handle(Command $command): Result
            {
                throw new BusinessRefusal(TestRefusalReason::SeatsNoLongerAvailable);
            }
        };

        $result = $service->execute(new class implements Command {});

        self::assertTrue($result->isFailure());

        $failure = $result->failure();
        self::assertInstanceOf(BusinessRefused::class, $failure);
        self::assertSame(FailureBranch::BusinessRefusal, $failure->branch());
        self::assertSame('booking.seats_no_longer_available', $failure->identifier());
    }

    #[Test]
    public function a_platform_fault_is_never_converted_into_a_business_refusal(): void
    {
        // Negative test for BE-186 ‡ / API-074 ‡. A broad catch in the base would
        // tell the caller a defect was a final answer.
        $service = new class extends ApplicationService
        {
            protected function handle(Command $command): Result
            {
                throw new RuntimeException('a defect, not a decision');
            }
        };

        $this->expectException(RuntimeException::class);

        $service->execute(new class implements Command {});
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
}
