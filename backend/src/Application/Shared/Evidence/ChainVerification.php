<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Evidence;

/**
 * What a verification pass found.
 *
 * `BE-115` / `DB-115` / `SEC-111`: a pass re-derives the chain and **reports**
 * the first divergence, with the record at which it occurred.
 *
 * `SEC-112` ‡ and `DB-115` are emphatic that it stops there: *"Verification shall
 * never repair a divergence; a break is a finding, not a fault to correct."*
 * There is accordingly nothing on this class that could be acted on
 * automatically — it carries a record identifier and a description, and the
 * decision about what a break means is a person's.
 */
final class ChainVerification
{
    private function __construct(
        private readonly int $recordsVerified,
        private readonly ?int $divergedAtRecord,
        private readonly ?string $divergence,
    ) {}

    public static function intact(int $recordsVerified): self
    {
        return new self($recordsVerified, null, null);
    }

    /**
     * @param  int  $recordId  the record at which the chain first diverged
     *                         (`SEC-111`)
     */
    public static function diverged(int $recordsVerified, int $recordId, string $divergence): self
    {
        return new self($recordsVerified, $recordId, $divergence);
    }

    public function isIntact(): bool
    {
        return $this->divergedAtRecord === null;
    }

    public function recordsVerified(): int
    {
        return $this->recordsVerified;
    }

    public function divergedAtRecord(): ?int
    {
        return $this->divergedAtRecord;
    }

    public function divergence(): ?string
    {
        return $this->divergence;
    }

    public function describe(): string
    {
        if ($this->isIntact()) {
            return sprintf('%d records verified; the chain is intact.', $this->recordsVerified);
        }

        return sprintf(
            '%d records verified; the chain first diverges at record %d: %s. '
            .'SEC-112 ‡: this is a finding, not a fault to correct.',
            $this->recordsVerified,
            (int) $this->divergedAtRecord,
            (string) $this->divergence,
        );
    }
}
