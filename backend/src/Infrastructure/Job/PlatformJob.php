<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Job;

use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Application\Shared\Idempotency\IdempotencyKey;
use Cmp\Application\Shared\Idempotency\IdempotentOperation;
use Cmp\Application\Shared\Result;
use Cmp\Application\Shared\StateChangingCommand;
use Cmp\Application\Shared\Work\JobFamily;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use JsonException;
use LogicException;

/**
 * The base every job extends.
 *
 * `BE-134`: a job **invokes an application service and contains no business
 * rule**. `perform()` is where the service is called, and the rule it enforces
 * lives in exactly one Domain component (`BE-010`) — never here, where it would
 * be a second copy running outside the transaction that enforced the first.
 *
 * `BE-135` ‡: **a job is safe to execute more than once.** A queue redelivers
 * after a worker dies mid-run, after a timeout, and after a deploy; a job that
 * assumed one execution would double-charge a passenger the first time a worker
 * was killed. Safety is not left to each job's author: the run goes through
 * {@see IdempotentOperation}, so a second execution with the same key replays
 * the recorded outcome instead of repeating the work (`API-062` ‡).
 *
 * `BE-136`: the outcome is recorded — by the registry (`DB-143`), in the same
 * transaction as the effect (`BE-051` ‡). The evidential record `BE-107` also
 * asks for arrives with the evidential writer, `CMP-IMP-439`.
 *
 * `BE-140`: the identity the job acts for is carried **explicitly**, as a value
 * on the job. Nothing here reads an ambient session; a worker has none.
 *
 * `BE-141`: a job depends on no memory state of the process that enqueued it.
 * {@see payload()} is everything it needs, and it is serialised into the store.
 *
 * `BE-050` ‡ / `BE-052`: **no external provider call occurs inside a
 * transaction.** {@see prepare()} runs first, outside the transactional scope,
 * and is where a job needing a provider result obtains it. That ordering is a
 * property of this base, not a rule each job has to remember.
 *
 * `BE-139` puts attempt counts and backoff intervals in configuration rather
 * than code, which needs the policy store (`CMP-IMP-031`). Until then `$tries`
 * is deliberately unset: the framework default of a single attempt cannot
 * silently re-run work, which is the safer way to be wrong.
 */
abstract class PlatformJob implements ShouldQueue, StateChangingCommand
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly string $actorReference,
        private readonly string $idempotencyKeyValue,
    ) {
        // BE-131 / BE-132 ‡: a job is bound to its family's queue, and there is
        // no path by which it lands anywhere else.
        $this->onQueue($this->family()->queue());
    }

    /**
     * The family this job belongs to (`BE-131`).
     */
    abstract public function family(): JobFamily;

    /**
     * The operation the idempotency key is scoped to (`API-060`).
     */
    abstract public function operationName(): string;

    /**
     * Everything the job needs, as scalars.
     *
     * `BE-141`: it depends on no memory state of the process that enqueued it.
     * `BE-201` ‡ applies to what is put here — no payment credential, no precise
     * location, no contact detail — because a payload is readable by anyone who
     * can read the job store.
     *
     * @return array<string, scalar|null>
     */
    abstract protected function payload(): array;

    /**
     * Calls the application service (`BE-134`).
     *
     * Runs **inside** the transaction, so it must not call an external provider
     * (`BE-050` ‡). A job needing a provider result obtains it in
     * {@see prepare()}.
     *
     * @return Result its success value is an array<string, mixed> or null, the
     *                representation `DB-143` records
     */
    abstract protected function perform(): Result;

    /**
     * Obtains anything that must be had before the transaction opens.
     *
     * `BE-052`: a service requiring a provider result before persisting obtains
     * it before opening the transaction. `BE-050` ‡ makes that absolute — a
     * provider call inside a transactional scope holds a row lock for as long as
     * a third party takes to answer.
     */
    protected function prepare(): void
    {
        //
    }

    final public function handle(IdempotentOperation $operation): void
    {
        // Outside the transaction (BE-050 ‡, BE-052).
        $this->prepare();

        $operation->execute(
            $this,
            $this->operationName(),
            ActorReference::fromString($this->actorReference),
            fn (): Result => $this->perform(),
        );
    }

    final public function idempotencyKey(): IdempotencyKey
    {
        return IdempotencyKey::fromString($this->idempotencyKeyValue);
    }

    /**
     * `API-062` ‡ / `API-063` ‡: a repeat with the same key and the same content
     * replays; one with the same key and different content is refused. The
     * fingerprint is derived from what the job actually carries.
     */
    final public function contentFingerprint(): string
    {
        try {
            $content = json_encode(
                ['operation' => $this->operationName(), 'payload' => $this->payload()],
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new LogicException(
                'BE-141: a job payload must be serialisable, or the job depends on process memory.',
                previous: $exception,
            );
        }

        return hash('sha256', $content);
    }

    /**
     * The identity the job acts for (`BE-140`).
     */
    final public function actorReference(): ActorReference
    {
        return ActorReference::fromString($this->actorReference);
    }
}
