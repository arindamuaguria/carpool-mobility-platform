<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Laravel\Providers;

use Cmp\Application\Shared\Policy\ChangePolicyValue;
use Cmp\Application\Shared\Policy\PolicyCache;
use Cmp\Application\Shared\Policy\RecordsPolicyChanges;
use Cmp\Application\Shared\Transaction\TransactionBoundary;
use Cmp\Domain\Shared\Policy\PolicyRegister;
use Cmp\Domain\Shared\Policy\PolicyStore;
use Cmp\Domain\Shared\StateMachine\StateMachine;
use Cmp\Domain\Shared\StateMachine\StateModelRepository;
use Cmp\Domain\Shared\Time\Clock;
use Cmp\Infrastructure\Persistence\Policy\DatabasePolicyChangeRecorder;
use Cmp\Infrastructure\Persistence\Policy\DatabasePolicyStore;
use Cmp\Infrastructure\Persistence\StateMachine\DatabaseStateModelRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\ServiceProvider;

/**
 * The one place a policy value is declared, and the composition root for the
 * policy store.
 *
 * `DB-153` ‡: *"a policy value shall never be **capable** of relaxing an absolute
 * rule; values that could are **absent from the table** rather than validated."*
 * {@see declaredValues()} is that table. A key absent from it cannot be read, cannot
 * be written, and has no default — `DatabasePolicyStore` raises rather than
 * falling back, and `ChangePolicyValue` raises before it opens a transaction.
 */
final class PolicyServiceProvider extends ServiceProvider
{
    /**
     * The platform's declared policy values.
     *
     * **Empty.** CMP-DOC-09 §13.2 lists eleven values that will be held as policy
     * configuration, and says of them: *"Their **existence** is architecture;
     * their **values** are not invented here."* A key is declared on the commit
     * that gives something the code to read it — declaring one earlier would
     * create the accessor `BADR-12` says must not exist, for behaviour nothing
     * yet performs.
     *
     * | §13.2 value | Why it is not declared |
     * |---|---|
     * | Cancellation window and consequence | Cancellation consequences are **withheld**; `FRD-GAP-008` is one of the two live product defects. |
     * | Commission or platform fee treatment | `BAD-DEC-003`/`BAD-DEC-004` are open; no fare or settlement model exists. |
     * | Verification levels required per action | `BAD-DEC-005` is open; `FRD-GAP-002`. |
     * | Rating thresholds and their effects | Ratings is a **withheld area** with zero functional requirements (CMP-DOC-04 §9.2). A key for it would be an affordance `ADM-187`/`ADM-191` forbid. |
     * | Refund eligibility and window | Refunds are **withheld**; `FRD-GAP-009` is the second live product defect. |
     * | Search radius and result limits | FEAT-011 does not exist. |
     * | Route-overlap acceptance threshold | `ARCH-OQ-001`; `T6` was expressly excluded from the ratified technical decisions. |
     * | Job attempt counts and backoff | `BE-139`; nothing reads it until a job declares tries. |
     * | Scheduled work frequency | `BE-148`; nothing is scheduled (`CMP-IMP-029`). |
     * | Retention periods per data category | `BAD-DEC-021` is open; 8 of 9 periods unset; `GAP-012`. |
     * | Provider timeout and circuit thresholds | `BE-157`; no adapter exists (`CMP-IMP-030`). |
     *
     * Six of these are blocked on a business decision, two on a withheld area,
     * and three on code that does not exist. None is blocked on this store.
     */
    public static function declaredValues(): PolicyRegister
    {
        return PolicyRegister::of();
    }

    public function register(): void
    {
        $this->app->singleton(PolicyRegister::class, static fn (): PolicyRegister => self::declaredValues());

        $this->app->singleton(DatabasePolicyStore::class, fn (Application $app): DatabasePolicyStore => new DatabasePolicyStore(
            $app->make(ConnectionResolverInterface::class)->connection(PersistenceServiceProvider::APPLICATION_CONNECTION),
            $app->make(PolicyRegister::class),
        ));

        // BE-168: one accessor. The store and the cache are the same object, so
        // an invalidation cannot miss a second cache nobody remembered.
        $this->app->bind(PolicyStore::class, static fn (Application $app): DatabasePolicyStore => $app->make(DatabasePolicyStore::class));
        $this->app->bind(PolicyCache::class, static fn (Application $app): DatabasePolicyStore => $app->make(DatabasePolicyStore::class));

        $this->app->bind(
            RecordsPolicyChanges::class,
            fn (Application $app): DatabasePolicyChangeRecorder => new DatabasePolicyChangeRecorder(
                $app->make(ConnectionResolverInterface::class)->connection(PersistenceServiceProvider::APPLICATION_CONNECTION),
                $app->make(Clock::class),
            ),
        );

        $this->app->bind(ChangePolicyValue::class, static fn (Application $app): ChangePolicyValue => new ChangePolicyValue(
            $app->make(TransactionBoundary::class),
            $app->make(PolicyRegister::class),
            $app->make(RecordsPolicyChanges::class),
            $app->make(PolicyCache::class),
        ));

        // BE-175: one engine, reading declared definitions. BE-177 ‡: the
        // invariants that hold irrespective of the declared model are code, and
        // are constructed into the engine here.
        //
        // The list is empty. The three BADR-13 names by way of example —
        // no trip without a confirmed booking (SRS-REQ-159 ‡), no case closed
        // without an outcome (SRS-REQ-160 ‡), payment restricted to three states
        // (SRS-REQ-155 ‡) — all concern aggregates that do not exist yet
        // (BE-017). Each arrives with its aggregate, in the change that makes it
        // enforceable.
        $this->app->singleton(StateMachine::class, static fn (): StateMachine => new StateMachine([]));

        $this->app->singleton(
            DatabaseStateModelRepository::class,
            fn (Application $app): DatabaseStateModelRepository => new DatabaseStateModelRepository(
                $app->make(ConnectionResolverInterface::class)->connection(PersistenceServiceProvider::APPLICATION_CONNECTION),
            ),
        );

        $this->app->bind(
            StateModelRepository::class,
            static fn (Application $app): DatabaseStateModelRepository => $app->make(DatabaseStateModelRepository::class),
        );
    }
}
