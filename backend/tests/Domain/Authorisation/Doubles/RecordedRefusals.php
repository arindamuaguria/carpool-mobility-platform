<?php

declare(strict_types=1);

namespace Tests\Domain\Authorisation\Doubles;

use Cmp\Application\Shared\Authorisation\Actor;
use Cmp\Application\Shared\Authorisation\AuthorisationRefusalCause;
use Cmp\Application\Shared\Authorisation\Operation;
use Cmp\Application\Shared\Authorisation\RecordsAuthorisationRefusals;

/**
 * Collects refusals so that `SEC-057` ‡ — every refused authorisation is
 * recorded — can be asserted without a sink.
 */
final class RecordedRefusals implements RecordsAuthorisationRefusals
{
    /** @var list<array{operation: string, actor: string, cause: AuthorisationRefusalCause}> */
    private array $recorded = [];

    public function record(Operation $operation, Actor $actor, AuthorisationRefusalCause $cause): void
    {
        $this->recorded[] = [
            'operation' => $operation->name(),
            'actor' => $actor->reference()->toString(),
            'cause' => $cause,
        ];
    }

    /**
     * @return list<array{operation: string, actor: string, cause: AuthorisationRefusalCause}>
     */
    public function recorded(): array
    {
        return $this->recorded;
    }
}
