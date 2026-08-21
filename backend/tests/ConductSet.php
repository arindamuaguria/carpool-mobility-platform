<?php

declare(strict_types=1);

namespace Tests;

use Cmp\Application\Shared\Integrity\RecordsIntegrityEvents;
use Cmp\Application\Shared\Policy\ChangePolicyValue;
use Cmp\Infrastructure\Authorisation\EvidentialAuthorisationRefusals;
use Cmp\Infrastructure\Evidential\LoggingReconciliationRaises;
use Cmp\Infrastructure\User\RecordedSessionAnomalies;
use Tests\Integration\Authorisation\AuthorisationRefusalRecordingTest;
use Tests\Integration\Evidence\EvidentialLogTest;
use Tests\Integration\Integrity\IntegrityEventTest;
use Tests\Integration\Policy\PolicyStoreTest;
use Tests\Integration\User\SessionAnomalyRecordingTest;

/**
 * `SEC-206` ‡ — the eight conducts the platform records.
 *
 * *"The conduct set shall be: refused authorisation, assertion attempt,
 * rate-limit breach, authentication failure, session anomaly, operator override,
 * policy change, and chain divergence."* Eight, named, and closed — `SADR-15` and
 * `NFR-060` are what it serves.
 *
 * ## Conduct is evidential; the platform's own health is operational
 *
 * `SEC-203` ‡: *"security events concerning **an actor's conduct** shall be
 * evidential records."* `SEC-204`: *"security events concerning **the platform's
 * own health** shall be operational logs."* And `SEC-205` ‡ closes the gap
 * between them — operational logging shall not substitute for the evidential log.
 *
 * So the default for this set is **evidential**, and `SEC-207` ‡'s six fields are
 * exactly `Evidence`'s (`BE-107` ‡). Two entries are not, and each says why:
 * chain divergence is the platform's health rather than anybody's conduct, and a
 * session anomaly is **either**, depending on whether the platform knows whose
 * session it was.
 *
 * ## What the register makes checkable
 *
 * Five of the eight are wired. Three are not, and each names what stands in the
 * way — none of them is work nobody has done. `ConductSetTest` fails the build if
 * a wired conduct names a class that has gone, and `SEC-207` ‡ requires every
 * record to carry actor, action, subject, time, outcome and reason, which
 * `Evidence` enforces by construction for the evidential half.
 */
final class ConductSet
{
    /**
     * The eight, in `SEC-206` ‡'s order.
     *
     * @return array<string, array{
     *     conduct: string,
     *     status: string,
     *     writtenBy: ?string,
     *     provenBy: ?string,
     *     note: string,
     * }>
     */
    public static function all(): array
    {
        return [
            'refused_authorisation' => [
                'conduct' => 'A caller was refused an operation they were not entitled to.',
                'status' => ObligationRegister::ENFORCED,
                'writtenBy' => EvidentialAuthorisationRefusals::class,
                'provenBy' => AuthorisationRefusalRecordingTest::class,
                'note' => 'SEC-057 ‡ records every one, evidentially first and operationally second — a log '
                    .'line means the evidential record above it was written (BE-202).',
            ],
            'assertion_attempt' => [
                'conduct' => 'A caller asserted a value the platform alone determines.',
                'status' => ObligationRegister::ENFORCED,
                'writtenBy' => RecordsIntegrityEvents::class,
                'provenBy' => IntegrityEventTest::class,
                'note' => 'SADR-08 / FRD-FR-237 to FRD-FR-241: the whole request is rejected and the attempt '
                    .'recorded. API-214 ‡ and TC-037 ‡ rule 11 mean a request schema cannot accept such a '
                    .'field at all, so this records the attempt that got past a client rather than the '
                    .'platform tolerating one.',
            ],
            'rate_limit_breach' => [
                'conduct' => 'An attempt limit was exhausted.',
                'status' => ObligationRegister::BLOCKED,
                'writtenBy' => null,
                'provenBy' => null,
                'note' => 'SEC-025 decided the bound — five attempts per phone number per hour, no account '
                    .'lockout — and SEC-026 ‡ makes exhaustion a business refusal carrying its own reason '
                    .'identifier. But DB-043 requires the attempts to be retained against state rather than '
                    .'memory, and the attempts are **verification** attempts: CC-034 leaves the verification '
                    .'flow unbuildable because no delivery channel is specified. There is nothing yet to '
                    .'count, so there is nothing to breach.',
            ],
            'authentication_failure' => [
                'conduct' => 'A demonstration was presented and refused.',
                'status' => ObligationRegister::BLOCKED,
                'writtenBy' => null,
                'provenBy' => null,
                'note' => 'CC-034. SEC-015 makes authentication the demonstration of possession of a verified '
                    .'phone number, and no demonstration can be issued — CMP-DOC-16 §0.6.1 declines to '
                    .'specify a channel and BAD-DEP-006 leaves the provider unselected.',
            ],
            'session_anomaly' => [
                'conduct' => 'A token was presented that the store will not serve.',
                'status' => ObligationRegister::ENFORCED,
                'writtenBy' => RecordedSessionAnomalies::class,
                'provenBy' => SessionAnomalyRecordingTest::class,
                'note' => 'The one conduct that goes to either sink. A terminated or expired token names an '
                    .'owner the platform knows, so SEC-203 ‡ makes it conduct and it is evidential — which is '
                    .'DB-044 ‡ making reuse detectable rather than merely impossible. An **unknown** token '
                    .'names nobody, BE-107 ‡ refuses a record that cannot say what it is about, and SEC-204 '
                    .'puts it in the operational log with no actor. SEC-048 ‡ is untouched: the three stay '
                    .'indistinguishable to a caller, and only the record differs.',
            ],
            'operator_override' => [
                'conduct' => 'An operator acted against what the platform would otherwise do.',
                'status' => ObligationRegister::BLOCKED,
                'writtenBy' => null,
                'provenBy' => null,
                'note' => 'BAD-DEC-006 leaves the role set undecided (SEC-063) and ADM-168 records that the '
                    .'administrative unit cannot start. SEC-010 ‡ and SEC-062 ‡ are already structural — an '
                    .'operator gains capability, never exemption, and Authoriser::authorise() returns void so '
                    .'there is nothing an override could ride on.',
            ],
            'policy_change' => [
                'conduct' => 'An operator changed a policy value.',
                'status' => ObligationRegister::ENFORCED,
                'writtenBy' => ChangePolicyValue::class,
                'provenBy' => PolicyStoreTest::class,
                'note' => 'BE-173 / ARCH-115 / DB-154: written in the same transaction as the change '
                    .'(BE-106 ‡), and the record points at the version row rather than carrying the values '
                    .'— CC-029 settled that and no seventh field was added.',
            ],
            'chain_divergence' => [
                'conduct' => 'Evidential chain verification found a record that does not verify.',
                'status' => ObligationRegister::ENFORCED,
                'writtenBy' => LoggingReconciliationRaises::class,
                'provenBy' => EvidentialLogTest::class,
                'note' => 'SEC-111 requires divergence reported at the record it begins from, which the '
                    .'verification does, and BE-115 raises it for reconciliation rather than repairing it.',
            ],
        ];
    }

    /**
     * The keys and values a log line may never carry (`SEC-208` ‡).
     *
     * *"No log shall contain a credential, a session token, a payment instrument
     * detail, a precise location or a contact detail."*
     *
     * `SEC-210` makes the mechanism **construction, not redaction**: the value is
     * not passed to the logger. A redaction list is what a platform builds when it
     * has already passed the value and hopes to catch it on the way out —
     * `SEC-144` and `SADR-09` take the same position about a query.
     *
     * So this is not a redaction list. It is what `LoggingRedactionRulesTest`
     * looks for **at the call site**, so that a value of a forbidden kind cannot
     * be handed over in the first place.
     *
     * @return list<string>
     */
    public static function forbiddenInALogLine(): array
    {
        return [
            // A credential or a demonstration (SEC-016 ‡, SEC-028 ‡).
            'password', 'credential', 'material', 'demonstration', 'secret', 'otp',
            // A session token, and its hash — SEC-038 ‡ keeps both out, because
            // the hash is what the store matches on.
            'token', 'token_hash', 'tokenhash', 'session_token',
            // A payment instrument detail (SADR-10).
            'card_number', 'cvv', 'pan', 'upi_pin', 'vpa',
            // A precise location (SEC-083 ‡, GPS-062).
            'latitude', 'longitude', 'lat', 'lng', 'coordinates', 'position',
            // A contact detail (BAD-RULE-043 makes the phone number the one the
            // platform holds).
            'phone', 'phone_number', 'email', 'contact',
        ];
    }
}
