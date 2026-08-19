<?php

declare(strict_types=1);

namespace Tests\Contract;

use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Application\Shared\Integrity\AuthoritativeValues;
use Cmp\Application\Shared\Integrity\RecordsIntegrityEvents;
use Cmp\Interface\Rest\RequestSchema;
use Illuminate\Http\Request;
use LogicException;
use Tests\TestCase;

/**
 * `CMP-IMP-468` — authoritative fields absent from every request schema.
 *
 * `API-214` ‡ asks for exactly this contract test. Level 4 (`TC-031`): what a
 * schema accepts is shape, and nothing here asserts what any operation then does
 * with an accepted body — `API-040` ‡ puts validation against platform state in
 * the application layer, and level 5 is where that is exercised.
 */
final class RequestSchemaTest extends TestCase
{
    public function test_a_body_of_only_declared_fields_is_accepted(): void
    {
        $schema = $this->schema(['origin', 'destination']);

        self::assertNull($schema->refusalFor(self::body(['origin' => 'a', 'destination' => 'b']), self::anActor()));
    }

    public function test_an_undeclared_field_refuses_the_whole_request(): void
    {
        // Negative test for API-038 ‡. AADR-06 rejected ignore-on-input: a schema
        // that dropped the field quietly would leave the client believing it had
        // set something.
        $refusal = $this->schema(['origin'])->refusalFor(
            self::body(['origin' => 'a', 'nickname' => 'b']),
            self::anActor(),
        );

        self::assertNotNull($refusal);
        self::assertCount(1, $refusal->fieldErrors());
        self::assertSame('nickname', $refusal->fieldErrors()[0]->field());
    }

    public function test_every_undeclared_field_is_named_and_not_only_the_first(): void
    {
        // API-079 / NFR-087: a caller correcting one field per round trip is a
        // caller making several.
        $refusal = $this->schema(['origin'])->refusalFor(
            self::body(['one' => 1, 'two' => 2, 'three' => 3]),
            self::anActor(),
        );

        self::assertNotNull($refusal);
        self::assertCount(3, $refusal->fieldErrors());
    }

    public function test_a_field_asserting_an_authoritative_value_is_recorded_as_an_integrity_event(): void
    {
        // API-039 ‡ / FRD-FR-241 / SADR-08. "Additionally" — the request is
        // already refused; this is the second effect.
        $recorder = new CollectedIntegrityEvents;

        $refusal = $this->schema(['origin'], $recorder)->refusalFor(
            self::body(['origin' => 'a', 'fare' => 500]),
            self::anActor(),
        );

        self::assertNotNull($refusal);
        self::assertCount(1, $recorder->events);
        self::assertSame(['fare'], $recorder->events[0]['values']);
        self::assertSame('test.operation', $recorder->events[0]['operation']);
    }

    public function test_an_ordinary_unknown_field_is_refused_without_an_integrity_event(): void
    {
        // A typo is a mistake, not an attempt. Recording every unknown field as
        // an integrity event would make the record useless for the repetition
        // API-204 ‡ wants treatable as abuse.
        $recorder = new CollectedIntegrityEvents;

        $this->schema(['origin'], $recorder)->refusalFor(
            self::body(['origin' => 'a', 'oragin' => 'b']),
            self::anActor(),
        );

        self::assertSame([], $recorder->events);
    }

    public function test_the_spelling_of_an_authoritative_field_does_not_evade_detection(): void
    {
        // API-017: casing and separators carry no meaning. A client team writing
        // seatsAvailable is asserting seat counts exactly as much as one writing
        // seats_available.
        foreach (['seatsAvailable', 'seats_available', 'SEATS-AVAILABLE'] as $spelling) {
            $recorder = new CollectedIntegrityEvents;

            $this->schema(['origin'], $recorder)->refusalFor(
                self::body(['origin' => 'a', $spelling => 3]),
                self::anActor(),
            );

            self::assertSame(['seat counts'], $recorder->events[0]['values'], $spelling.' evaded API-039 ‡.');
        }
    }

    public function test_the_record_carries_the_canonical_value_and_never_what_the_caller_sent(): void
    {
        // BE-201 ‡: caller-supplied content stays out of the log. The value the
        // caller tried to assert is the one thing a reader might want and must
        // not have.
        $recorder = new CollectedIntegrityEvents;

        $this->schema(['origin'], $recorder)->refusalFor(
            self::body(['walletBalance' => '9999.99']),
            self::anActor(),
        );

        self::assertSame(['balances'], $recorder->events[0]['values']);
        self::assertStringNotContainsString('9999.99', json_encode($recorder->events, JSON_THROW_ON_ERROR));
    }

    public function test_a_schema_declaring_an_authoritative_field_cannot_be_used_at_all(): void
    {
        // Negative test for API-037 ‡, made structural. A schema accepting `fare`
        // is not something downstream validation can put right, so it fails the
        // first time it is used rather than the first time it is exploited.
        $this->expectException(LogicException::class);

        $this->schema(['origin', 'fare'])->refusalFor(self::body(['origin' => 'a']), self::anActor());
    }

    public function test_the_register_covers_all_seven_of_api_037(): void
    {
        // API-037 ‡ names seven. A register that had lost one would leave that
        // value assertable and nobody would see it happen.
        self::assertSame(
            ['fare', 'verification standing', 'payment status', 'seat counts', 'ratings', 'balances', 'trip state'],
            array_keys(AuthoritativeValues::all()),
        );
    }

    /**
     * @param  list<string>  $fields
     */
    private function schema(array $fields, ?RecordsIntegrityEvents $recorder = null): RequestSchema
    {
        return new class($recorder ?? new CollectedIntegrityEvents, $fields) extends RequestSchema
        {
            /**
             * @param  list<string>  $fields
             */
            public function __construct(RecordsIntegrityEvents $events, private readonly array $fields)
            {
                parent::__construct($events);
            }

            public function fields(): array
            {
                return $this->fields;
            }

            public function operation(): string
            {
                return 'test.operation';
            }
        };
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private static function body(array $body): Request
    {
        return Request::create('/api/v1/test', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($body, JSON_THROW_ON_ERROR));
    }

    private static function anActor(): ActorReference
    {
        return ActorReference::fromString('actor-1');
    }
}

/**
 * Keeps the integrity events a schema raised, so a shape test can read them.
 *
 * Not a second recorder: `API-039` ‡'s record is written by
 * `EvidentialIntegrityEvents`, and that it reaches `ev_` is level 3's.
 */
final class CollectedIntegrityEvents implements RecordsIntegrityEvents
{
    /** @var list<array{actor: string, operation: string, values: list<string>}> */
    public array $events = [];

    public function record(ActorReference $actor, string $operation, array $authoritativeValues): void
    {
        $this->events[] = [
            'actor' => $actor->toString(),
            'operation' => $operation,
            'values' => $authoritativeValues,
        ];
    }
}
