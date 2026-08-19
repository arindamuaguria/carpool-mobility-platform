<?php

declare(strict_types=1);

namespace Cmp\Interface\Rest\Middleware;

use Closure;
use Cmp\Application\Shared\Failure\InvalidRequest;
use Cmp\Application\Shared\Idempotency\IdempotencyRegistry;
use Cmp\Application\Shared\Response\EvaluationTime;
use Cmp\Interface\Rest\FailureResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `CMP-IMP-436` — every state-changing request carries an idempotency key.
 *
 * `API-057` ‡ requires the key; `API-058` ‡ says a request without one is
 * refused as an **invalid request** rather than as a refusal, because the caller
 * can correct it — §8.6 lists the condition explicitly. `AADR-04` made the key
 * mandatory rather than optional, and `MOB-049` is why it works: the client
 * generates the key when the **intent is recorded**, not when the request is
 * sent, so a retry after a lost response carries the same key.
 *
 * ## Which requests
 *
 * `API-065`: `GET` does not require a key, and `API-007` is the reason — `GET`
 * is safe and changes no authoritative state, so there is nothing for a key to
 * guard. `HEAD` and `OPTIONS` follow `GET`. Everything else is state-changing by
 * `API-009` and `API-010`, including `PUT` and `DELETE`: `API-008` makes those
 * idempotent *"by definition of the operation, independently of the idempotency
 * key"*, which is a different guarantee and not a substitute — an idempotent
 * operation still needs the registry entry `API-062` ‡ replays from.
 *
 * ## What this middleware does not do
 *
 * It checks **presence**, and stops there. Whether the key has been seen, whether
 * the content matches, and what to replay are the registry's
 * ({@see IdempotencyRegistry}), and `API-061` ‡
 * requires the entry to be written *"in the same transaction as the effect it
 * guards"* — which is the application service's transaction, not a middleware's.
 * A middleware that consulted the registry would either open a transaction
 * (`BE-047` ‡ reserves that for the application layer) or write the entry outside
 * the one that matters.
 *
 * `API-059`: the key is caller-generated and **opaque to the platform**, so
 * nothing here parses it. What is checked is that it is present and not empty;
 * `API-060` scopes it to the actor and the operation, which again is the
 * registry's.
 *
 * `API-066` ‡ applies this to the safety surface identically, and `API-067` ‡ to
 * provider callbacks. Neither surface exists; when they do, this middleware is
 * what they register.
 */
final class RequireIdempotencyKey
{
    /**
     * `MOB-049`/`API-059`: a header, because the key belongs to the request
     * rather than to the resource, and a body field would put it inside a schema
     * that `API-038` ‡ then has to define on every operation.
     */
    public const HEADER = 'Idempotency-Key';

    /**
     * `API-065` / `API-007`: safe methods change no authoritative state.
     *
     * @var list<string>
     */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private readonly EvaluationTime $evaluatedAt) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array(strtoupper($request->getMethod()), self::SAFE_METHODS, true)) {
            return $next($request);
        }

        $key = $request->headers->get(self::HEADER);

        if ($key === null || trim($key) === '') {
            // API-058 ‡ / §8.6: an invalid request. The caller can correct it by
            // sending the key it should already have generated.
            return FailureResponse::from(
                InvalidRequest::forField(
                    self::HEADER,
                    'idempotency.key_required',
                    'Every state-changing request must carry an idempotency key.',
                ),
                $this->evaluatedAt->stamp(),
            );
        }

        return $next($request);
    }
}
