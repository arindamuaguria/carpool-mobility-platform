<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tests\ObligationRegister;

/**
 * `TC-200` ‡ — gate 4 requires every non-suppressible obligation passing, with no
 * exception.
 *
 * This does **not** assert that the gate is met. It is not, and asserting it were
 * would be the misrepresentation `TC-186` ‡ names. What it asserts is that the
 * platform can say, exactly and without anybody compiling a list by hand, **which**
 * non-suppressible obligations do not pass and why — so that a green suite cannot
 * be mistaken for a passed gate.
 *
 * `TR-121` states the same thing from the release side: *"a passing suite is not
 * readiness."*
 *
 * ## What the gate stands at
 *
 * Twelve of the twenty-five hold. Thirteen do not, and **every one of the thirteen
 * is `BLOCKED`** — an authoritative prerequisite nobody has resolved — rather than
 * `ABSENT`. That distinction is the useful part of the answer: nothing on this
 * list is waiting on engineering. It is asserted below, so that the day one of
 * them becomes work rather than a decision, the change is visible rather than
 * silent.
 *
 * `TC-201` records that **what else constitutes a release gate is
 * `[TBD – Business Decision Required]`** — the Project Owner's, not this
 * document's. So `TC-200` ‡ is the only gate criterion that exists, and nothing
 * here invents a second.
 *
 * @phpstan-import-type Obligation from ObligationRegister
 */
final class ReleaseGateTest extends TestCase
{
    public function test_the_gate_can_name_every_non_suppressible_obligation_that_does_not_pass(): void
    {
        // TC-200 ‡ is checkable only if the answer is derivable. A gate whose
        // criterion nobody can evaluate is a gate that gets waved through.
        $outstanding = self::outstanding();

        self::assertNotSame(
            [],
            $outstanding,
            'The gate reports itself met. That is a claim about 25 obligations, and it should be read '
            .'carefully rather than trusted — check each against its proving test.',
        );

        foreach ($outstanding as $reference => $obligation) {
            self::assertNotSame(
                '',
                trim($obligation['note']),
                'TC-200 ‡: '.$reference.' holds the gate closed and does not say why.',
            );
        }
    }

    public function test_nothing_holding_the_gate_closed_is_merely_unbuilt(): void
    {
        // The distinction that makes the report worth reading. An obligation that
        // is BLOCKED waits on a decision somebody else owns; one that is ABSENT
        // waits on work. Today every one of the thirteen is blocked, and this
        // fails the moment that stops being true — which is exactly when somebody
        // should be told there is work to do.
        $unbuilt = array_filter(
            self::outstanding(),
            static fn (array $obligation): bool => $obligation['status'] === ObligationRegister::ABSENT,
        );

        self::assertSame(
            [],
            $unbuilt,
            'TC-200 ‡: a non-suppressible obligation is buildable and unbuilt. It holds gate 4 closed and '
            .'nothing external is stopping it.',
        );
    }

    public function test_no_non_suppressible_obligation_is_withheld(): void
    {
        // TADR-13 forbids a test for a withheld capability, and TC-021 ‡ makes
        // the twenty-five non-suppressible. An obligation that was both would be
        // a gate criterion that can never be met and must never be met — a
        // contradiction that should be reported rather than carried.
        foreach (self::nonSuppressible() as $reference => $obligation) {
            self::assertNotSame(
                ObligationRegister::WITHHELD,
                $obligation['status'],
                $reference.': TC-021 ‡ requires it to pass and TADR-13 forbids testing it. One is wrong.',
            );
        }
    }

    public function test_every_non_suppressible_obligation_that_passes_names_its_test(): void
    {
        // TC-200 ‡ admits no exception, so a claim that one of the twenty-five
        // passes carries more weight than the same claim about the other
        // seventy-four. ObligationRegisterTest already requires an enforced entry
        // to name a class that exists; this says it again for the set where being
        // wrong matters most.
        foreach (self::nonSuppressible() as $reference => $obligation) {
            if ($obligation['status'] !== ObligationRegister::ENFORCED) {
                continue;
            }

            self::assertIsString(
                $obligation['provenBy'],
                $reference.' is non-suppressible, claims to pass, and names nothing that proves it.',
            );
            self::assertTrue(class_exists($obligation['provenBy']), $reference.' names a test that is gone.');
        }
    }

    public function test_the_gate_is_the_only_criterion_the_documentation_states(): void
    {
        // TC-201: "what else constitutes a release gate is [TBD – Business
        // Decision Required] — the Project Owner's, not this document's."
        // TC-202 records CMP-DOC-01 §9.4's unresolved recommendation as the
        // natural input to it.
        //
        // So there is one criterion, and this file states no second. Asserted by
        // counting what this class checks against: the non-suppressible set and
        // nothing else.
        $checked = array_keys(self::nonSuppressible());

        self::assertCount(25, $checked, 'TC-021 ‡ / TADR-06: the gate is over exactly twenty-five.');
    }

    /**
     * The twenty-five (`TC-021` ‡, `TADR-06`).
     *
     * @return array<string, Obligation>
     */
    private static function nonSuppressible(): array
    {
        return array_filter(
            ObligationRegister::all(),
            static fn (array $obligation): bool => $obligation['suppressible'] === false,
        );
    }

    /**
     * The non-suppressible obligations that do not pass — what holds gate 4 shut.
     *
     * @return array<string, Obligation>
     */
    private static function outstanding(): array
    {
        return array_filter(
            self::nonSuppressible(),
            static fn (array $obligation): bool => $obligation['status'] !== ObligationRegister::ENFORCED,
        );
    }
}
