<?php

declare(strict_types=1);

namespace Cmp\Application\User;

use Cmp\Application\Shared\Failure\FieldError;
use Cmp\Application\Shared\Failure\InvalidRequest;
use Cmp\Domain\User\ContactLabel;
use Cmp\Domain\User\NominatedContacts;
use Cmp\Domain\User\PhoneNumber;
use InvalidArgumentException;
use LogicException;

/**
 * `FRD-FR-183` — *"validate emergency contact details and state why a contact is
 * unusable."*
 *
 * `UC-048`'s minimal guarantee is the strong form of it: *"A contact is never
 * recorded in a form the platform cannot use."* So this runs before anything
 * reaches {@see NominatedContacts}, and a set that failed it is
 * never built.
 *
 * ## Why the reason is a field error rather than a refusal
 *
 * `API-087` separates a request the caller can correct from a decision made
 * against platform state. A number the platform cannot parse is the first: the
 * caller knows what they sent and can send something else, without knowing
 * anything about what the platform holds. `EmergencyContactRefusal` carries the
 * second kind — already nominated, not nominated — and both are refusals a
 * caller could only have discovered by asking.
 *
 * ## Both fields, not the first
 *
 * `API-079` reports every detectable failure in one response, so a caller
 * correcting a malformed number and an over-long label does it once. That is
 * also why the two are parsed independently rather than short-circuited.
 *
 * ## The rules themselves are elsewhere
 *
 * What makes a number well-formed is {@see PhoneNumber}'s, and what makes a
 * label well-formed is {@see ContactLabel}'s. This does not restate either —
 * `BE-010` puts a rule in one component, and a second opinion here would be a
 * second place for it to be wrong. It converts a raise into a reason.
 */
final class ContactDetails
{
    private function __construct(
        private readonly ?PhoneNumber $number,
        private readonly ?ContactLabel $label,
        private readonly ?InvalidRequest $unusable,
    ) {}

    public static function from(?string $number, ?string $label): self
    {
        $errors = [];
        $parsedNumber = null;
        $parsedLabel = null;

        if ($number === null) {
            // BAD-RULE-043's shape: the number is the one detail without which
            // there is no contact. UC-048's success guarantee cannot be met by a
            // record holding a label alone.
            $errors[] = new FieldError(
                'phone_number',
                'contact.phone_number_absent',
                'An emergency contact is a phone number. Send one.',
            );
        } else {
            try {
                $parsedNumber = PhoneNumber::fromString($number);
            } catch (InvalidArgumentException $malformed) {
                $errors[] = new FieldError(
                    'phone_number',
                    'contact.phone_number_unusable',
                    $malformed->getMessage(),
                );
            }
        }

        if ($label !== null) {
            try {
                $parsedLabel = ContactLabel::fromString($label);
            } catch (InvalidArgumentException $malformed) {
                $errors[] = new FieldError(
                    'label',
                    'contact.label_unusable',
                    $malformed->getMessage(),
                );
            }
        }

        return new self($parsedNumber, $parsedLabel, $errors === [] ? null : new InvalidRequest($errors));
    }

    /**
     * Null where the details are usable; the reason where they are not.
     */
    public function unusable(): ?InvalidRequest
    {
        return $this->unusable;
    }

    public function number(): PhoneNumber
    {
        if ($this->number === null) {
            throw new LogicException('FRD-FR-183: read the number only where unusable() returned null.');
        }

        return $this->number;
    }

    public function label(): ?ContactLabel
    {
        return $this->label;
    }
}
