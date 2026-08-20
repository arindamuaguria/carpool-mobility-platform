<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\User;

use Cmp\Application\User\HashesSessionTokens;
use InvalidArgumentException;

/**
 * Session tokens from the platform's random source.
 *
 * `SEC-035` ‡: *"generated with a cryptographically secure random source and
 * shall carry sufficient entropy that guessing is infeasible."* `random_bytes()`
 * is PHP's CSPRNG and raises rather than degrading if the source is unavailable —
 * which is the behaviour to want: a token from a weak source is worse than no
 * token, because nothing downstream could tell.
 *
 * **32 bytes.** `SEC-035` ‡ states the property and no statement states a length,
 * so this file chooses one: 256 bits is the width at which guessing is infeasible
 * by any margin anyone need argue about, and it is the output width of the hash
 * below, so the token carries no less entropy than the store can distinguish.
 * Recorded as an implementation choice.
 *
 * `SEC-036` ‡ / CMP-DOC-13 §14.2: the stored form is a **one-way hash of a
 * high-entropy random token** — explicitly not `SEC-028` ‡'s memory-hard
 * construction, which exists for secrets a person chose. SHA-256 is one-way,
 * deterministic (which `SEC-042`'s lookup requires) and fast enough to run on
 * every request. `SEC-031`'s cost parameters are `SEC-028` ‡'s and are not needed
 * here, which is why session work is not blocked on them.
 *
 * ## No salt
 *
 * `SEC-029` ‡ requires a unique salt for authentication material, and this is not
 * that. A salt defends a small input space; `SEC-035` ‡ leaves none. And a
 * per-row salt would make `SEC-042`'s hash-and-lookup impossible — the platform
 * would have to try every row to find the one that matched.
 */
final class RandomSessionTokens implements HashesSessionTokens
{
    /**
     * 256 bits. See the class note: `SEC-035` ‡ states the property, not the
     * width.
     */
    private const TOKEN_BYTES = 32;

    private const ALGORITHM = 'sha256';

    public function generate(): string
    {
        // Hexadecimal rather than raw bytes: API-100 carries the token in a
        // request header, and a header value has to survive being a string.
        return bin2hex(random_bytes(self::TOKEN_BYTES));
    }

    public function hash(string $token): string
    {
        if (trim($token) === '') {
            throw new InvalidArgumentException(
                'SEC-036 ‡: an empty token is not a token, and hashing one would produce a value that '
                .'looks like a session identifier.'
            );
        }

        // Raw binary, because op_sessions.token_hash is VARBINARY(32) — DB-110 ‡
        // takes the same position for the evidential chain, and for the same
        // reason: no collation can fold two distinct hashes into one.
        return hash(self::ALGORITHM, $token, true);
    }
}
