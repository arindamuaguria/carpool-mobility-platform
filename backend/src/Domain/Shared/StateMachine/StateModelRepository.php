<?php

declare(strict_types=1);

namespace Cmp\Domain\Shared\StateMachine;

/**
 * Where the engine gets its definitions (`BE-175`, `ARCH-037`).
 *
 * Declared in `Domain` because the engine is a domain component and `BE-037`
 * puts repository interfaces here, returning domain objects.
 *
 * `SRS-REQ-158`: an attempt to use a model that has not been configured is
 * **rejected**. There is no default model and no permissive fallback — a
 * fallback would be inventing the business policy `BADR-13` declined to invent.
 */
interface StateModelRepository
{
    /**
     * @throws StateModelNotDeclared where no definition has been applied
     */
    public function modelNamed(string $name): StateModel;

    public function hasModelNamed(string $name): bool;
}
