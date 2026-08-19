<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Work;

/**
 * The seven job families of `BADR-07`, and **no undeclared eighth** (`BE-131`).
 *
 * Each family has its own queue, so that a family can be drained and paused
 * independently of the others (`BE-133`) and workers can be assigned per family
 * as a deployment decision rather than a code change (`BADR-07`).
 *
 * `BE-132` ‡ is why the separation exists at all: **safety work never queues
 * behind work of any other family.** `BADR-07` records the rejected
 * alternative — a single default queue, on which safety work would queue behind
 * projection rebuilds under load, breaching `SRS-REQ-060`.
 *
 * The order of the cases is the priority order. A worker consuming more than one
 * family takes them in this order, so safety is always drained first.
 */
enum JobFamily: string
{
    /** Emergency dispatch, contact notification, escalation. Highest. */
    case Safety = 'safety';

    /** Provider confirmation, status resolution. High. */
    case PaymentVerification = 'payment-verification';

    /** Push and message delivery. Normal. */
    case Notification = 'notification';

    /** Read model maintenance. Normal. */
    case Projection = 'projection';

    /** Pending resolution, chain verification, integrity sweeps. Normal. */
    case Reconciliation = 'reconciliation';

    /**
     * Recurring ride instantiation. Normal.
     *
     * **The family is declared; the work is not built.** `BE-131` requires the
     * seven families to exist. Recurring commute carries **zero functional
     * requirements** (CMP-DOC-04 §9.2), so `BE-143` and `BE-144` ‡ have nothing
     * to realise and nothing may be enqueued here. Declaring a queue name is not
     * building a feature, and the family is present so that an eighth is never
     * invented when the requirements arrive.
     */
    case ScheduledGeneration = 'scheduled-generation';

    /** Retention enforcement, housekeeping. Lowest. */
    case Maintenance = 'maintenance';

    /**
     * The families in priority order — the order a worker consuming several of
     * them takes them in (`BE-132` ‡).
     *
     * @return list<self>
     */
    public static function inPriorityOrder(): array
    {
        return self::cases();
    }

    /**
     * The queue this family binds to.
     *
     * The queue name is the family's own value: one family, one queue, and no
     * mapping table for a mistake to hide in.
     */
    public function queue(): string
    {
        return $this->value;
    }

    /**
     * Where this family sits in the priority order — 1 is highest.
     */
    public function priority(): int
    {
        return array_search($this, self::inPriorityOrder(), true) + 1;
    }

    /**
     * `BE-132` ‡: safety work is not delayed by any other family's volume.
     */
    public function isSafety(): bool
    {
        return $this === self::Safety;
    }

    /**
     * The `--queue` argument for a worker taking every family in priority order.
     */
    public static function allQueuesInPriorityOrder(): string
    {
        return implode(',', array_map(
            static fn (self $family): string => $family->queue(),
            self::inPriorityOrder(),
        ));
    }
}
