<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Failure;

use InvalidArgumentException;

/**
 * The command was malformed. The caller can correct it and resubmit.
 *
 * `API-078`: the body identifies each offending field and why it is offending.
 * `API-079`: **all** detectable field failures are reported, not only the first
 * — so this failure carries a list, and constructing one with an empty list is
 * itself a programming error.
 *
 * Classification (CMP-DOC-10 §8.6): a missing, malformed or wrongly typed field,
 * an unknown field (`API-038`), and an absent idempotency key (`API-058`) are
 * all invalid requests. An idempotency key **reused with different content** is
 * not — that is a business refusal, because correction is not possible.
 */
final class InvalidRequest extends Failure
{
    /** @var list<FieldError> */
    private readonly array $fieldErrors;

    /**
     * @param  list<FieldError>  $fieldErrors
     */
    public function __construct(array $fieldErrors)
    {
        if ($fieldErrors === []) {
            throw new InvalidArgumentException(
                'API-078: an invalid request must identify each offending field.'
            );
        }

        $this->fieldErrors = $fieldErrors;
    }

    public static function forField(string $field, string $identifier, string $defaultText): self
    {
        return new self([new FieldError($field, $identifier, $defaultText)]);
    }

    public function branch(): FailureBranch
    {
        return FailureBranch::InvalidRequest;
    }

    /**
     * Every detectable field failure (`API-079`).
     *
     * @return list<FieldError>
     */
    public function fieldErrors(): array
    {
        return $this->fieldErrors;
    }
}
