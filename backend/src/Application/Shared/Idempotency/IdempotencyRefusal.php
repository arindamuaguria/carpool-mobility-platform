<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Idempotency;

use Cmp\Domain\Shared\Refusal\RefusalKind;
use Cmp\Domain\Shared\Refusal\RefusalReason;

/**
 * Refusal reasons arising from idempotency.
 *
 * `API-063` ‡: a repeated key with **different content** is refused as a
 * business refusal and does not overwrite the original outcome. CMP-DOC-10 §8.6
 * places it there deliberately — correction is not possible, so it is not an
 * invalid request.
 *
 * `API-086` ‡ / `API-094` ‡: the reason states what the platform declined and
 * discloses nothing about the earlier request.
 */
enum IdempotencyRefusal: string implements RefusalReason
{
    case KeyReusedWithDifferentContent = 'idempotency.key_reused_with_different_content';

    public function identifier(): string
    {
        return $this->value;
    }

    public function defaultText(): string
    {
        return match ($this) {
            self::KeyReusedWithDifferentContent => 'This request repeats a key that was used for a different request.',
        };
    }

    public function kind(): RefusalKind
    {
        return match ($this) {
            // A conflict with what the platform already holds, not a rule
            // declining the request on its merits (API-087).
            self::KeyReusedWithDifferentContent => RefusalKind::StateConflict,
        };
    }
}
