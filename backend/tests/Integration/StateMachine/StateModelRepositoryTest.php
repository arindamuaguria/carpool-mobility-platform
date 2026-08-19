<?php

declare(strict_types=1);

namespace Tests\Integration\StateMachine;

use Cmp\Domain\Shared\StateMachine\StateModelNotDeclared;
use Cmp\Domain\Shared\StateMachine\StateModelRepository;
use Cmp\Infrastructure\Persistence\StateMachine\DatabaseStateModelRepository;
use InvalidArgumentException;
use Tests\Integration\IntegrationTestCase;

/**
 * CMP-IMP-032 — state model definitions read from `cfg_state_models`.
 *
 * Level 3 (`TC-030` ‡). `DB-155` puts the definitions in `cfg_`, `DB-152` makes
 * a change a new version rather than an update in place, and `SRS-REQ-158`
 * rejects an unconfigured model — all properties of what is stored and read
 * back.
 *
 * The models written here are **test models**. Seeding a real one would be
 * inventing business policy, which is what `BADR-13` refused to do.
 */
final class StateModelRepositoryTest extends IntegrationTestCase
{
    private const MODEL = 'test.lifecycle';

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearModels();
    }

    protected function tearDown(): void
    {
        $this->clearModels();

        parent::tearDown();
    }

    public function test_the_table_ships_empty(): void
    {
        // BADR-13: "six are undecided; this is inventing business policy." Six of
        // the ten models in CMP-DOC-06 §7.2 are undefined, and none is seeded.
        $rows = $this->readConnection()->select(
            'SELECT COUNT(*) AS total FROM '.DatabaseStateModelRepository::TABLE
            .' WHERE model_name NOT LIKE ?',
            ['test.%'],
        );

        self::assertSame(0, (int) $rows[0]->total);
    }

    public function test_an_unconfigured_model_is_rejected_rather_than_defaulted(): void
    {
        // SRS-REQ-158 / SRS-REQ-113. A permissive default would be the business
        // policy BADR-13 declined to invent.
        self::assertFalse($this->repository()->hasModelNamed(self::MODEL));

        $this->expectException(StateModelNotDeclared::class);
        $this->repository()->modelNamed(self::MODEL);
    }

    public function test_a_declared_model_is_read_back(): void
    {
        $this->applyModel(1, ['drafted', 'published'], [['from' => 'drafted', 'trigger' => 'publish', 'to' => 'published']]);

        $model = $this->repository()->modelNamed(self::MODEL);

        self::assertSame(['drafted', 'published'], $model->states());
        self::assertSame('published', $model->destinationOf('drafted', 'publish'));
        self::assertNull($model->destinationOf('drafted', 'withdraw'));
    }

    public function test_the_definition_in_force_is_the_highest_version(): void
    {
        // DB-152: a change appends a version rather than updating in place.
        $this->applyModel(1, ['drafted', 'published'], [['from' => 'drafted', 'trigger' => 'publish', 'to' => 'published']]);
        $this->applyModel(2, ['drafted', 'published', 'withdrawn'], [
            ['from' => 'drafted', 'trigger' => 'publish', 'to' => 'published'],
            ['from' => 'published', 'trigger' => 'withdraw', 'to' => 'withdrawn'],
        ]);

        $model = $this->repository()->modelNamed(self::MODEL);

        self::assertSame(['drafted', 'published', 'withdrawn'], $model->states());
        self::assertSame('withdrawn', $model->destinationOf('published', 'withdraw'));

        // The earlier version is still there — a decision taken under it can be
        // re-examined (BE-167).
        $rows = $this->readConnection()->select(
            'SELECT COUNT(*) AS total FROM '.DatabaseStateModelRepository::TABLE.' WHERE model_name = ?',
            [self::MODEL],
        );
        self::assertSame(2, (int) $rows[0]->total);
    }

    public function test_a_definition_naming_a_state_it_does_not_permit_is_refused_on_read(): void
    {
        // BE-174 / ARCH-148: an invalid configuration is rejected rather than
        // applied — including one that reached the table by some other route.
        // A definition that could name a state outside its own permitted set
        // would let SRS-REQ-158 be bypassed by the definition itself.
        $this->applyModel(1, ['drafted'], [['from' => 'drafted', 'trigger' => 'publish', 'to' => 'published']]);

        $this->expectException(InvalidArgumentException::class);
        $this->repository()->modelNamed(self::MODEL);
    }

    public function test_the_application_account_cannot_update_a_declared_model(): void
    {
        // DB-152 as a property of the credential. DADR-09 states no grant for the
        // configuration domain; the narrowest reading — SELECT and INSERT — is
        // applied and reported, and this is what it buys.
        $this->applyModel(1, ['drafted'], []);

        self::assertTrue(
            $this->refused(fn () => $this->applicationConnection()->update(
                'UPDATE '.DatabaseStateModelRepository::TABLE.' SET definition = ? WHERE model_name = ?',
                ['{"states":["anything"],"transitions":[]}', self::MODEL],
            )),
            'DB-152: a change appends a version; an in-place edit must be refused by the server.',
        );
    }

    public function test_the_application_account_cannot_delete_a_declared_model(): void
    {
        $this->applyModel(1, ['drafted'], []);

        self::assertTrue(
            $this->refused(fn () => $this->applicationConnection()->delete(
                'DELETE FROM '.DatabaseStateModelRepository::TABLE.' WHERE model_name = ?',
                [self::MODEL],
            )),
        );
    }

    /**
     * @param  list<string>  $states
     * @param  list<array{from: string, trigger: string, to: string}>  $transitions
     */
    private function applyModel(int $version, array $states, array $transitions): void
    {
        $this->applicationConnection()->insert(
            'INSERT INTO '.DatabaseStateModelRepository::TABLE
            .' (model_name, version, definition, applied_by, applied_at) VALUES (?, ?, ?, ?, ?)',
            [
                self::MODEL,
                $version,
                json_encode(['states' => $states, 'transitions' => $transitions], JSON_THROW_ON_ERROR),
                'operator-1',
                '2026-08-19 09:30:00',
            ],
        );
    }

    private function repository(): StateModelRepository
    {
        return new DatabaseStateModelRepository($this->applicationConnection());
    }

    private function refused(callable $operation): bool
    {
        try {
            $operation();
        } catch (\Throwable) {
            return true;
        }

        return false;
    }

    private function clearModels(): void
    {
        $this->migrationConnection()->delete(
            'DELETE FROM '.DatabaseStateModelRepository::TABLE.' WHERE model_name LIKE ?',
            ['test.%'],
        );
    }
}
