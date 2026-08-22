<?php

declare(strict_types=1);

namespace Cmp\Application\Safety;

use Cmp\Application\Shared\Command;
use Cmp\Application\Shared\StateChangingCommand;
use Cmp\Application\User\AuthenticatedCaller;
use Cmp\Domain\User\UserReference;

/**
 * Read one's own incident — CMP-DOC-10 §12.1's `GET /incidents/{id}`.
 *
 * Not a {@see StateChangingCommand}: it changes nothing,
 * so it carries no idempotency key. The reference arrives as a **string**,
 * because the interface layer cannot build a Domain type (`BE-002`) and must not
 * decide that a value is well-formed (`API-041`).
 */
final class ReadOwnIncidentCommand implements Command
{
    private function __construct(
        private readonly UserReference $reader,
        private readonly string $reference,
    ) {}

    public static function from(AuthenticatedCaller $caller, string $reference): self
    {
        return new self($caller->session()->user(), $reference);
    }

    public function reader(): UserReference
    {
        return $this->reader;
    }

    public function reference(): string
    {
        return $this->reference;
    }
}
