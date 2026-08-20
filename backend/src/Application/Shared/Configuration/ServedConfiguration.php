<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Configuration;

/**
 * The configuration a client was served, and the version that produced it.
 *
 * ## `API-188` — one version for the response, and per-value versions with it
 *
 * *"The response shall carry the configuration version that produced it."* One
 * number, for a whole response assembled from values that are versioned
 * **individually** — `BE-167` versions each policy value and requires *"a decision
 * shall record the version it used"*.
 *
 * Both are here, and neither is faked:
 *
 * - {@see versions()} gives each value's own version, so a decision taken on one
 *   can record the version it used, which is what `BE-167` asks for and what a
 *   single response-level number could never support.
 * - {@see version()} is a **digest** over the served set — every name paired with
 *   its version. It is not a sequence number and does not pretend to be one: a
 *   client compares it with the one it holds and refetches if they differ, which
 *   is the whole of what `API-189` needs it for.
 *
 * A digest rather than the highest version among them, because a value **leaving**
 * the served set changes the configuration and does not raise any version. The
 * maximum would miss exactly that, and a client would keep applying a value the
 * platform had stopped delivering.
 *
 * ## `API-190` ‡ and what this makes possible
 *
 * *"The client shall not poll this resource on a client-chosen interval."* A
 * client that may not poll needs to be told, which is `API-189`: any response on
 * any surface may indicate that configuration has changed. {@see version()} is
 * what such a response carries, so a client refetches on a change it was told
 * about rather than on a timer it chose.
 */
final class ServedConfiguration
{
    /**
     * @param  array<string, mixed>  $values  by the name a client knows
     * @param  array<string, int>  $versions  `BE-167`'s per-value version, where one exists
     */
    private function __construct(
        private readonly array $values,
        private readonly array $versions,
        private readonly string $version,
    ) {}

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, int>  $versions
     */
    public static function of(array $values, array $versions): self
    {
        return new self($values, $versions, self::digestOf($values, $versions));
    }

    /**
     * @return array<string, mixed>
     */
    public function values(): array
    {
        return $this->values;
    }

    /**
     * `BE-167`: the version each value came from, for a decision to record.
     *
     * A derived value ({@see DeliveredValue::derived()}) has none — it is
     * versioned with the interface, and `API-022` already states that on every
     * response.
     *
     * @return array<string, int>
     */
    public function versions(): array
    {
        return $this->versions;
    }

    /**
     * `API-188`: the configuration version that produced this response.
     */
    public function version(): string
    {
        return $this->version;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, int>  $versions
     */
    private static function digestOf(array $values, array $versions): string
    {
        // Sorted, so that two responses carrying the same configuration produce
        // the same version whatever order the register was read in — a version
        // that changed when nothing had would make API-189 tell a client to
        // refetch for nothing, and API-190 ‡ leaves it no other way to decide.
        ksort($values);
        ksort($versions);

        // The values themselves are in the digest, not only their versions: a
        // derived value has no version at all, and its change has to move this.
        return substr(hash('sha256', json_encode([$values, $versions], JSON_THROW_ON_ERROR)), 0, 16);
    }
}
