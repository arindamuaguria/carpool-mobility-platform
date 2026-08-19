<?php

declare(strict_types=1);

namespace Cmp\Interface\Rest\Middleware;

use Closure;
use Cmp\Application\Shared\Failure\InvalidRequest;
use Cmp\Application\Shared\Response\EvaluationTime;
use Cmp\Interface\Rest\FailureResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `CMP-IMP-467` — JSON, and nothing else.
 *
 * `API-011`: *"Request and response bodies shall be `application/json`; **no
 * other media type shall be negotiated**."* `AADR-05` gives the reason: one
 * representation per resource means there is nothing to negotiate, and a
 * negotiation mechanism that always produces the same answer is a mechanism
 * nobody maintains and everybody has to reason about.
 *
 * Two checks, and they are different questions:
 *
 * - **A request carrying a body** must declare `application/json`. Anything else
 *   is an invalid request — the caller can correct it, which is §8.6's test for
 *   the branch.
 * - **A request asking for something else** through `Accept` is likewise refused
 *   rather than served JSON anyway. Serving JSON to a client that asked for XML
 *   would be `API-011` honoured in the letter and abandoned in the spirit: the
 *   client would carry on believing negotiation works.
 *
 * `Accept: * / *` and an absent `Accept` are both fine — neither asks for
 * anything the platform does not serve.
 *
 * `API-017` is why the comparison ignores parameters and case: *"the interface
 * shall not depend on request or response header order, casing or optional
 * whitespace for meaning."* `application/json; charset=utf-8` is
 * `application/json`.
 */
final class ServeJsonOnly
{
    public const MEDIA_TYPE = 'application/json';

    public function __construct(private readonly EvaluationTime $evaluatedAt) {}

    public function handle(Request $request, Closure $next): Response
    {
        $contentType = $request->headers->get('Content-Type');

        if ($request->getContent() !== '' && ! self::isJson($contentType)) {
            return $this->refuse('Content-Type', 'request.media_type_not_supported',
                'Request bodies must be application/json.');
        }

        $accept = $request->headers->get('Accept');

        if ($accept !== null && ! self::acceptsJson($accept)) {
            return $this->refuse('Accept', 'response.media_type_not_supported',
                'This interface serves application/json only.');
        }

        return $next($request);
    }

    private function refuse(string $header, string $identifier, string $defaultText): Response
    {
        // §8.6: "field missing, malformed, or of the wrong type" is an invalid
        // request, because the caller can correct it. A header is a field of the
        // request for this purpose.
        return FailureResponse::from(
            InvalidRequest::forField($header, $identifier, $defaultText),
            $this->evaluatedAt->stamp(),
        );
    }

    private static function isJson(?string $contentType): bool
    {
        return $contentType !== null && self::normalise($contentType) === self::MEDIA_TYPE;
    }

    /**
     * `* / *` and `application/*` both include JSON, so neither is a refusal.
     */
    private static function acceptsJson(string $accept): bool
    {
        foreach (explode(',', $accept) as $offer) {
            $type = self::normalise($offer);

            if ($type === self::MEDIA_TYPE || $type === '*/*' || $type === 'application/*') {
                return true;
            }
        }

        return false;
    }

    /**
     * `API-017`: parameters, casing and whitespace carry no meaning here.
     */
    private static function normalise(string $mediaType): string
    {
        return strtolower(trim(explode(';', $mediaType, 2)[0]));
    }
}
