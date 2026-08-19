<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Integrity;

/**
 * The values only the platform decides, and the field names a caller might use
 * to try to decide them instead.
 *
 * `API-037` ‡ names seven: *"Fare, verification standing, payment status, seat
 * counts, ratings, balances and trip state shall be absent from every request
 * schema."* `FRD-FR-237`–`FRD-FR-241` is the behaviour behind it and `ARCH-121`
 * the architecture: the backend is the authority, and a request that asserts one
 * of these is refused **in whole** and recorded.
 *
 * ## Why a register of spellings rather than the seven names
 *
 * `API-039` ‡ requires an integrity event where *"a request contain[s] a field
 * **whose name matches** a known authoritative value"*. Matching a name means
 * knowing what a caller would plausibly call it, and a client team writing
 * `seatsAvailable` is asserting seat counts exactly as much as one writing
 * `seats_available`. The register maps spellings to the canonical value so that
 * detection is broad and the **record** is narrow.
 *
 * That narrowness is deliberate. What is written to the evidential log is the
 * canonical value name, never the caller's spelling and never the value they
 * sent: `BE-201` ‡ keeps a log free of what a caller supplied, and seven short
 * names fit the record's bounded `reason` where an arbitrary list of caller field
 * names would not.
 *
 * ## Absence, not ignore-on-input
 *
 * `AADR-06` chose absence: a schema that accepted the field and ignored it would
 * leave a client believing it had set something. `API-038` ‡ refuses the whole
 * request instead, and CLAUDE.md rule 4 states the same in one line — reject the
 * whole request, never partially apply.
 */
final class AuthoritativeValues
{
    /**
     * Canonical value => the field spellings that assert it.
     *
     * The seven of `API-037` ‡, in its own order. Spellings are lower-cased and
     * compared without separators, so `seatsAvailable`, `seats_available` and
     * `SeatsAvailable` are one entry rather than three.
     *
     * @return array<string, list<string>>
     */
    public static function all(): array
    {
        return [
            'fare' => ['fare', 'fareamount', 'faretotal', 'price', 'amountdue'],
            'verification standing' => ['verificationstatus', 'verificationstanding', 'verified', 'isverified'],
            'payment status' => ['paymentstatus', 'paymentstate', 'paid', 'ispaid'],
            'seat counts' => ['seatsavailable', 'seatcount', 'seatsremaining', 'availableseats', 'seatsconfirmed'],
            'ratings' => ['rating', 'ratings', 'ratingaverage', 'score'],
            'balances' => ['balance', 'walletbalance', 'rewardbalance', 'points'],
            'trip state' => ['tripstate', 'tripstatus', 'bookingstatus', 'bookingstate', 'ridestatus'],
        ];
    }

    /**
     * The canonical values a set of field names attempts to assert.
     *
     * @param  list<string>  $fieldNames
     * @return list<string>
     */
    public static function assertedIn(array $fieldNames): array
    {
        $asserted = [];

        foreach ($fieldNames as $field) {
            $normalised = self::normalise($field);

            foreach (self::all() as $value => $spellings) {
                if (in_array($normalised, $spellings, true) && ! in_array($value, $asserted, true)) {
                    $asserted[] = $value;
                }
            }
        }

        return $asserted;
    }

    /**
     * Every spelling the register recognises, for a rule that has to scan source
     * rather than call {@see assertedIn()}.
     *
     * @return list<string>
     */
    public static function spellings(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::all()))));
    }

    /**
     * `API-017`: casing and separators carry no meaning, so they carry none here
     * either. A caller cannot evade the check by changing an underscore.
     */
    private static function normalise(string $field): string
    {
        return strtolower(str_replace(['_', '-', ' '], '', $field));
    }
}
