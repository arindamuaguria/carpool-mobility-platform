<?php

declare(strict_types=1);

namespace Tests\Contract;

use Cmp\Application\Shared\Authorisation\AuthorisationRefusal;
use Cmp\Application\Shared\CorrelationIdentity;
use Cmp\Application\Shared\Failure\BusinessRefused;
use Cmp\Application\Shared\Failure\DependencyUnavailable;
use Cmp\Application\Shared\Failure\Failure;
use Cmp\Application\Shared\Failure\FailureBranch;
use Cmp\Application\Shared\Failure\FieldError;
use Cmp\Application\Shared\Failure\InternalFault;
use Cmp\Application\Shared\Failure\InvalidRequest;
use Cmp\Application\Shared\Failure\ReasonIdentifiers;
use Cmp\Application\Shared\Idempotency\IdempotencyRefusal;
use Cmp\Interface\Rest\FailureResponse;
use Illuminate\Http\JsonResponse;
use Tests\TestCase;

/**
 * `CMP-IMP-463`, `CMP-IMP-464`, `CMP-IMP-471` — the four branches, four shapes.
 *
 * `API-215` ‡ requires exactly this: *"a contract test shall exist for each of
 * the four error branches, asserting that a condition of one branch never returns
 * as another."* `API-073`–`API-076` are the four statements it is asserting, and
 * each is integrity-critical, so each has a negative test here (`FR-04`).
 *
 * Level 4 (`TC-031`): **shape only**. Nothing below asserts why a refusal
 * happened or whether it was correct to refuse — only that a refusal looks like a
 * refusal and like nothing else.
 */
final class ErrorBranchTest extends TestCase
{
    private const AT = '2026-08-20T12:00:00.000000Z';

    public function test_each_branch_carries_its_own_status(): void
    {
        // CMP-DOC-10 §8.1's table, which is the specification's and not this
        // adapter's.
        self::assertSame(400, self::respond(self::anInvalidRequest())->getStatusCode());
        self::assertSame(422, self::respond(self::aRuleDeclined())->getStatusCode());
        self::assertSame(409, self::respond(self::aStateConflict())->getStatusCode());
        self::assertSame(503, self::respond(self::anUnavailability())->getStatusCode());
        self::assertSame(500, self::respond(self::aFault())->getStatusCode());
    }

    public function test_each_branch_is_distinguishable_by_structure_alone(): void
    {
        // API-072 ‡, the half that matters: a client must be able to tell the
        // branch without first reading a discriminator field. Each branch owns a
        // top-level key, and no branch carries another's.
        $keys = array_values(FailureResponse::shapes());

        self::assertSame($keys, array_unique($keys), 'API-072 ‡: four branches, four distinct shapes.');

        foreach ([
            InvalidRequest::class => self::anInvalidRequest(),
            BusinessRefused::class => self::aRuleDeclined(),
            DependencyUnavailable::class => self::anUnavailability(),
            InternalFault::class => self::aFault(),
        ] as $class => $failure) {
            $body = self::body(self::respond($failure));
            $own = FailureResponse::shapes()[$class];

            self::assertArrayHasKey($own, $body, $class.' must carry its own key.');

            foreach (FailureResponse::shapes() as $other) {
                if ($other !== $own) {
                    self::assertArrayNotHasKey($other, $body, $class.' must not carry '.$other.'.');
                }
            }
        }
    }

    public function test_a_business_refusal_is_never_returned_as_an_internal_fault(): void
    {
        // Negative test for API-073 ‡ / BE-186 ‡. A refusal reaching a client as
        // a 500 would tell it the platform broke, when the platform decided.
        $body = self::body(self::respond(self::aRuleDeclined()));

        self::assertArrayNotHasKey('fault', $body);
        self::assertNotSame(500, self::respond(self::aRuleDeclined())->getStatusCode());
    }

    public function test_an_internal_fault_is_never_returned_as_a_business_refusal(): void
    {
        // Negative test for API-074 ‡. The other direction, and the worse one: a
        // client told "the platform declined" will not retry a fault that a retry
        // would have cleared.
        $body = self::body(self::respond(self::aFault()));

        self::assertArrayNotHasKey('refusal', $body);
    }

    public function test_a_dependency_unavailability_is_never_returned_as_a_business_refusal(): void
    {
        // Negative test for API-075 ‡ / BE-187. "The provider did not answer" is
        // not "the platform declined", and §8.6 puts them in different branches
        // because the client's treatment differs: hold and retry, against present
        // the reason.
        $body = self::body(self::respond(self::anUnavailability()));

        self::assertArrayNotHasKey('refusal', $body);
        self::assertArrayHasKey('unavailable', $body);
    }

    public function test_a_dependency_unavailability_is_never_returned_as_success(): void
    {
        // Negative test for API-076 ‡ / BE-152. The most damaging of the four: a
        // 2xx would have the client record an outcome the platform never reached.
        $response = self::respond(self::anUnavailability());

        self::assertGreaterThanOrEqual(400, $response->getStatusCode());
        self::assertArrayNotHasKey('data', self::body($response));
    }

    public function test_the_unavailable_branch_says_that_nothing_was_decided(): void
    {
        // API-089 ‡: "neither success nor failure". Stated in the body rather
        // than left to be inferred from a 503, because a client that infers it
        // wrongly either drops the intent or repeats an effect.
        $unavailable = self::section(self::respond(self::anUnavailability()), 'unavailable');

        self::assertTrue($unavailable['nothing_was_decided']);
    }

    public function test_the_unavailable_branch_names_no_provider(): void
    {
        // Negative test for API-090 / BE-150 / ARCH-063. The capability is named
        // because FRD-FR-257 requires the actor to be told what is unavailable;
        // the provider behind it is not the caller's business and naming it would
        // leak a supplier relationship the platform has not disclosed.
        $response = self::respond(self::anUnavailability());

        self::assertSame('payment verification', self::section($response, 'unavailable')['capability']);

        $encoded = strtolower(json_encode(self::body($response), JSON_THROW_ON_ERROR));

        foreach (['upi', 'firebase', 'google', 'provider', 'vendor', 'gateway'] as $leak) {
            self::assertStringNotContainsString($leak, $encoded);
        }
    }

    public function test_an_internal_fault_discloses_a_correlation_identity_and_nothing_else(): void
    {
        // Negative test for API-092 ‡: no stack, no query, no identifier of an
        // internal component. InternalFault cannot be constructed from a
        // throwable, so there is nothing to leak — this asserts the body agrees.
        self::assertSame(
            ['correlation' => 'correlation-1'],
            self::section(self::respond(self::aFault()), 'fault'),
        );
    }

    public function test_an_invalid_request_reports_every_offending_field(): void
    {
        // API-079: all detectable failures, not only the first. NFR-087 is the
        // reason — a caller correcting one field at a time is a caller making
        // four round trips.
        $failure = new InvalidRequest([
            new FieldError('origin', 'field.required', 'An origin is required.'),
            new FieldError('seats', 'field.not_an_integer', 'Seats must be a whole number.'),
        ]);

        $fields = self::section(self::respond($failure), 'invalid_request')['fields'];

        self::assertIsArray($fields);
        self::assertCount(2, $fields);
        self::assertSame(
            ['origin', 'seats'],
            array_map(
                static function (mixed $field): mixed {
                    self::assertIsArray($field);

                    return $field['field'];
                },
                $fields,
            ),
        );
    }

    public function test_a_business_refusal_carries_a_registered_identifier_and_a_default(): void
    {
        // API-081 ‡ and API-082 ‡ together, and API-083 is why both: the client
        // presents its own localised text keyed by the identifier and falls back
        // to the default only for one it does not recognise.
        $refusal = self::section(self::respond(self::aRuleDeclined()), 'refusal');

        self::assertIsString($refusal['reason']);
        self::assertTrue(ReasonIdentifiers::isRegistered($refusal['reason']));
        self::assertNotSame('', $refusal['default_text']);
    }

    public function test_every_registered_identifier_is_stable_and_namespaced(): void
    {
        // API-084: not removed or repurposed within a version. A register is what
        // holds it to that, and the shape below is what makes a collision between
        // two areas visible rather than accidental.
        foreach (ReasonIdentifiers::identifiers() as $identifier) {
            self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $identifier);
        }

        self::assertSame(
            ReasonIdentifiers::identifiers(),
            array_unique(ReasonIdentifiers::identifiers()),
        );
    }

    public function test_the_two_refusals_the_platform_can_actually_raise_are_registered(): void
    {
        // A register nothing checks is a list that drifts. These two are raised
        // by code that exists today.
        self::assertTrue(ReasonIdentifiers::isRegistered(AuthorisationRefusal::NotAvailableToYou->value));
        self::assertTrue(ReasonIdentifiers::isRegistered(IdempotencyRefusal::KeyReusedWithDifferentContent->value));
    }

    public function test_the_four_branches_are_the_only_four(): void
    {
        // API-071 ‡. FailureBranch has four cases and FailureResponse has four
        // shapes; a fifth of either without the other is a branch nobody can
        // serve or a shape nothing produces.
        self::assertCount(4, FailureBranch::cases());
        self::assertCount(4, FailureResponse::shapes());
    }

    public function test_every_branch_still_states_the_version_and_the_evaluation_time(): void
    {
        // API-022 and API-043 ‡ say "every response", and a failure is a
        // response. A client that could not date a refusal could not tell a stale
        // one from a current one.
        foreach ([self::anInvalidRequest(), self::aRuleDeclined(), self::anUnavailability(), self::aFault()] as $failure) {
            $meta = self::section(self::respond($failure), 'meta');

            self::assertSame(1, $meta['interface_version']);
            self::assertSame(self::AT, $meta['evaluated_at']);
        }
    }

    private static function respond(Failure $failure): JsonResponse
    {
        return FailureResponse::from($failure, self::AT);
    }

    /**
     * One top-level object of a response body, asserted to be one.
     *
     * The four branch keys and `meta` are all objects; reading through here keeps
     * the assertions typed rather than indexing into `mixed`.
     *
     * @return array<string, mixed>
     */
    private static function section(JsonResponse $response, string $key): array
    {
        $body = self::body($response);

        self::assertArrayHasKey($key, $body);
        self::assertIsArray($body[$key]);

        /** @var array<string, mixed> $section */
        $section = $body[$key];

        return $section;
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(JsonResponse $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private static function anInvalidRequest(): InvalidRequest
    {
        return InvalidRequest::forField('seats', 'field.required', 'Seats are required.');
    }

    private static function aRuleDeclined(): BusinessRefused
    {
        // AuthorisationRefusal is RuleDeclined — the platform declined, and no
        // change of state would make it decline differently.
        return new BusinessRefused(AuthorisationRefusal::NotAvailableToYou);
    }

    private static function aStateConflict(): BusinessRefused
    {
        // IdempotencyRefusal is a StateConflict: the key already stands against a
        // different request, which is a fact about state rather than a rule.
        return new BusinessRefused(IdempotencyRefusal::KeyReusedWithDifferentContent);
    }

    private static function anUnavailability(): DependencyUnavailable
    {
        return DependencyUnavailable::ofCapability('payment verification');
    }

    private static function aFault(): InternalFault
    {
        return new InternalFault(CorrelationIdentity::fromString('correlation-1'));
    }
}
