<?php

declare(strict_types=1);

namespace Tests\Integration\Evidence;

use Cmp\Infrastructure\Evidential\DatabaseEvidentialWriter;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * Puts the evidential log back to empty between tests.
 *
 * This needs a credential nothing in the platform has. `DB-118` ‡ withholds
 * `DELETE` on `ev_` from the application account, `DB-119` ‡ withholds `DDL`,
 * and `DB-120` ‡'s trigger refuses the delete outright — so clearing the table
 * means dropping the trigger, deleting under the provisioning connection, and
 * putting the trigger back. That the fixture has to work this hard is the rule
 * working.
 *
 * Both defences are restored **before** any test body runs. A test that found
 * them missing would be asserting nothing.
 */
trait ClearsTheEvidentialLog
{
    private function clearEvidentialLog(): void
    {
        $privileged = $this->provisioningConnection();

        $privileged->statement('DROP TRIGGER IF EXISTS ev_evidential_records_refuse_delete');
        $privileged->delete('DELETE FROM '.DatabaseEvidentialWriter::TABLE);
        $privileged->statement('ALTER TABLE '.DatabaseEvidentialWriter::TABLE.' AUTO_INCREMENT = 1');

        $this->restoreEvidentialTriggers();
    }

    private function restoreEvidentialTriggers(): void
    {
        $privileged = $this->provisioningConnection();

        $privileged->statement('DROP TRIGGER IF EXISTS ev_evidential_records_refuse_update');
        $privileged->statement('DROP TRIGGER IF EXISTS ev_evidential_records_refuse_delete');
        $privileged->unprepared(
            'CREATE TRIGGER ev_evidential_records_refuse_update BEFORE UPDATE ON ev_evidential_records FOR EACH ROW '
            ."SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'DB-108 : an evidential record is never updated by any code path.'"
        );
        $privileged->unprepared(
            'CREATE TRIGGER ev_evidential_records_refuse_delete BEFORE DELETE ON ev_evidential_records FOR EACH ROW '
            ."SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'DB-108 : an evidential record is never deleted by any code path.'"
        );
    }

    private function provisioningConnection(): Connection
    {
        $connection = $this->app->make(ConnectionResolverInterface::class)->connection('mysql_provisioning');

        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
