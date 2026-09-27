<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * `SRS-REQ-153` — *"The software shall record **every** transition of an
 * authoritative state with the time and the cause."*
 *
 * `BE-178` says the same thing from the backend side. Between them the statement
 * has two halves, and only one of them was observed.
 *
 * - **The record carries the time and the cause.** `ApplyTransitionTest` proves
 *   it: the action names the model and the trigger, the actor is carried, and
 *   `occurredAt()` is asserted against a fixed clock.
 * - **Every transition is recorded.** Nothing observed this. `StateMachine::apply()`
 *   is pure — it validates a transition and *returns the destination state* — so a
 *   caller can obtain a destination and persist it without evidence ever being
 *   written, and every test in the platform would still pass. `ApplyTransition` is
 *   the class that joins the two, and it was the only thing making the word
 *   *"every"* true.
 *
 * So the rule is the one `SEC-037` ‡ needed in {@see InterfaceStructureRulesTest}:
 * **wherever the engine is applied, the same method records.** It names no file, so
 * a second application path added anywhere must evidence too — which is the
 * statement, rather than a note about today's arrangement.
 *
 * ## Why `StateMachine` itself is excluded
 *
 * `StateMachine::permits()` calls `apply()` to answer whether a transition *would*
 * be allowed. Nothing is applied and nothing may be recorded: a probe that wrote
 * evidence would record transitions that never happened, which `DB-125` ‡ makes
 * permanent. The exclusion is safe because the class is Domain — `BE-002` and
 * Deptrac keep every repository and writer out of it, so nothing inside it can
 * persist a destination even if it wanted to.
 *
 * ## What this does not claim
 *
 * A service that wrote a state column directly, never consulting the engine, would
 * not be recognised here. That is `BE-176` ‡'s subject rather than this one, and it
 * is not checkable until an aggregate declares a state model — `BE-017`'s nine are
 * unbuilt. The gap is recorded rather than covered by a looser rule (`TC-042`).
 */
final class StateTransitionRulesTest extends TestCase
{
    use ReadsMethods;

    /**
     * The engine's own namespace, where `apply()` is computed rather than applied.
     */
    private const ENGINE = 'src/Domain/Shared/StateMachine/';

    public function test_every_application_of_a_transition_records_it(): void
    {
        // SRS-REQ-153 / BE-178. The half that the word "every" rests on.
        $offenders = [];
        $callSites = 0;

        foreach (self::filesNamingTheEngine() as $relative => $code) {
            foreach (self::methodsIn($code) as $name => $body) {
                if (! str_contains($body, '->apply(')) {
                    continue;
                }

                $callSites++;

                if (! str_contains($body, '->record(')) {
                    $offenders[] = $relative.'::'.$name.'()';
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            'SRS-REQ-153 / BE-178: every transition of an authoritative state is recorded. StateMachine::apply() '
            .'returns a destination and writes nothing, so a caller that does not record has transitioned a '
            .'state with no evidence that it did.',
        );

        // A rule with no call site proves nothing. ApplyTransition is the one.
        self::assertGreaterThan(
            0,
            $callSites,
            'No application of the state machine was examined, so the rule ran on nothing.',
        );
    }

    /**
     * `TC-041` / `TC-024` ‡ — the detector fires on a method that applies without
     * recording, and not on one that does both.
     */
    public function test_the_detector_recognises_an_application_that_does_not_record(): void
    {
        $recording = <<<'PHP'
            <?php

            final class Recording
            {
                public function apply(): string
                {
                    $destination = $this->machine->apply($model, $state, $trigger);

                    $this->evidence->record(Evidence::of($actor, $action, $subject));

                    return $destination;
                }
            }
            PHP;

        $silent = <<<'PHP'
            <?php

            final class Silent
            {
                public function advance(): void
                {
                    $destination = $this->machine->apply($model, $state, $trigger);

                    $this->rides->save($ride->movedTo($destination));
                }
            }
            PHP;

        self::assertSame([], self::offendingMethodsIn($recording));
        self::assertSame(['advance'], self::offendingMethodsIn($silent));

        // And a method that records without applying is not this rule's business.
        self::assertSame([], self::offendingMethodsIn(<<<'PHP'
            <?php

            final class Evidencing
            {
                public function raise(): void
                {
                    $this->evidence->record(Evidence::of($actor, $action, $subject));
                }
            }
            PHP));
    }

    /**
     * `TC-024` ‡ — the method reader finds a method, so a rule reporting no
     * offenders is reporting on something it read.
     */
    public function test_the_method_reader_reads_the_class_the_rule_exists_for(): void
    {
        $applying = self::filesNamingTheEngine();

        self::assertArrayHasKey('src/Application/Shared/StateMachine/ApplyTransition.php', $applying);

        $methods = self::methodsIn($applying['src/Application/Shared/StateMachine/ApplyTransition.php']);

        self::assertArrayHasKey('apply', $methods);
        self::assertStringContainsString('->apply(', $methods['apply']);
        self::assertStringContainsString('->record(', $methods['apply']);
    }

    /**
     * `TC-024` ‡ — the reader keeps the defect it was written to fix.
     *
     * `ApplyTransition` declares a promoted constructor that ends `) {}` and then
     * the method this whole rule is about. A reader that looked for a closing brace
     * at a known indentation attributed the second to the first, and the rule then
     * examined a class in which nothing applied a transition.
     */
    public function test_the_method_reader_survives_an_empty_constructor_body(): void
    {
        $methods = self::methodsIn(self::codeOf(<<<'PHP'
            <?php

            final class Promoted
            {
                public function __construct(private readonly StateMachine $machine) {}

                public function advance(): string
                {
                    return $this->machine->apply($model, $state, $trigger);
                }
            }
            PHP));

        self::assertSame(['__construct', 'advance'], array_keys($methods));
        self::assertSame('', $methods['__construct']);
        self::assertStringContainsString('->apply(', $methods['advance']);

        // And an interface declaration, which has no body to read at all.
        self::assertSame([], self::methodsIn(self::codeOf('<?php interface Engine { public function apply(): string; }')));
    }

    /**
     * @return list<string>
     */
    private static function offendingMethodsIn(string $contents): array
    {
        $offenders = [];

        foreach (self::methodsIn(self::codeOf($contents)) as $name => $body) {
            if (str_contains($body, '->apply(') && ! str_contains($body, '->record(')) {
                $offenders[] = $name;
            }
        }

        return $offenders;
    }

    /**
     * Every source file that names the engine, except the engine itself.
     *
     * @return array<string, string> relative path => code, comments removed
     */
    private static function filesNamingTheEngine(): array
    {
        $found = [];

        foreach (self::sourceFiles() as $relative => $contents) {
            if (str_starts_with($relative, self::ENGINE)) {
                continue;
            }

            $code = self::codeOf($contents);

            // The type itself, on a word boundary: `StateMachineRefusal` is a
            // reason vocabulary that two providers name without ever holding an
            // engine, and a file that merely spells the word is not a file that
            // applies a transition.
            if (preg_match('/\bStateMachine\b/', $code) === 1) {
                $found[$relative] = $code;
            }
        }

        return $found;
    }

    /**
     * @return array<string, string> relative path => contents
     */
    private static function sourceFiles(): array
    {
        $root = dirname(__DIR__, 2).'/';
        $files = [];

        /** @var iterable<SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'src', RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            self::assertIsString($contents);

            $files[str_replace('\\', '/', substr($file->getPathname(), strlen($root)))] = $contents;
        }

        return $files;
    }
}
