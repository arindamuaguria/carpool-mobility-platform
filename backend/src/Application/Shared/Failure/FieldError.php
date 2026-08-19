<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Failure;

use InvalidArgumentException;

/**
 * One offending field within an invalid request (`API-078`).
 *
 * `API-080`: field-level detail shall not disclose platform state the caller is
 * not entitled to. The message describes the field and the expectation it broke
 * — never a value the platform holds, never whether some other record exists.
 */
final class FieldError
{
    public function __construct(
        private readonly string $field,
        private readonly string $identifier,
        private readonly string $defaultText,
    ) {
        if (trim($field) === '') {
            throw new InvalidArgumentException('A field error must name the field it concerns.');
        }

        if (trim($identifier) === '') {
            throw new InvalidArgumentException('A field error must carry a stable identifier.');
        }
    }

    /** The path of the offending field within the command. */
    public function field(): string
    {
        return $this->field;
    }

    /** A stable machine-readable identifier for why the field is offending. */
    public function identifier(): string
    {
        return $this->identifier;
    }

    /** The human-readable default for a client with no text for the identifier. */
    public function defaultText(): string
    {
        return $this->defaultText;
    }
}
