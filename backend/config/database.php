<?php

declare(strict_types=1);

use Pdo\Mysql;

/*
 * Database connections.
 *
 * `DB-001` / `DADR-01`: one MySQL instance and one schema. No second engine is
 * configured — PostgreSQL is an excluded technology (README §3.1), and `TC-030` ‡
 * forbids an in-memory substitute for an integration test, so no SQLite
 * connection exists to fall back to by accident.
 *
 * `DADR-09` requires three accounts against that one schema:
 *
 *   mysql            the application account — SELECT/INSERT/UPDATE/DELETE on
 *                    op_, proj_ and mch_; SELECT/INSERT only on ev_ and led_;
 *                    no DDL (DB-118 ‡, DB-119 ‡)
 *   mysql_migration  DDL, used only by migrations (DB-215)
 *   mysql_read       SELECT, used for reporting
 *
 * The grants themselves are not configuration; they are asserted by a check that
 * attempts the forbidden operation and requires the server to refuse
 * (`DB-121` ‡, `DB-205` ‡).
 */

return [

    'default' => env('DB_CONNECTION', 'mysql'),

    'connections' => [

        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'cmp'),
            'username' => env('DB_USERNAME'),
            'password' => env('DB_PASSWORD'),
            'unix_socket' => env('DB_SOCKET', ''),
            // DB-010: a Unicode character set covering the full range, with a
            // deterministic collation. utf8mb4_0900_as_cs is accent- and
            // case-sensitive, so two distinct strings never compare equal.
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_as_cs',
            // DB-002: domain membership is carried by the table name itself.
            // A connection-level prefix would hide it.
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            // DB-009 ‡: every table uses InnoDB.
            'engine' => 'InnoDB',
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mysql_migration' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'cmp'),
            'username' => env('DB_MIGRATION_USERNAME'),
            'password' => env('DB_MIGRATION_PASSWORD'),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_as_cs',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => 'InnoDB',
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mysql_read' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'cmp'),
            'username' => env('DB_READ_USERNAME'),
            'password' => env('DB_READ_PASSWORD'),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_as_cs',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => 'InnoDB',
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        /*
         * Used only by db:provision-accounts, and by nothing at runtime.
         *
         * DADR-09 issues no account to any component other than the platform,
         * and DB-122 ‡ forbids the application holding a credential that could
         * alter the evidential domain in any environment. This connection exists
         * so that the three accounts can be created and narrowed; where a
         * database administrator does that instead, it is left unconfigured.
         */
        'mysql_provisioning' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'cmp'),
            'username' => env('DB_PROVISIONING_USERNAME'),
            'password' => env('DB_PROVISIONING_PASSWORD'),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_as_cs',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => 'InnoDB',
        ],

    ],

    /*
     * The migration bookkeeping table is machinery, so it carries the mch_
     * prefix like every other machinery table (`DB-002`, `DB-007`, `DB-014`).
     */
    'migrations' => [
        'table' => 'mch_migrations',
        'update_date_on_publish' => true,
    ],

];
