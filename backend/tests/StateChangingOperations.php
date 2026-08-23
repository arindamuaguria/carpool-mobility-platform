<?php

declare(strict_types=1);

namespace Tests;

use Cmp\Application\Shared\ApplicationService;
use Cmp\Application\Shared\StateChangingCommand;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every state-changing operation the platform performs — **derived, not listed**.
 *
 * `BE-211` ‡: *"Idempotent replay shall be tested for **every** state-changing
 * operation."* A list kept beside the code answers that only until somebody adds
 * an operation and forgets the list, which is the failure mode the statement
 * exists to prevent — so the set is read from the source instead.
 *
 * ## How an operation is recognised
 *
 * `ApplicationService::execute()` takes the idempotent path when, and only when,
 * the command it is handed implements {@see StateChangingCommand}. So a service
 * is state-changing exactly when it is written to accept one, and a service
 * declares which command it accepts the only way it can — by testing for it:
 *
 * ```php
 * if (! $command instanceof RaiseIncidentCommand) { …
 * ```
 *
 * That `instanceof` is what is read here. It is a narrow signal and deliberately
 * so: a service that accepted a state-changing command without ever naming its
 * type could not use it, because `Command` carries no idempotency key.
 *
 * ## What this is not
 *
 * It is not a count of operations on the REST surface. `EstablishSession` has no
 * REST caller yet — `CC-034` blocks registration — and it is state-changing all
 * the same, so `BE-211` ‡ reaches it. An obligation about operations is not an
 * obligation about endpoints.
 */
final class StateChangingOperations
{
    /**
     * Service short name => the state-changing command it accepts.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        $operations = [];

        foreach (self::applicationFiles() as $contents) {
            if (preg_match('/final class (\w+) extends ApplicationService/', $contents, $class) !== 1) {
                continue;
            }

            preg_match_all('/instanceof (\w+Command)\b/', $contents, $commands);

            foreach (array_unique($commands[1]) as $command) {
                $resolved = self::resolve($command);

                if ($resolved === null || ! is_a($resolved, StateChangingCommand::class, true)) {
                    continue;
                }

                $operations[$class[1]] = $command;
            }
        }

        ksort($operations);

        return $operations;
    }

    /**
     * The fully qualified name of a command named only by its short one.
     *
     * The Application layer has two areas that hold commands. A command in a
     * third would return null here and be silently skipped — so
     * EveryStateChangingOperationReplaysTest asserts the derived set by name,
     * which is what would notice.
     */
    private static function resolve(string $short): ?string
    {
        foreach (['Cmp\\Application\\User\\', 'Cmp\\Application\\Safety\\'] as $namespace) {
            if (class_exists($namespace.$short)) {
                return $namespace.$short;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function applicationFiles(): array
    {
        $root = dirname(__DIR__).'/src/Application';
        $contents = [];

        /** @var iterable<SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            if (is_string($source)) {
                $contents[] = $source;
            }
        }

        return $contents;
    }
}
