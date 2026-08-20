<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Configuration;

use Cmp\Domain\Shared\Policy\PolicyKey;
use Cmp\Domain\Shared\Policy\PolicyStore;
use Cmp\Domain\Shared\Policy\PolicyType;
use Cmp\Domain\Shared\Policy\PolicyValue;
use Cmp\Domain\Shared\Refusal\RefusalReason;

/**
 * CMP-DOC-10 §14 — the configuration resource.
 *
 * `API-187` ‡ makes this the only place a client obtains a policy value, and
 * `API-196` makes it the only way one is delivered — *"a configuration change
 * shall not require a client release, and shall not be delivered by any other
 * means"*. `MADR-11` and `AADR-13` are the decisions behind both.
 *
 * ## An unset value is omitted, not served as null
 *
 * `API-193`: *"the client shall hold a documented conservative default for every
 * value, applied only until its first successful fetch."* A value the operator
 * has not set has nothing to deliver, and `SRS-REQ-113` forbids synthesising one
 * — so it is absent from the response and the client keeps its conservative
 * default, which is exactly the state `API-193` describes.
 *
 * Serving `null` instead would be worse in a way worth stating: a client cannot
 * tell a deliberate null from an unset one, and `API-192` requires every value
 * served to be **typed and validated**, which null is not.
 *
 * The platform does not hide the omission. `GET /health` names every unset value
 * (`FRD-FR-257`), and both capabilities that read one are reported **withdrawn**
 * — so a client that fetched configuration and found a value missing can find out
 * why without asking anybody.
 *
 * ## `API-191` ‡ is satisfied by construction
 *
 * *"No value delivered here shall be capable of relaxing an absolute business
 * rule."* Everything served is either a declared policy key — and `DB-153` ‡ makes
 * absence from that register the mechanism, so a key that could relax a rule does
 * not exist to be served — or a value derived from the platform's own contract,
 * which no client can write.
 *
 * ## `API-192` — typed and validated before it is served
 *
 * The store validates on write (`BE-174`) and the typed accessor validates again
 * on read (`BE-166`), so a value that reached this method already passed both.
 * Nothing here re-checks, and nothing here coerces.
 */
final class ServeConfiguration
{
    /**
     * The names a client knows CMP-DOC-10 §14.2's values by.
     *
     * Declared here rather than at the composition root because both the
     * register that supplies them and the code that derives one must agree, and
     * two string literals are two chances to disagree.
     */
    public const SESSION_LIFETIME = 'session_lifetime_seconds';

    public const REFUSAL_REASONS = 'refusal_reasons';

    /**
     * @param  list<DeliveredValue>  $delivered  CMP-DOC-10 §14.2's register
     * @param  list<RefusalReason>  $refusalReasons  `AADR-14`'s enumerable set
     */
    public function __construct(
        private readonly array $delivered,
        private readonly array $refusalReasons,
        private readonly PolicyStore $policy,
    ) {}

    /**
     * The public subset — §9.1's *"configuration fetch, public subset"*.
     *
     * `API-195`: it contains no value that discloses platform state, which is a
     * property of the register rather than of this method — each entry says which
     * it is, and this filters on what the entry says.
     */
    public function public(): ServedConfiguration
    {
        return $this->assemble(true);
    }

    /**
     * Everything a client is entitled to.
     *
     * Identical to {@see public()} today, because every entry in the register is
     * public. It is a separate method rather than the same one because the day a
     * non-public value is added, `API-195` must not be satisfied by whoever
     * happens to remember it — the two callers are already distinct.
     */
    public function all(): ServedConfiguration
    {
        return $this->assemble(false);
    }

    private function assemble(bool $publicOnly): ServedConfiguration
    {
        $values = [];
        $versions = [];

        foreach ($this->delivered as $entry) {
            if ($publicOnly && ! $entry->isPublic()) {
                continue;
            }

            $key = $entry->key();

            if (! $key instanceof PolicyKey) {
                $values[$entry->name()] = $this->derive($entry->name());

                continue;
            }

            // API-193: unset means absent, so the client keeps its conservative
            // default. isSet() is asked first because this is precisely the
            // caller PolicyStore's own note describes — "a caller that can act
            // without the value asks first".
            if (! $this->policy->isSet($key)) {
                continue;
            }

            $value = $this->policy->read($key);
            $values[$entry->name()] = $this->typed($value);
            $versions[$entry->name()] = $value->version();
        }

        return ServedConfiguration::of($values, $versions);
    }

    /**
     * A value the platform derives from its own contract rather than reading.
     */
    private function derive(string $name): mixed
    {
        return match ($name) {
            // AADR-14: "reasons are enumerable and testable", and API-083 has the
            // client key its localised text by the identifier. Without the set,
            // a client cannot know which identifiers it has text for until one
            // arrives in a refusal it then cannot present.
            self::REFUSAL_REASONS => $this->identifiers(),

            default => throw new UndeliverableValue(sprintf(
                'CMP-DOC-10 §14.2: "%s" is registered as derived and nothing derives it. A value in the '
                .'register with no source is a value a client is promised and never gets.',
                $name,
            )),
        };
    }

    /**
     * `AADR-14`'s identifier set, sorted so the digest is stable.
     *
     * `API-084`: an identifier is not removed or repurposed within a version, so
     * this list only ever grows within `v1` — which is what lets a client cache
     * it against {@see ServedConfiguration::version()}.
     *
     * @return list<array{reason: string, default_text: string}>
     */
    private function identifiers(): array
    {
        $seen = [];

        foreach ($this->refusalReasons as $reason) {
            // API-082 ‡ travels with API-081 ‡: an identifier with no default is
            // an identifier an older client shows as a blank dialog, which is the
            // alternative AADR-14 rejected.
            $seen[$reason->identifier()] = [
                'reason' => $reason->identifier(),
                'default_text' => $reason->defaultText(),
            ];
        }

        ksort($seen);

        return array_values($seen);
    }

    /**
     * `API-192`: served in the declared type, never as a string.
     */
    private function typed(PolicyValue $value): int
    {
        // Only Duration is reachable today, because the one policy value §14.2
        // delivers is API-104's session lifetime bound. A second type arrives
        // with the second value, and UndeliverableValue is what a caller gets
        // meanwhile rather than a silent cast — the typed accessor would raise
        // anyway (BE-166), and this says why in the platform's own terms first.
        return match ($value->key()->type()) {
            PolicyType::Duration => $value->asDurationInSeconds(),

            default => throw new UndeliverableValue(sprintf(
                'CMP-DOC-10 §14.2 delivers no %s value yet, so nothing here knows how to serve one. '
                .'API-192 requires it typed; a cast chosen here would be the platform guessing.',
                $value->key()->type()->value,
            )),
        };
    }
}
