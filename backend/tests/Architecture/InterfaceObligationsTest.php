<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tests\InterfaceObligations;

/**
 * `API-213` — the register is complete, and truthful about itself.
 *
 * *"Every integrity-critical statement in this document shall have an automated
 * contract test."* {@see InterfaceObligations} answers it, and this checks the
 * answer: that the register holds **every** ‡ statement CMP-DOC-10 declares and
 * no statement it does not, that an entry claiming `enforced` names a test class
 * that exists, and that an entry claiming anything else says what stands in the
 * way.
 *
 * The membership check is the important one. A register that chose which
 * obligations to include would be a register asserting its own completeness —
 * the same failure `ObligationRegisterTest` and `DocumentObligationsTest` exist
 * to prevent for their own registers.
 */
final class InterfaceObligationsTest extends TestCase
{
    /**
     * CMP-DOC-10 as issued: 216 statements, of which 100 are integrity-critical.
     *
     * Stated rather than counted by a reader, for `IntegrityConstraintRegisterTest`'s
     * reason — a figure a reader has to derive is one nobody checks.
     */
    private const MARKED = 100;

    public function test_the_register_holds_every_marked_statement_the_document_declares(): void
    {
        $declared = self::markedInTheDocument();

        self::assertCount(self::MARKED, $declared, 'CMP-DOC-10 declares '.self::MARKED.' ‡ statements.');

        self::assertSame(
            $declared,
            array_keys(InterfaceObligations::all()),
            'API-213: the register holds every ‡ statement CMP-DOC-10 declares, in the document\'s order, and no '
            .'statement it does not.',
        );
    }

    public function test_the_membership_check_would_notice_a_statement_that_does_not_exist(): void
    {
        // TC-041: the check above passes trivially if reading the document
        // produced nothing. This is the detector on its own terms.
        $declared = self::markedInTheDocument();

        self::assertNotSame([], $declared);
        self::assertContains('API-037', $declared, 'API-037 ‡ is marked in the document and must be found.');
        self::assertNotContains('API-213', $declared, 'API-213 carries no ‡ of its own — it is the obligation.');
        self::assertNotContains('API-216', $declared, 'API-216 is [TBD] and unmarked.');
    }

    public function test_an_enforced_statement_names_a_test_that_exists(): void
    {
        // The rule this register shares with every other on the platform: an
        // entry may claim `enforced` only by naming a class that exists. A
        // register citing a test nobody wrote would be worse than no register.
        foreach (InterfaceObligations::all() as $id => $obligation) {
            if ($obligation['status'] !== 'enforced') {
                continue;
            }

            self::assertNotNull($obligation['provenBy'], $id.' is enforced and names nothing that proves it.');
            self::assertTrue(
                class_exists($obligation['provenBy']),
                $id.' names '.$obligation['provenBy'].', which does not exist.',
            );
        }
    }

    public function test_a_statement_that_is_not_enforced_says_what_stands_in_the_way(): void
    {
        foreach (InterfaceObligations::all() as $id => $obligation) {
            if ($obligation['status'] === 'enforced') {
                continue;
            }

            self::assertNull(
                $obligation['provenBy'],
                $id.' is not enforced and names a test that proves it, which cannot both be true.',
            );

            self::assertNotSame('', trim($obligation['note']), $id.' does not say why it is '.$obligation['status'].'.');
        }
    }

    public function test_every_statement_carries_a_note_and_one_of_the_five_statuses(): void
    {
        foreach (InterfaceObligations::all() as $id => $obligation) {
            self::assertContains(
                $obligation['status'],
                InterfaceObligations::STATUSES,
                $id.' carries a status outside CC-038\'s five.',
            );

            self::assertNotSame('', trim($obligation['note']), $id.' carries no note.');
        }
    }

    public function test_the_counts_are_stated_rather_than_left_for_a_reader_to_derive(): void
    {
        // If this fails, the register moved and the figure in its class note and
        // in CC-049 no longer describes it.
        self::assertSame(
            ['enforced' => 59, 'absent' => 3, 'blocked' => 35, 'withheld' => 0, 'not_applicable' => 3],
            InterfaceObligations::counts(),
        );

        self::assertSame(self::MARKED, array_sum(InterfaceObligations::counts()));
    }

    public function test_the_unproven_statements_are_the_three_that_await_a_second_release_or_a_rule(): void
    {
        // `absent` means the statement's **subject exists** and nothing asserts
        // it — a defect here rather than a consequence of the chain. Naming them
        // is the point of the register, so they are named.
        self::assertSame(
            ['API-020', 'API-030', 'API-040'],
            InterfaceObligations::unproven(),
            'The absent set has moved. If one was closed, close its row too; if one was added, it is a finding.',
        );
    }

    /**
     * Every `API-nnn` CMP-DOC-10 marks ‡, in the order the document states them.
     *
     * Read from the document rather than from a list kept beside it, because a
     * list kept beside it is a list that drifts.
     *
     * @return list<string>
     */
    private static function markedInTheDocument(): array
    {
        $path = dirname(__DIR__, 3).'/Document/10_API_Specification/DOC-10-API-CMP-API-Specification.md';
        $contents = file_get_contents($path);

        self::assertIsString($contents, 'CMP-DOC-10 is missing.');

        preg_match_all('/^\| `(API-\d+)` ‡ \|/m', $contents, $matches);

        /** @var list<string> $ids */
        $ids = array_values(array_unique($matches[1]));

        return $ids;
    }
}
