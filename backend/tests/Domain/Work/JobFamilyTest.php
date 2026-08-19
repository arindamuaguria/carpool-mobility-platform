<?php

declare(strict_types=1);

namespace Tests\Domain\Work;

use Cmp\Application\Shared\Work\JobFamily;
use Tests\Domain\DomainTestCase;

/**
 * CMP-IMP-027 — the seven job families.
 *
 * Level 2 (`TC-029` ‡). Which families exist, and in what order, is decidable
 * without a database. That each family really has its own queue in the deployed
 * schema is level 3's.
 */
final class JobFamilyTest extends DomainTestCase
{
    /**
     * The seven of `BADR-07` §11.1, in the document's own priority order.
     */
    private const EXPECTED = [
        'safety',
        'payment-verification',
        'notification',
        'projection',
        'reconciliation',
        'scheduled-generation',
        'maintenance',
    ];

    public function test_exactly_seven_families_are_declared_and_no_undeclared_eighth(): void
    {
        // BE-131.
        self::assertCount(7, JobFamily::cases());
        self::assertSame(
            self::EXPECTED,
            array_map(static fn (JobFamily $f): string => $f->value, JobFamily::cases()),
        );
    }

    public function test_safety_is_the_highest_priority_family(): void
    {
        // BE-132 ‡. BADR-07 rejected a single default queue precisely because
        // safety work would queue behind projection rebuilds under load,
        // breaching SRS-REQ-060.
        self::assertSame(1, JobFamily::Safety->priority());
        self::assertTrue(JobFamily::Safety->isSafety());

        foreach (JobFamily::cases() as $family) {
            if ($family === JobFamily::Safety) {
                continue;
            }

            self::assertGreaterThan(
                JobFamily::Safety->priority(),
                $family->priority(),
                $family->value.' must not be taken before safety.',
            );
            self::assertFalse($family->isSafety());
        }
    }

    public function test_maintenance_is_the_lowest_priority_family(): void
    {
        self::assertSame(7, JobFamily::Maintenance->priority());
    }

    public function test_every_family_binds_to_its_own_queue(): void
    {
        // BE-133: a family is drainable and pausable on its own, which requires
        // it not to share a queue with another.
        $queues = array_map(static fn (JobFamily $f): string => $f->queue(), JobFamily::cases());

        self::assertSame($queues, array_unique($queues));
        self::assertCount(7, $queues);
    }

    public function test_the_worker_queue_list_takes_the_families_in_priority_order(): void
    {
        // A worker consuming several families takes them in the order given, so
        // the order in this string is BE-132 ‡ in operational form.
        self::assertSame(implode(',', self::EXPECTED), JobFamily::allQueuesInPriorityOrder());
    }

    public function test_the_priority_order_is_the_declaration_order(): void
    {
        // Two sources of the order would be one too many.
        self::assertSame(JobFamily::cases(), JobFamily::inPriorityOrder());
    }
}
