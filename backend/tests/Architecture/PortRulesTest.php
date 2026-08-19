<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Cmp\Domain\Payment\Port\PaymentVerificationPort;
use Cmp\Domain\Ride\Port\MappingPort;
use Cmp\Domain\SafetyIncident\Port\EmergencyDispatchPort;
use Cmp\Domain\Shared\Notification\NotificationPort;
use Cmp\Domain\Shared\Port\Port;
use Cmp\Domain\User\Port\IdentityVerificationPort;
use Cmp\Infrastructure\Laravel\Providers\PortServiceProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * CMP-IMP-030 — the structural half of the port and adapter skeleton.
 *
 * `BE-153` ‡ — **no provider type appears above the adapter** — is the rule that
 * keeps `BE-162` true, and it is not something a reviewer will reliably catch by
 * eye. `BE-150` says the ports themselves name no provider, which is the same
 * rule one layer further in.
 */
final class PortRulesTest extends TestCase
{
    /**
     * The five ports of CMP-DOC-09 §12.
     */
    private const PORTS = [
        PaymentVerificationPort::class,
        IdentityVerificationPort::class,
        NotificationPort::class,
        MappingPort::class,
        EmergencyDispatchPort::class,
    ];

    /**
     * Names that would identify a supplier.
     *
     * Two are directed and three are undecided (`BE-161`), so the list covers
     * the directed pair and the shapes the undecided three would take. Matched
     * case-insensitively as whole words, so that a comment naming one is caught
     * as surely as an import — a port that documents itself in a provider's
     * vocabulary has already coupled to it.
     */
    private const PROVIDER_NAMES = [
        'Firebase', 'FCM', 'Google', 'Razorpay', 'Stripe', 'Twilio', 'PhonePe', 'Paytm', 'Gpay',
    ];

    /**
     * The only directory in which a provider type may appear (`BE-153` ‡).
     */
    private const ADAPTER_DIRECTORY = 'src/Infrastructure/Adapter/';

    public function test_the_five_ports_are_declared(): void
    {
        // BE-149: a port for each capability the platform does not itself
        // provide. BE-164: the emergency dispatch port exists so that its
        // absence is visible rather than assumed.
        foreach (self::PORTS as $port) {
            self::assertTrue(interface_exists($port), $port.' is not declared.');
        }

        self::assertSame(self::PORTS, array_keys(PortServiceProvider::ports()));
    }

    public function test_every_port_in_the_domain_obeys_the_port_contract_and_is_registered(): void
    {
        // Discovered from the source tree rather than from a list, so a sixth
        // port added tomorrow is held to BE-150, BE-151 and BE-156 ‡ — and
        // appears in the register — without anyone remembering to add it here.
        $discovered = [];

        foreach (self::sourceFiles() as $relative => $contents) {
            if (! str_starts_with($relative, 'src/Domain/') || ! str_ends_with($relative, 'Port.php')) {
                continue;
            }

            if (preg_match('/^namespace ([^;]+);/m', $contents, $namespace) !== 1) {
                continue;
            }

            $interface = $namespace[1].'\\'.basename($relative, '.php');

            if ($interface === Port::class) {
                continue;
            }

            self::assertTrue(interface_exists($interface), $interface.' is not declared.');
            self::assertContains(
                Port::class,
                class_implements($interface) ?: [],
                $interface.' must extend Port, which is where BE-150, BE-151 and BE-156 ‡ are stated.',
            );
            self::assertArrayHasKey(
                $interface,
                PortServiceProvider::ports(),
                'BE-149/BE-162: every port is in the one register, with what blocks an adapter for it.',
            );

            $discovered[] = $interface;
        }

        sort($discovered);
        $expected = self::PORTS;
        sort($expected);

        self::assertSame($expected, $discovered, 'The register and the source tree must agree on which ports exist.');
    }

    public function test_every_port_is_declared_in_the_domain(): void
    {
        // BE-036 / BE-150: declared in Domain, in domain terms.
        foreach (self::PORTS as $port) {
            self::assertStringStartsWith('Cmp\\Domain\\', $port);
            self::assertTrue((new ReflectionClass($port))->isInterface());
        }
    }

    public function test_no_port_names_a_provider(): void
    {
        // BE-150. A port called after a supplier has to change when the supplier
        // does, which is the coupling BE-162 forbids.
        foreach (self::PORTS as $port) {
            $file = (new ReflectionClass($port))->getFileName();
            self::assertIsString($file);

            $contents = file_get_contents($file);
            self::assertIsString($contents);

            foreach (self::PROVIDER_NAMES as $provider) {
                self::assertDoesNotMatchRegularExpression(
                    '/\b'.preg_quote($provider, '/').'\b/i',
                    $contents,
                    sprintf('BE-150: %s names %s.', $port, $provider),
                );
            }
        }
    }

    public function test_no_provider_type_appears_above_the_adapter(): void
    {
        // BE-153 ‡ / TC-037 rule 7.
        $offenders = [];

        foreach (self::sourceFiles() as $relative => $contents) {
            if (str_starts_with($relative, self::ADAPTER_DIRECTORY)) {
                continue;
            }

            foreach (self::PROVIDER_NAMES as $provider) {
                if (preg_match('/\b'.preg_quote($provider, '/').'\b/i', $contents) === 1) {
                    $offenders[] = $relative.' → '.$provider;
                }
            }
        }

        self::assertSame([], $offenders, 'BE-153 ‡: a provider type belongs in '.self::ADAPTER_DIRECTORY.' and nowhere else.');
    }

    public function test_the_withheld_port_has_no_adapter(): void
    {
        // BAD-DEC-011 is open and no response capability is staffed.
        // BAD-RISK-005: a safety control with no response behind it is a
        // liability. ADM-187/ADM-191 forbid stubbing, prototyping, hiding behind
        // a role, disabling, flagging or marking it "coming soon".
        self::assertSame([EmergencyDispatchPort::class], PortServiceProvider::withheldPorts());

        foreach (self::sourceFiles() as $relative => $contents) {
            // A README explaining the withholding is not an adapter; a PHP file
            // implementing the port would be.
            if (! str_starts_with($relative, self::ADAPTER_DIRECTORY) || ! str_ends_with($relative, '.php')) {
                continue;
            }

            self::assertStringNotContainsString(
                'EmergencyDispatchPort',
                $contents,
                'BAD-DEC-011: an adapter for emergency dispatch is withheld, not merely unwritten.',
            );
        }
    }

    public function test_every_port_records_what_blocks_an_adapter_for_it(): void
    {
        // A register of five ports with nothing bound reads as work nobody got
        // to. Each entry says which decision or feature it waits on.
        foreach (PortServiceProvider::ports() as $port => $reason) {
            self::assertNotSame('', trim($reason), $port.' must record what blocks an adapter for it.');
        }
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
            if (! $file->isFile()) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            self::assertIsString($contents);

            $files[str_replace('\\', '/', substr($file->getPathname(), strlen($root)))] = $contents;
        }

        return $files;
    }
}
