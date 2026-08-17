<?php

declare(strict_types=1);

namespace Melograno\UsageTracker\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Melograno\UsageTracker\Collectors\BaseCollector;
use Melograno\UsageTracker\Collectors\Plugin\AmeliaCollector;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class AmeliaCollectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('AMELIA_VERSION')) {
            define('AMELIA_VERSION', '1.0.0-test');
        }

        if (!defined('AMELIA_URL')) {
            define('AMELIA_URL', 'https://example.com/wp-content/plugins/ameliabooking/');
        }

        if (!class_exists(\AmeliaBooking\Infrastructure\Licence\Licence::class)) {
            require_once __DIR__ . '/Stubs/AmeliaLicenceStubs.php';
        }

        \AmeliaBooking\Infrastructure\Licence\Licence::reset('Lite', false);
    }

    protected function tearDown(): void
    {
        \AmeliaBooking\Infrastructure\Licence\Licence::reset('Lite', false);
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testCollectorIdentity(): void
    {
        $collector = new AmeliaCollector();

        $this->assertSame('ameliabooking', $collector->getPluginSlug());
        $this->assertSame('amelia_usage_tracking_consent', $collector->getConsentOptionName());
    }

    public function testGetConsentNoticePresentation(): void
    {
        $this->registerAmeliaBackendStringsStub();

        $presentation = (new AmeliaCollector())->getConsentNoticePresentation();

        $this->assertSame('#4a3bd6', $presentation['accentColor']);
        $this->assertSame(
            'https://example.com/wp-content/plugins/ameliabooking/public/img/amelia-logo-admin-icon.svg',
            $presentation['iconUrl']
        );
        $this->assertSame('Amelia', $presentation['iconAlt']);
        $this->assertSame('Improve Amelia', $presentation['title']);
        $this->assertSame(
            'Help us improve Amelia by sharing anonymous data about your plugin usage.',
            $presentation['description']
        );
        $this->assertSame('Improve plugin', $presentation['enableLabel']);
        $this->assertSame('Learn more', $presentation['learnMoreLabel']);
        $this->assertSame('https://wpamelia.com/usage-data-privacy/', $presentation['learnMoreUrl']);
        $this->assertSame('Dismiss this notice.', $presentation['dismissLabel']);
        $this->assertSame('amelia-redesign-page', $presentation['spaPageId']);
        $this->assertSame('#amelia-redesign', $presentation['spaAppRootSelector']);
    }

    public function testCronHookName(): void
    {
        $collector = new AmeliaCollector();

        $this->assertSame('melograno_usage_tracker_ameliabooking_send', $collector->getCronHookName());
        $this->assertSame(
            $collector->getCronHookName(),
            BaseCollector::cronHookNameForSlug($collector->getPluginSlug())
        );
    }

    public function testCronSchedule(): void
    {
        $this->assertSame('weekly', (new AmeliaCollector())->getCronSchedule());
    }

    public function testShouldShowAdminNoticeForAllLicences(): void
    {
        \AmeliaBooking\Infrastructure\Licence\Licence::reset('Lite', false);
        $this->assertTrue((new AmeliaCollector())->shouldShowAdminNotice());

        \AmeliaBooking\Infrastructure\Licence\Licence::reset('Pro', true);
        $this->assertTrue((new AmeliaCollector())->shouldShowAdminNotice());
    }

    public function testIsOnWelcomePage(): void
    {
        Functions\when('wp_unslash')->returnArg();
        Functions\when('sanitize_text_field')->returnArg();

        $previousPage = $_GET['page'] ?? null;

        try {
            $_GET['page'] = 'wpamelia-welcome';
            $this->assertTrue($this->invokeIsOnWelcomePage(new AmeliaCollector()));

            $_GET['page'] = 'wpamelia-dashboard';
            $this->assertFalse($this->invokeIsOnWelcomePage(new AmeliaCollector()));

            unset($_GET['page']);
            $this->assertFalse($this->invokeIsOnWelcomePage(new AmeliaCollector()));
        } finally {
            if ($previousPage === null) {
                unset($_GET['page']);
            } else {
                $_GET['page'] = $previousPage;
            }
        }
    }

    public function testShouldMigrateConsentOnUpgradeOnlyForLite(): void
    {
        \AmeliaBooking\Infrastructure\Licence\Licence::reset('Lite', false);
        $this->assertTrue((new AmeliaCollector())->shouldMigrateConsentOnUpgrade());

        \AmeliaBooking\Infrastructure\Licence\Licence::reset('Pro', true);
        $this->assertFalse((new AmeliaCollector())->shouldMigrateConsentOnUpgrade());
    }

    public function testShouldEnableConsentByDefaultForPremiumOnly(): void
    {
        \AmeliaBooking\Infrastructure\Licence\Licence::reset('Lite', false);
        $this->assertFalse((new AmeliaCollector())->shouldEnableConsentByDefault());

        \AmeliaBooking\Infrastructure\Licence\Licence::reset('Pro', true);
        $this->assertTrue((new AmeliaCollector())->shouldEnableConsentByDefault());
    }

    /**
     * @dataProvider licenseTierProvider
     */
    public function testNormalizeLicenseTier(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, AmeliaCollector::normalizeLicenseTier($input));
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public function licenseTierProvider(): array
    {
        return [
            'lite' => ['Lite', 'lite'],
            'starter' => ['Starter', 'starter'],
            'basic' => ['Basic', 'standard'],
            'pro' => ['Pro', 'pro'],
            'developer' => ['Developer', 'elite'],
            'empty string' => ['', 'elite'],
            'whitespace' => ['  ', 'elite'],
            'null' => [null, null],
            'unknown' => ['Enterprise', null],
        ];
    }

    public function testPluginPayloadIncludesLicenseFromGetLicence(): void
    {
        $collector = new class () extends AmeliaCollector {
            protected function resolveAmeliaLicence(): ?string
            {
                return 'Pro';
            }

            protected function resolveBookingMetrics(): array
            {
                return [
                    'appointment_bookings_count' => 0,
                    'appointments_count' => 0,
                    'event_bookings_count' => 0,
                    'approved_appointment_bookings_count' => 0,
                    'approved_appointments_count' => 0,
                ];
            }
        };

        $payload = $this->invokePluginPayload($collector);

        $this->assertSame('pro', $payload['license'] ?? null);
        $this->assertArrayNotHasKey('first_booking_created_at', $payload);
    }

    public function testPluginPayloadOmitsLicenseWhenLicenceUnavailable(): void
    {
        $collector = new class () extends AmeliaCollector {
            protected function resolveAmeliaLicence(): ?string
            {
                return null;
            }

            protected function resolveBookingMetrics(): array
            {
                return [
                    'appointment_bookings_count' => 0,
                    'appointments_count' => 0,
                    'event_bookings_count' => 0,
                    'approved_appointment_bookings_count' => 0,
                    'approved_appointments_count' => 0,
                ];
            }
        };

        $payload = $this->invokePluginPayload($collector);

        $this->assertArrayNotHasKey('license', $payload);
    }

    public function testPluginPayloadIncludesFirstBookingCreatedAt(): void
    {
        $collector = new class () extends AmeliaCollector {
            protected function resolveAmeliaLicence(): ?string
            {
                return null;
            }

            protected function resolveBookingMetrics(): array
            {
                return [
                    'first_booking_created_at' => '2024-03-15T10:00:00+00:00',
                    'appointment_bookings_count' => 0,
                    'appointments_count' => 0,
                    'event_bookings_count' => 0,
                    'approved_appointment_bookings_count' => 0,
                    'approved_appointments_count' => 0,
                ];
            }
        };

        $payload = $this->invokePluginPayload($collector);

        $this->assertSame('2024-03-15T10:00:00+00:00', $payload['first_booking_created_at'] ?? null);
    }

    public function testPluginPayloadIncludesFeaturesWhenCollectionSucceeds(): void
    {
        $collector = new class () extends AmeliaCollector {
            protected function resolveAmeliaLicence(): ?string
            {
                return null;
            }

            protected function resolveBookingMetrics(): array
            {
                return [
                    'appointment_bookings_count' => 0,
                    'appointments_count' => 0,
                    'event_bookings_count' => 0,
                    'approved_appointment_bookings_count' => 0,
                    'approved_appointments_count' => 0,
                ];
            }

            protected function resolveFeatures(): ?array
            {
                return [
                    'features' => ['stripe' => true],
                    'feature_metrics' => [],
                ];
            }
        };

        $payload = $this->invokePluginPayload($collector);

        $this->assertSame(['stripe' => true], $payload['features'] ?? null);
    }

    public function testPluginPayloadIncludesEmptyFeaturesWhenNothingEnabled(): void
    {
        $collector = new class () extends AmeliaCollector {
            protected function resolveAmeliaLicence(): ?string
            {
                return null;
            }

            protected function resolveBookingMetrics(): array
            {
                return [
                    'appointment_bookings_count' => 0,
                    'appointments_count' => 0,
                    'event_bookings_count' => 0,
                    'approved_appointment_bookings_count' => 0,
                    'approved_appointments_count' => 0,
                ];
            }

            protected function resolveFeatures(): ?array
            {
                return [
                    'features' => [],
                    'feature_metrics' => [],
                ];
            }
        };

        $payload = $this->invokePluginPayload($collector);

        $this->assertArrayHasKey('features', $payload);
        $this->assertSame([], $payload['features']);
    }

    public function testPluginPayloadOmitsFeaturesWhenCollectionFails(): void
    {
        $collector = new class () extends AmeliaCollector {
            protected function resolveAmeliaLicence(): ?string
            {
                return null;
            }

            protected function resolveBookingMetrics(): array
            {
                return [
                    'appointment_bookings_count' => 0,
                    'appointments_count' => 0,
                    'event_bookings_count' => 0,
                    'approved_appointment_bookings_count' => 0,
                    'approved_appointments_count' => 0,
                ];
            }

            protected function resolveFeatures(): ?array
            {
                return null;
            }
        };

        $payload = $this->invokePluginPayload($collector);

        $this->assertArrayNotHasKey('features', $payload);
    }

    private function registerAmeliaBackendStringsStub(): void
    {
        if (!class_exists(\AmeliaBooking\Infrastructure\WP\Translations\BackendStrings::class)) {
            require_once __DIR__ . '/fixtures/amelia_backend_strings_stub.php';
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function invokePluginPayload(AmeliaCollector $collector): array
    {
        $reflection = new ReflectionClass($collector);
        $method = $reflection->getMethod('pluginPayload');
        $method->setAccessible(true);

        return $method->invoke($collector);
    }

    private function invokeIsOnWelcomePage(AmeliaCollector $collector): bool
    {
        $reflection = new ReflectionClass($collector);
        $method = $reflection->getMethod('isOnWelcomePage');
        $method->setAccessible(true);

        return $method->invoke($collector);
    }
}
