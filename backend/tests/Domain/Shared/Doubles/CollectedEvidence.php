<?php

declare(strict_types=1);

namespace Tests\Domain\Shared\Doubles;

use Cmp\Application\Shared\Evidence\Evidence;
use Cmp\Application\Shared\Evidence\EvidenceNotRecorded;
use Cmp\Application\Shared\Evidence\RecordsEvidence;

/**
 * An evidential writer that keeps what it was given, and can be told to fail.
 *
 * `TC-029` ‡ keeps level-2 tests off the database. What a record **contains** is
 * decidable here; whether it reaches `ev_` and chains correctly is level 3's,
 * and is not simulated.
 *
 * This is a **test double and not a second writer.** `BE-105` ‡ and `TC-037`
 * rule 6 concern the one component that reaches `ev_evidential_records`, which
 * `EvidentialLogRulesTest` enforces over `src` alone. Nothing here touches the
 * table.
 */
final class CollectedEvidence implements RecordsEvidence
{
    /** @var list<Evidence> */
    public array $records = [];

    private bool $failing = false;

    /**
     * Makes every subsequent write fail, so that `FRD-FR-248` ‡ can be tested:
     * an action is not reported complete where its record cannot be written.
     */
    public function failEveryWrite(): void
    {
        $this->failing = true;
    }

    public function record(Evidence $evidence): void
    {
        if ($this->failing) {
            throw new EvidenceNotRecorded('The test double was told to fail this write.');
        }

        $this->records[] = $evidence;
    }

    public function only(): Evidence
    {
        if (count($this->records) !== 1) {
            throw new \RuntimeException(sprintf('Expected exactly one record, found %d.', count($this->records)));
        }

        return $this->records[0];
    }
}
