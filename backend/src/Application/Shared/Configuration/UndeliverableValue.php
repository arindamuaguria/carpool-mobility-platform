<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Configuration;

use LogicException;

/**
 * The register promises a client a value the platform cannot produce.
 *
 * A fault, not a business refusal (`BE-186` ‡): no caller can provoke it, because
 * the register is a constant in the code. Reaching it means a value was added to
 * CMP-DOC-10 §14.2's register without the code that supplies it — which is a
 * value `API-187` ‡ tells a client to rely on and that never arrives.
 *
 * It raises rather than omitting the value, which is the difference between this
 * and an unset policy value. An unset value is a **state** `API-193` anticipates
 * and the client has a conservative default for; a value with no source is a
 * defect, and omitting it would make the two indistinguishable to whoever has to
 * find out why a client is behaving oddly.
 */
final class UndeliverableValue extends LogicException {}
