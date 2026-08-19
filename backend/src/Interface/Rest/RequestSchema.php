<?php

declare(strict_types=1);

namespace Cmp\Interface\Rest;

use Cmp\Application\Shared\Evidence\EvidenceNotRecorded;
use Cmp\Application\Shared\Failure\FieldError;
use Cmp\Application\Shared\Failure\InvalidRequest;
use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Application\Shared\Integrity\AuthoritativeValues;
use Cmp\Application\Shared\Integrity\RecordsIntegrityEvents;
use Illuminate\Http\Request;

/**
 * `CMP-IMP-468` — what a request body may contain, and what happens when it
 * contains anything else.
 *
 * Three integrity-critical statements meet in one place:
 *
 * - `API-038` ‡ — *"A request containing a field the schema does not define shall
 *   be refused **in whole**."*
 * - `API-037` ‡ — the seven authoritative values are **absent** from every
 *   request schema, so no subclass can define one.
 * - `API-039` ‡ — an unknown field whose name matches an authoritative value is
 *   **additionally** recorded as an integrity event.
 *
 * ## Absence, and why it is not the same as ignoring
 *
 * `AADR-06` rejected ignore-on-input in its own terms: a schema that accepted
 * `fare` and quietly dropped it would leave the client believing it had set a
 * fare, and the disagreement would surface later as a support case rather than
 * immediately as a refusal. `FRD-FR-238` and CLAUDE.md rule 4 say the same
 * operationally — reject the **whole** request, never partially apply.
 *
 * `API-079` still applies: the refusal names **every** offending field, not the
 * first, so a caller correcting a schema mismatch does it once.
 *
 * ## The order of the two effects
 *
 * The integrity event is written **before** the refusal is returned. The reason
 * is `FRD-FR-248` ‡'s: if the record cannot be written, `EvidenceNotRecorded`
 * propagates and the caller receives an internal fault rather than a refusal the
 * platform failed to evidence. `API-039` ‡ says *"additionally"*, not *"where
 * convenient"*.
 *
 * ## What a subclass declares
 *
 * {@see fields()} lists the field names the operation accepts, and nothing else
 * is accepted. It is a list rather than a rule set on purpose: validating the
 * **shape** is this layer's, and `API-040` ‡ keeps validating a value against
 * platform state in the application layer where it belongs — `API-041` is
 * explicit that well-formedness *"shall not substitute for"* it.
 */
abstract class RequestSchema
{
    public function __construct(private readonly RecordsIntegrityEvents $integrityEvents) {}

    /**
     * The field names this operation accepts, and the only ones.
     *
     * `API-037` ‡: no implementation may name one of the seven. {@see accepts()}
     * refuses to run against a schema that does, so the prohibition is not left
     * to a reviewer noticing.
     *
     * @return list<string>
     */
    abstract public function fields(): array;

    /**
     * The operation this schema belongs to, for the integrity record's subject.
     */
    abstract public function operation(): string;

    /**
     * Null where the body is acceptable; an {@see InvalidRequest} where it is not.
     *
     * @throws EvidenceNotRecorded where `API-039` ‡'s record cannot be written
     */
    final public function refusalFor(Request $request, ActorReference $actor): ?InvalidRequest
    {
        $this->assertNoAuthoritativeFieldIsDeclared();

        $body = $request->json()->all();

        if (! is_array($body)) {
            return InvalidRequest::forField(
                'body', 'request.body_not_an_object', 'The request body must be a JSON object.',
            );
        }

        /** @var list<string> $names */
        $names = array_values(array_filter(array_keys($body), is_string(...)));
        $undefined = array_values(array_diff($names, $this->fields()));

        if ($undefined === []) {
            return null;
        }

        // API-039 ‡: recorded first, and only where a name actually matches an
        // authoritative value. An unknown field is not by itself an integrity
        // event — a typo is a mistake, and recording every typo as an attempt
        // would make the record useless for the case NFR-069 cares about.
        $asserted = AuthoritativeValues::assertedIn($undefined);

        if ($asserted !== []) {
            $this->integrityEvents->record($actor, $this->operation(), $asserted);
        }

        // API-038 ‡ / API-079: in whole, and naming each offending field.
        return new InvalidRequest(array_map(
            static fn (string $field): FieldError => new FieldError(
                $field,
                'request.field_not_defined',
                'This request accepts no field by that name.',
            ),
            $undefined,
        ));
    }

    /**
     * `API-037` ‡, made structural.
     *
     * A subclass that declared `fare` would be a schema accepting an
     * authoritative value, which no amount of downstream validation would put
     * right — `ARCH-121` makes the backend the authority and `API-036` ‡ says a
     * representation carries no field by which a caller may assert one. Raising
     * here means such a schema fails the first time it is used rather than the
     * first time it is exploited.
     */
    private function assertNoAuthoritativeFieldIsDeclared(): void
    {
        $declared = AuthoritativeValues::assertedIn($this->fields());

        if ($declared !== []) {
            throw new \LogicException(sprintf(
                'API-037 ‡: %s declares a field asserting %s. The seven authoritative values are absent from '
                .'every request schema — AADR-06 chose absence over ignore-on-input.',
                static::class,
                implode(', ', $declared),
            ));
        }
    }
}
