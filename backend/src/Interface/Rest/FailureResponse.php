<?php

declare(strict_types=1);

namespace Cmp\Interface\Rest;

use Cmp\Application\Shared\Failure\BusinessRefused;
use Cmp\Application\Shared\Failure\DependencyUnavailable;
use Cmp\Application\Shared\Failure\Failure;
use Cmp\Application\Shared\Failure\FieldError;
use Cmp\Application\Shared\Failure\InternalFault;
use Cmp\Application\Shared\Failure\InvalidRequest;
use Illuminate\Http\JsonResponse;
use LogicException;

/**
 * `CMP-IMP-463` — the four error branches, in four shapes.
 *
 * `API-071` ‡: every failure is exactly one of four. `API-072` ‡ is the harder
 * half — *"each branch shall have its own status code range and its own body
 * shape, **distinguishable by structure alone**"*. That rules out a shared
 * `error` object with a `type` discriminator, because a client would then have to
 * read a value to learn how to read the rest. Each branch owns a top-level key
 * nothing else uses:
 *
 * | Branch | Status | Key | Retry |
 * |---|---|---|---|
 * | Invalid request | `400` | `invalid_request` | after correction |
 * | Business refusal | `409` state · `422` rule | `refusal` | no |
 * | Dependency unavailable | `503` | `unavailable` | yes |
 * | Internal fault | `500` | `fault` | yes, and it is the platform's fault |
 *
 * The statuses are CMP-DOC-10 §8.1's table, not this file's choice.
 *
 * ## What each branch may and may not say
 *
 * **Invalid request.** `API-078` identifies each offending field and why;
 * `API-079` reports **all** detectable failures, not only the first, which is why
 * `InvalidRequest` carries a list and refuses an empty one. `API-080` keeps
 * field-level detail from disclosing platform state the caller is not entitled
 * to — satisfied by construction, because a `FieldError` carries a field name, a
 * stable identifier and a default text, and has nowhere to put a value it read.
 *
 * **Business refusal.** `API-081` ‡ requires a stable machine-readable
 * identifier and `API-082` ‡ a human-readable default; `API-083` has the client
 * present its own localised text keyed by the identifier and fall back to the
 * default. `API-087` distinguishes a state conflict from a rule — `409` against
 * `422` — and {@see BusinessRefused::isStateConflict()} answers that, so this
 * adapter never touches a Domain enum (`BE-002`).
 *
 * **Dependency unavailable.** `API-089` ‡ requires the body to convey that
 * **nothing was decided** — neither success nor failure — and `API-090` forbids
 * naming the provider or exposing its error. The capability is named because
 * `FRD-FR-257` requires the actor to be told what is unavailable; a capability is
 * not a provider, and `BE-150` is why the distinction matters.
 *
 * **Internal fault.** `API-092` ‡: no stack, no query, no identifier of an
 * internal component. `API-093`: a correlation identity the caller may quote.
 * `InternalFault` cannot be constructed from a throwable at all, so there is
 * nothing here to accidentally serialise.
 *
 * ## Why the mapping is a `match` on the concrete failure
 *
 * `FailureBranch` has four cases and this has four arms, but the arms are keyed
 * on the **class**, because each shape needs data only that class carries. A
 * fifth failure class would fail to match and raise rather than fall into a
 * default arm — `API-071` ‡ says every failure is one of four, and a silent
 * default would be the way a fifth arrived unnoticed.
 */
final class FailureResponse
{
    public static function from(Failure $failure, string $evaluatedAt, int $version = ServedVersions::CURRENT): JsonResponse
    {
        $meta = Envelope::meta($version, $evaluatedAt);

        return match (true) {
            $failure instanceof InvalidRequest => new JsonResponse([
                'meta' => $meta,
                // API-078 / API-079: each offending field, and all of them.
                'invalid_request' => [
                    'fields' => array_map(
                        static fn (FieldError $error): array => [
                            'field' => $error->field(),
                            'reason' => $error->identifier(),
                            'default_text' => $error->defaultText(),
                        ],
                        $failure->fieldErrors(),
                    ),
                ],
            ], 400),

            $failure instanceof BusinessRefused => new JsonResponse([
                'meta' => $meta,
                'refusal' => [
                    // API-081 ‡ / API-084: stable, and never repurposed within a
                    // version.
                    'reason' => $failure->identifier(),
                    // API-082 ‡ / API-083: what a client shows when it has no
                    // text of its own for the identifier.
                    'default_text' => $failure->defaultText(),
                ],
            ], $failure->isStateConflict() ? 409 : 422),

            $failure instanceof DependencyUnavailable => new JsonResponse([
                'meta' => $meta,
                'unavailable' => [
                    // API-090: a capability, never the provider behind it.
                    'capability' => $failure->capability(),
                    // API-089 ‡: neither success nor failure — nothing was
                    // decided. Stated rather than left to be inferred from a 503.
                    'nothing_was_decided' => $failure->nothingWasDecided(),
                    'retry_may_help' => $failure->retryMayHelp(),
                ],
            ], 503),

            $failure instanceof InternalFault => new JsonResponse([
                'meta' => $meta,
                // API-092 ‡: the correlation identity and nothing else.
                'fault' => ['correlation' => $failure->correlationIdentity()->toString()],
            ], 500),

            default => throw new LogicException(sprintf(
                'API-071 ‡: every failure is exactly one of four branches, and %s is not one of them. '
                .'A fifth branch is a change to CMP-DOC-10 §8, not a new class.',
                $failure::class,
            )),
        };
    }

    /**
     * The top-level key each branch owns.
     *
     * `API-072` ‡ is checkable only against a list of what the shapes are, and a
     * contract test asserting "the four keys are distinct" needs the four. Public
     * so the test derives them from here rather than restating them.
     *
     * @return array<string, string> failure class => its top-level key
     */
    public static function shapes(): array
    {
        return [
            InvalidRequest::class => 'invalid_request',
            BusinessRefused::class => 'refusal',
            DependencyUnavailable::class => 'unavailable',
            InternalFault::class => 'fault',
        ];
    }
}
