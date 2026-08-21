<?php

declare(strict_types=1);

namespace Cmp\Interface\Rest\Middleware;

use Closure;
use Cmp\Application\Shared\Failure\FieldError;
use Cmp\Application\Shared\Failure\InvalidRequest;
use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Application\Shared\Integrity\AuthoritativeValues;
use Cmp\Application\Shared\Integrity\RecordsIntegrityEvents;
use Cmp\Application\Shared\Response\EvaluationTime;
use Cmp\Application\User\AuthenticatedCaller;
use Cmp\Interface\Rest\FailureResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `FRD-FR-238`–`FRD-FR-240` ‡ — a caller may not assert what the platform
 * decides.
 *
 * `ARCH-121` makes the backend the authority and `API-036` ‡ leaves a
 * representation no field by which a caller could assert one. `API-037` ‡ keeps
 * the seven out of every request schema, and {@see RequestSchema} makes that
 * structural — a schema declaring `fare` raises the first time it is used.
 *
 * ## Why absence from the schema is not enough
 *
 * A schema that does not declare a field does not *reject* a request carrying
 * one — it ignores it. `FRD-FR-239` ‡ requires the opposite in terms: *"reject a
 * request carrying such a value **in its entirety**, and shall not partially
 * apply it."* `FRD-FR-240` ‡ then requires the attempt recorded. Ignoring does
 * neither, and `AADR-06` rejected ignore-on-input for exactly the reason it names:
 * a client that set a field and saw the request succeed believes it set something.
 *
 * ## Why this is middleware rather than per operation
 *
 * `FRD-FR-238` ‡ is about **every** request, and the three operations the surface
 * serves today accept no body at all — so there is no schema on any of them to
 * carry the check. A guarantee that has to be remembered per route is a guarantee
 * that lapses on the route somebody adds in a hurry.
 *
 * {@see RequestSchema} keeps the per-operation half and is not replaced: it
 * refuses **any** undefined field (`API-038` ‡), which is stricter and needs to
 * know what the operation accepts. It arrives with the first operation that
 * accepts a body. This is the floor beneath it.
 *
 * ## What is recorded, and what is not
 *
 * `API-039` ‡ records an integrity event only where a field name **matches a
 * known authoritative value**. An unknown field is not by itself one — a typo is
 * a mistake, and recording every typo would make the record useless for the case
 * `NFR-069` cares about. So a body with `{"nonsense": 1}` passes here and is
 * refused, if at all, by the operation's own schema.
 *
 * `BE-201` ‡: the record names the **canonical value**, never the caller's
 * spelling and never the value they sent.
 */
final class RefuseAssertedAuthority
{
    public function __construct(
        private readonly RecordsIntegrityEvents $integrityEvents,
        private readonly EvaluationTime $evaluatedAt,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $asserted = AuthoritativeValues::assertedIn($this->fieldNamesIn($request));

        if ($asserted === []) {
            return $next($request);
        }

        // FRD-FR-240 ‡ / API-039 ‡: recorded before the refusal is returned, and
        // FRD-FR-248 ‡ makes an action whose record cannot be written not
        // complete — so a failure here propagates rather than letting the refusal
        // reach the caller unrecorded.
        //
        // SEC-045 ‡: the actor is whoever the session named, and an
        // unauthenticated caller is recorded as unattributed rather than not at
        // all. API-039 ‡ says "a request", not "an authenticated request", and
        // NFR-069's abuse case is exactly the caller who has not signed in.
        $this->integrityEvents->record(
            ActorReference::fromString($this->actorIn($request)),
            $request->method().' '.$request->path(),
            $asserted,
        );

        // FRD-FR-239 ‡ / API-038 ‡: in whole. Nothing downstream runs, so nothing
        // is partially applied — the refusal is returned from here rather than
        // from the operation, which never sees the request.
        return FailureResponse::from(
            new InvalidRequest(array_map(
                static fn (string $value): FieldError => new FieldError(
                    $value,
                    'request.authoritative_value_asserted',
                    'This value is determined by the platform and cannot be supplied.',
                ),
                $asserted,
            )),
            $this->evaluatedAt->stamp(),
        );
    }

    /**
     * Every field name in the request, from the body and the query alike.
     *
     * Both, because `FRD-FR-238` ‡ names no carriage: a fare in a query string
     * asserts a fare exactly as much as one in a body, and a guard that read only
     * the body would be one somebody routes around by moving the field.
     *
     * Nested names are included. A caller asserting `{"booking": {"fare": 1}}` is
     * asserting a fare, and a check that only read the top level would miss the
     * shape any real client would actually send.
     *
     * @return list<string>
     */
    private function fieldNamesIn(Request $request): array
    {
        $body = $request->isJson() ? $request->json()->all() : [];

        return array_values(array_unique(array_merge(
            self::namesIn(is_array($body) ? $body : []),
            self::namesIn($request->query->all()),
        )));
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private static function namesIn(array $values, int $depth = 0): array
    {
        // Bounded, because a caller controls the shape and an unbounded walk is
        // something a caller could make expensive. Four levels is deeper than any
        // representation CMP-DOC-10 §11 describes.
        if ($depth > 4) {
            return [];
        }

        $names = [];

        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $names[] = $key;
            }

            if (is_array($value)) {
                $names = array_merge($names, self::namesIn($value, $depth + 1));
            }
        }

        return array_values($names);
    }

    /**
     * Whoever the session named, or the unattributed caller.
     *
     * `RequireSession` puts an {@see AuthenticatedCaller} on
     * the request for the operations that carry one. This middleware runs on the
     * outer group — before that, and on the operations §9.1 leaves outside it — so
     * for most requests there is no actor.
     *
     * `BE-107` ‡ refuses a record that cannot say what it is about, and here it
     * can: the subject is the operation and the reason is the value asserted. The
     * actor is the one field with nobody to put in it, and a reserved name says
     * so rather than leaving the record unwritten — which is the opposite choice
     * from `RecordedSessionAnomalies`, and for the opposite reason. There, nothing
     * had happened that anybody did; here, somebody deliberately sent a value the
     * platform decides.
     */
    private function actorIn(Request $request): string
    {
        $caller = $request->attributes->get(RequireSession::CALLER);

        return $caller instanceof AuthenticatedCaller
            ? $caller->actor()->reference()->toString()
            : 'unattributed';
    }
}
