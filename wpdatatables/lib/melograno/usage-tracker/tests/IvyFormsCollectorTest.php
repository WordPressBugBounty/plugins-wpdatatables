<?php

declare(strict_types=1);

namespace Melograno\UsageTracker\Tests;

use Brain\Monkey;
use Melograno\UsageTracker\Collectors\BaseCollector;
use Melograno\UsageTracker\Collectors\ConsentNoticeCollectorInterface;
use Melograno\UsageTracker\Collectors\Plugin\IvyFormsCollector;
use Melograno\UsageTracker\Core\UsageTracker;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class IvyFormsCollectorTest extends TestCase
{
    private const CONSENT_OPTION = 'ivyforms_usage_tracking_consent';

    private const NOTICE_OPTION = 'ivyforms_show_usage_tracking_notice';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('IVYFORMS_VERSION')) {
            define('IVYFORMS_VERSION', '1.0.0-test');
        }

        if (!defined('IVYFORMS_URL')) {
            define('IVYFORMS_URL', 'https://example.com/wp-content/plugins/ivyforms/');
        }
    }

    protected function tearDown(): void
    {
        delete_option(self::CONSENT_OPTION);
        delete_option(self::NOTICE_OPTION);
        $this->resetUsageTrackerStaticState();
        unset($GLOBALS['melograno_usage_tracker_options']['ivyforms_settings']);
        Monkey\tearDown();
        parent::tearDown();
    }

    private function resetUsageTrackerStaticState(): void
    {
        $ref = new ReflectionClass(UsageTracker::class);

        foreach (['bootstrapped', 'instances', 'collectorsByPluginFile'] as $property) {
            $prop = $ref->getProperty($property);
            $prop->setAccessible(true);
            $prop->setValue([]);
        }
    }

    public function testCollectorIdentity(): void
    {
        $collector = new IvyFormsCollector();

        $this->assertInstanceOf(ConsentNoticeCollectorInterface::class, $collector);
        $this->assertSame('ivyforms', $collector->getPluginSlug());
        $this->assertSame('ivyforms_usage_tracking_consent', $collector->getConsentOptionName());
        $this->assertSame('ivyforms_show_usage_tracking_notice', $collector->getNoticeOptionName());
        $this->assertSame('ivyforms', $collector->getConsentNoticeAjaxPrefix());
        $this->assertNull($collector->getOptInMigrationVersion());
        $this->assertFalse($collector->shouldMigrateConsentOnUpgrade());
    }

    public function testGetConsentNoticePresentation(): void
    {
        $presentation = (new IvyFormsCollector())->getConsentNoticePresentation();

        $this->assertSame('#1BBEA0', $presentation['accentColor']);
        $this->assertSame(
            'https://example.com/wp-content/plugins/ivyforms/frontend/src/assets/images/logos/logo-only-admin.svg',
            $presentation['iconUrl']
        );
        $this->assertSame('IvyForms', $presentation['iconAlt']);
        $this->assertSame('Improve IvyForms', $presentation['title']);
        $this->assertStringContainsString('anonymous data', $presentation['description']);
        $this->assertSame('Improve plugin', $presentation['enableLabel']);
        $this->assertSame('Learn more', $presentation['learnMoreLabel']);
        $this->assertSame('https://ivyforms.com/usage-data-privacy/', $presentation['learnMoreUrl']);
        $this->assertSame('ivyforms-app', $presentation['spaPageId']);
        $this->assertSame('#ivyforms-app', $presentation['spaAppRootSelector']);
    }

    public function testShouldEnableConsentByDefaultForLite(): void
    {
        $collector = new class () extends IvyFormsCollector {
            protected function resolveLicence(): ?string
            {
                return 'lite';
            }
        };

        $this->assertFalse($collector->shouldEnableConsentByDefault());
        $this->assertTrue($collector->shouldShowAdminNotice());
    }

    public function testConsentDisabledByDefaultForPaidPlanShowsNotice(): void
    {
        $collector = new class () extends IvyFormsCollector {
            protected function resolveLicence(): ?string
            {
                return 'growth';
            }
        };

        $this->assertFalse($collector->shouldEnableConsentByDefault());
        $this->assertTrue($collector->shouldShowAdminNotice());
    }

    public function testInitArmsNoticeForLiteInstall(): void
    {
        $collector = new class () extends IvyFormsCollector {
            protected function resolveLicence(): ?string
            {
                return 'lite';
            }
        };

        UsageTracker::init($collector, 'ivyforms/ivyforms.php');

        $this->assertSame('yes', get_option(self::NOTICE_OPTION));
        $this->assertSame(0, (int) get_option(self::CONSENT_OPTION));
    }

    public function testInitArmsNoticeForPaidInstall(): void
    {
        $collector = new class () extends IvyFormsCollector {
            protected function resolveLicence(): ?string
            {
                return 'agency';
            }

            protected function pluginPayload(): array
            {
                return [
                    'plugin_version' => IVYFORMS_VERSION,
                    'license' => 'agency',
                ];
            }
        };

        UsageTracker::init($collector, 'ivyforms/ivyforms.php');

        $this->assertSame('yes', get_option(self::NOTICE_OPTION));
        $this->assertSame(0, (int) get_option(self::CONSENT_OPTION));
    }

    public function testDismissedNoticeStaysDismissedAfterReInit(): void
    {
        $collector = new class () extends IvyFormsCollector {
            protected function resolveLicence(): ?string
            {
                return 'lite';
            }
        };

        UsageTracker::init($collector, 'ivyforms/ivyforms.php');
        update_option(self::NOTICE_OPTION, 'no', true);

        $this->resetUsageTrackerStaticState();
        UsageTracker::init($collector, 'ivyforms/ivyforms.php');

        $this->assertSame('no', get_option(self::NOTICE_OPTION));
    }

    public function testCronHookName(): void
    {
        $collector = new IvyFormsCollector();

        $this->assertSame('melograno_usage_tracker_ivyforms_send', $collector->getCronHookName());
        $this->assertSame(
            $collector->getCronHookName(),
            BaseCollector::cronHookNameForSlug($collector->getPluginSlug())
        );
    }

    public function testCronSchedule(): void
    {
        $this->assertSame('weekly', (new IvyFormsCollector())->getCronSchedule());
    }

    /**
     * @dataProvider licenseTierProvider
     */
    public function testNormalizeLicenseTier(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, IvyFormsCollector::normalizeLicenseTier($input));
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public function licenseTierProvider(): array
    {
        return [
            'lite' => ['Lite', 'lite'],
            'essentials' => ['Essentials', 'essentials'],
            'growth' => ['Growth', 'growth'],
            'agency' => ['Agency', 'agency'],
            'empty string' => ['', null],
            'whitespace' => ['  ', null],
            'null' => [null, null],
            'unknown' => ['Enterprise', null],
        ];
    }

    public function testPluginPayloadIncludesLicenseFromSettings(): void
    {
        $collector = new class () extends IvyFormsCollector {
            protected function resolveLicence(): ?string
            {
                return 'Growth';
            }

            protected function resolveRecordCounts(): array
            {
                return [];
            }
        };

        $payload = $this->invokePluginPayload($collector);

        $this->assertSame('growth', $payload['license'] ?? null);
    }

    public function testPluginPayloadOmitsLicenseWhenLicenceUnavailable(): void
    {
        $collector = new class () extends IvyFormsCollector {
            protected function resolveLicence(): ?string
            {
                return null;
            }

            protected function resolveRecordCounts(): array
            {
                return [];
            }
        };

        $payload = $this->invokePluginPayload($collector);

        $this->assertArrayNotHasKey('license', $payload);
    }

    public function testResolveLicenceReturnsLiteWhenProIsNotInstalled(): void
    {
        unset($GLOBALS['melograno_usage_tracker_options']['ivyforms_settings']);

        $collector = new IvyFormsCollector();
        $plan = $this->invokeResolveLicence($collector);

        $this->assertSame('lite', $plan);
    }

    public function testResolveLicenceReturnsNullWhenProInstalledWithoutPlan(): void
    {
        $GLOBALS['melograno_usage_tracker_options']['ivyforms_settings'] = json_encode([
            'general' => ['wcagBackend' => false],
        ]);

        $collector = $this->createProCollector();

        $this->assertNull($this->invokeResolveLicence($collector));
    }

    public function testResolveLicenceReturnsNullWhenProInstalledWithEmptySettings(): void
    {
        $GLOBALS['melograno_usage_tracker_options']['ivyforms_settings'] = '';

        $collector = $this->createProCollector();

        $this->assertNull($this->invokeResolveLicence($collector));
    }

    public function testResolveLicenceReturnsNullWhenProInstalledWithMalformedSettings(): void
    {
        $GLOBALS['melograno_usage_tracker_options']['ivyforms_settings'] = 'not-json';

        $collector = $this->createProCollector();

        $this->assertNull($this->invokeResolveLicence($collector));
    }

    public function testPluginPayloadOmitsLicenseWhenProInstalledWithoutReadablePlan(): void
    {
        $GLOBALS['melograno_usage_tracker_options']['ivyforms_settings'] = json_encode([
            'general' => ['wcagBackend' => false],
        ]);

        $collector = new class () extends IvyFormsCollector {
            protected function isProPluginActive(): bool
            {
                return true;
            }

            protected function resolveRecordCounts(): array
            {
                return [];
            }
        };

        $payload = $this->invokePluginPayload($collector);

        $this->assertArrayNotHasKey('license', $payload);
    }

    public function testResolveLicenceReturnsPaidPlanFromProSettings(): void
    {
        $GLOBALS['melograno_usage_tracker_options']['ivyforms_settings'] = json_encode([
            'pro' => [
                'license' => [
                    'status' => 'valid',
                    'plan' => 'growth',
                ],
            ],
        ]);

        $collector = $this->createProCollector();

        $this->assertSame('growth', $this->invokeResolveLicence($collector));
    }

    public function testPluginPayloadIncludesLiteForFreeInstall(): void
    {
        $collector = new class () extends IvyFormsCollector {
            protected function resolveLicence(): ?string
            {
                return 'lite';
            }

            protected function resolveRecordCounts(): array
            {
                return [];
            }
        };

        $payload = $this->invokePluginPayload($collector);

        $this->assertSame('lite', $payload['license'] ?? null);
    }

    public function testPluginPayloadIncludesRecordCounts(): void
    {
        $collector = new class () extends IvyFormsCollector {
            protected function resolveLicence(): ?string
            {
                return 'lite';
            }

            protected function resolveRecordCounts(): array
            {
                return [
                    'forms_count' => 5,
                    'active_forms_count' => 3,
                    'inactive_forms_count' => 2,
                    'submissions_count' => 42,
                    'first_form_created_at' => '2024-03-15T10:00:00+00:00',
                ];
            }
        };

        $payload = $this->invokePluginPayload($collector);

        $this->assertSame(5, $payload['forms_count'] ?? null);
        $this->assertSame(3, $payload['active_forms_count'] ?? null);
        $this->assertSame(2, $payload['inactive_forms_count'] ?? null);
        $this->assertSame(42, $payload['submissions_count'] ?? null);
        $this->assertSame('2024-03-15T10:00:00+00:00', $payload['first_form_created_at'] ?? null);
    }

    public function testPluginPayloadOmitsFirstFormCreatedAtWhenUnavailable(): void
    {
        $collector = new class () extends IvyFormsCollector {
            protected function resolveRecordCounts(): array
            {
                return [
                    'active_forms_count' => 1,
                    'inactive_forms_count' => 0,
                    'submissions_count' => 0,
                ];
            }
        };

        $payload = $this->invokePluginPayload($collector);

        $this->assertArrayNotHasKey('first_form_created_at', $payload);
    }

    public function testPluginPayloadOmitsRecordCountsWhenTablesUnavailable(): void
    {
        $collector = new class () extends IvyFormsCollector {
            protected function resolveRecordCounts(): array
            {
                return [];
            }
        };

        $payload = $this->invokePluginPayload($collector);

        $this->assertArrayNotHasKey('forms_count', $payload);
        $this->assertArrayNotHasKey('active_forms_count', $payload);
        $this->assertArrayNotHasKey('inactive_forms_count', $payload);
        $this->assertArrayNotHasKey('submissions_count', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function invokePluginPayload(IvyFormsCollector $collector): array
    {
        $reflection = new ReflectionClass($collector);
        $method = $reflection->getMethod('pluginPayload');
        $method->setAccessible(true);

        return $method->invoke($collector);
    }

    private function invokeResolveLicence(IvyFormsCollector $collector): ?string
    {
        $reflection = new ReflectionClass($collector);
        $method = $reflection->getMethod('resolveLicence');
        $method->setAccessible(true);

        return $method->invoke($collector);
    }

    private function createProCollector(): IvyFormsCollector
    {
        return new class () extends IvyFormsCollector {
            protected function isProPluginActive(): bool
            {
                return true;
            }
        };
    }

    public function testGetResourcesUrlUsesScopeVendorFallback(): void
    {
        $url = $this->invokeGetResourcesUrl(new IvyFormsCollector());

        $this->assertStringContainsString(
            'backend/scope-vendor/melograno/usage-tracker/resources',
            $url
        );
        $this->assertStringNotContainsString('backend/vendor/', $url);
    }

    public function testGetResourcesUrlResolvesFromPluginPath(): void
    {
        if (!defined('IVYFORMS_PATH')) {
            define('IVYFORMS_PATH', dirname(__DIR__));
        }

        if (!defined('IVYFORMS_FILE')) {
            define('IVYFORMS_FILE', IVYFORMS_PATH . '/ivyforms.php');
        }

        $url = $this->invokeGetResourcesUrl(new IvyFormsCollector());

        $this->assertSame(
            'https://example.com/wp-content/plugins/ivyforms/resources',
            $url
        );
    }

    private function invokeGetResourcesUrl(IvyFormsCollector $collector): string
    {
        $reflection = new ReflectionClass($collector);
        $method = $reflection->getMethod('getResourcesUrl');
        $method->setAccessible(true);

        return $method->invoke($collector);
    }
}
