<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Laravel\Providers;

use Cmp\Application\Shared\Evidence\RecordsEvidence;
use Cmp\Application\Shared\Policy\ChangePolicyValue;
use Cmp\Application\Shared\Policy\PolicyCache;
use Cmp\Application\Shared\Policy\RecordsPolicyChanges;
use Cmp\Application\Shared\StateMachine\ApplyTransition;
use Cmp\Application\Shared\Transaction\TransactionBoundary;
use Cmp\Application\User\ResolveSession;
use Cmp\Domain\Shared\Policy\PolicyKey;
use Cmp\Domain\Shared\Policy\PolicyRegister;
use Cmp\Domain\Shared\Policy\PolicyStore;
use Cmp\Domain\Shared\Policy\PolicyType;
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
     * **One key, declared on 2026-08-20 with the code that reads it** —
     * {@see sessionLifetime()}, for `SEC-039` ‡. That is the rule this register
     * follows: a key is declared on the commit that gives something the code to
     * read it, because declaring one earlier would create the accessor `BADR-12`
     * says must not exist, for behaviour nothing yet performs.
     *
     * CMP-DOC-09 §13.2 lists eleven **further** values that will be held as
     * policy configuration, and says of them: *"Their **existence** is
     * architecture; their **values** are not invented here."* None is declared,
     * and each row below says why.
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
        return PolicyRegister::of(self::sessionLifetime());
    }

    /**
     * `SEC-039` ‡ / `API-104` — how long a session remains usable.
     *
     * The first key this register has ever held, and it is here rather than in
     * `UserServiceProvider` because `DB-153` ‡ makes {@see declaredValues()} *the*
     * table: a key absent from it cannot be read, cannot be written and has no
     * default, and a second declaration point would defeat that.
     *
     * **Declared, and deliberately unset.** `SEC-039` ‡ was decided on 2026-08-20
     * as twenty-four hours, and the figure is recorded in CMP-DOC-13 rather than
     * here — `BADR-12` applies a value by an **operator action** that `BE-173`
     * evidences, and `BE-171` keeps policy configuration out of deployment
     * configuration. Until an operator applies `86400`, `DatabasePolicyStore`
     * raises `PolicyNotSet` and every session resolution fails, which is
     * `SRS-REQ-158` working rather than a defect.
     *
     * `SEC-017` (ten minutes) and `SEC-049` (three) were decided at the same time
     * and are **not** declared: nothing reads either yet, and this file's own rule
     * is that a key arrives with its reader. `SEC-049` has a further reason —
     * what the platform does when the limit is reached is not stated by any
     * requirement.
     */
    public static function sessionLifetime(): PolicyKey
    {
        return PolicyKey::of(
            ResolveSession::LIFETIME_KEY,
            PolicyType::Duration,
            'How long an established session remains usable, measured from establishment (SEC-039 ‡). Read by '
            .'ResolveSession on every request, so BE-170 lets a shortened bound apply to sessions already '
            .'established. It cannot relax an absolute rule (BE-172 ‡): it sets the width of a window SEC-039 ‡ '
            .'requires to exist and to be bounded, and cannot remove the bound.',
        );
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
            // ARCH-115 / DB-154 / BE-173: a policy change is an evidential
            // record, written in the same transaction (BE-106 ‡).
            $app->make(RecordsEvidence::class),
            $app->make(Clock::class),
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

        // BE-178: a transition is evidenced with its trigger and actor. The
        // engine is Domain and depends on nothing (BE-001–BE-003), so the
        // joining of a transition to its evidence is an application-layer
        // service rather than something the engine does.
        $this->app->bind(ApplyTransition::class, static fn (Application $app): ApplyTransition => new ApplyTransition(
            $app->make(StateMachine::class),
            $app->make(RecordsEvidence::class),
            $app->make(Clock::class),
        ));

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
