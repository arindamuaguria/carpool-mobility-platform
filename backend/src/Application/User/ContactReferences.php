<?php

declare(strict_types=1);

namespace Cmp\Application\User;

use Cmp\Application\Shared\Failure\InvalidRequest;
use Cmp\Domain\User\EmergencyContactReference;
use Cmp\Domain\User\EmergencyContactRefusal;
use InvalidArgumentException;
use LogicException;

/**
 * Turning a route parameter into a reference, or into the reason it is not one.
 *
 * Shared by {@see AmendEmergencyContact} and {@see RemoveEmergencyContact},
 * which both take one from the path and must answer identically — `API-094` ‡
 * would be defeated by two operations that disagreed about which references are
 * refusals and which are invalid requests, because a caller could then tell the
 * two states apart by asking the other operation.
 *
 * ## The reason is the same one a real reference gets
 *
 * A caller sending `../../etc/passwd` and a caller sending a well-formed
 * reference to somebody else's contact are told the same thing:
 * `emergency_contact.not_nominated`. `SEC-069` ‡ requires that, and `DB-023` ‡
 * is the reason it costs nothing — a reference cannot be guessed, so a caller
 * holding one either has it from the platform or invented it, and the platform
 * owes an inventor no help.
 *
 * The value is never echoed back, and neither is the parse error:
 * {@see EmergencyContactReference}'s message describes the identifier space,
 * which is exactly what somebody probing it wants.
 */
final class ContactReferences
{
    public static function parse(?string $value): EmergencyContactReference|InvalidRequest
    {
        if ($value === null) {
            // The route binds it. Reaching here means the operation was
            // registered without the parameter, which is a fault rather than
            // anything a caller did.
            throw new LogicException('The route supplies the contact reference; reaching here means it did not.');
        }

        try {
            return EmergencyContactReference::fromString($value);
        } catch (InvalidArgumentException) {
            return InvalidRequest::forField(
                'id',
                EmergencyContactRefusal::NotNominated->identifier(),
                EmergencyContactRefusal::NotNominated->defaultText(),
            );
        }
    }
}
