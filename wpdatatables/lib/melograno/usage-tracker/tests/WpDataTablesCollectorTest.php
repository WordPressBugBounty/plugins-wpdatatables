<?php

declare(strict_types=1);

namespace Melograno\UsageTracker\Tests;

use Brain\Monkey;
use Melograno\UsageTracker\Collectors\BaseCollector;
use Melograno\UsageTracker\Collectors\ConsentNoticeCollectorInterface;
use Melograno\UsageTracker\Collectors\Plugin\WpDataTablesCollector;
use Melograno\UsageTracker\Core\UsageTracker;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class WpDataTablesCollectorTest extends TestCase
{
    private const CONSENT_OPTION = 'wpdatatables_usage_tracking_consent';

    private const NOTICE_OPTION = 'wpdatatables_show_usage_tracking_notice';

    private bool $wpdbMocked = false;

    private bool $wpdbExistedBeforeMock = false;

    /** @var mixed */
    private $wpdbBeforeMock;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('WDT_CURRENT_VERSION')) {
            define('WDT_CURRENT_VERSION', '7.4-test');
        }

        if (!defined('WDT_ROOT_URL')) {
            define('WDT_ROOT_URL', 'https://example.com/wp-content/plugins/wpdatatables/');
        }

        if (!defined('WDT_STARTER_INTEGRATIONS_PATH')) {
            define('WDT_STARTER_INTEGRATIONS_PATH', '/tmp/wpdt/starter/');
        }
    }

    protected function tearDown(): void
    {
        $this->restoreGlobalWwpdb();
        delete_option(self::CONSENT_OPTION);
        delete_option(self::NOTICE_OPTION);
        unset($GLOBALS['wdtmcp_test_license_active']);
        unset($GLOBALS['melograno_usage_tracker_options']['wdtActivated']);
        $this->resetUsageTrackerStaticState();
        Monkey\tearDown();
        parent::tearDown();
    }

    private function resetUsageTrackerStaticState(): void
    {
        $ref = new ReflectionClass(UsageTracker::class);

        foreach (['bootstrapped', 'instances'] as $property) {
            $prop = $ref->getProperty($property);
            $prop->setAccessible(true);
            $prop->setValue([]);
        }
    }

    public function testCollectorIdentity(): void
    {
        $collector = new WpDataTablesCollector();

        $this->assertInstanceOf(ConsentNoticeCollectorInterface::class, $collector);
        $this->assertSame('wpdatatables', $collector->getPluginSlug());
        $this->assertSame('wpdatatables_usage_tracking_consent', $collector->getConsentOptionName());
        $this->assertSame('wpdatatables_show_usage_tracking_notice', $collector->getNoticeOptionName());
        $this->assertSame('wpdt', $collector->getConsentNoticeAjaxPrefix());
        $this->assertNull($collector->getOptInMigrationVersion());
        $this->assertFalse($collector->shouldMigrateConsentOnUpgrade());
    }

    public function testGetConsentNoticePresentation(): void
    {
        $presentation = (new WpDataTablesCollector())->getConsentNoticePresentation();

        $this->assertSame('#0088cc', $presentation['accentColor']);
        $this->assertSame(
            'https://example.com/wp-content/plugins/wpdatatables/assets/img/logo-large.png',
            $presentation['iconUrl']
        );
        $this->assertSame('wpDataTables', $presentation['iconAlt']);
        $this->assertSame('Improve wpDataTables', $presentation['title']);
        $this->assertStringContainsString('anonymous data', $presentation['description']);
        $this->assertSame('Improve plugin', $presentation['enableLabel']);
        $this->assertSame('Learn more', $presentation['learnMoreLabel']);
        $this->assertSame('https://wpdatatables.com/usage-data-privacy/', $presentation['learnMoreUrl']);
    }

    public function testShouldEnableConsentByDefaultForLite(): void
    {
        $collector = new class () extends WpDataTablesCollector {
            protected function isPaid(): bool
            {
                return false;
            }
        };

        $this->assertFalse($collector->shouldEnableConsentByDefault());
        $this->assertTrue($collector->shouldShowAdminNotice());
    }

    public function testShouldEnableConsentByDefaultForPaid(): void
    {
        $collector = new class () extends WpDataTablesCollector {
            protected function isPaid(): bool
            {
                return true;
            }
        };

        $this->assertTrue($collector->shouldEnableConsentByDefault());
    }

    public function testInitArmsNoticeForLiteInstall(): void
    {
        $collector = new class () extends WpDataTablesCollector {
            protected function isPaid(): bool
            {
                return false;
            }
        };

        UsageTracker::init($collector, 'wpdatatables/wpdatatables.php');

        $this->assertSame('yes', get_option(self::NOTICE_OPTION));
        $this->assertSame(0, (int) get_option(self::CONSENT_OPTION));
    }

    public function testInitDoesNotArmNoticeForPaidInstall(): void
    {
        $collector = new class () extends WpDataTablesCollector {
            protected function isPaid(): bool
            {
                return true;
            }

            protected function pluginPayload(): array
            {
                return [
                    'plugin_version' => WDT_CURRENT_VERSION,
                    'license' => 'pro',
                ];
            }
        };

        UsageTracker::init($collector, 'wpdatatables/wpdatatables.php');

        $this->assertFalse(metadata_exists('option', self::NOTICE_OPTION));
        $this->assertSame(1, (int) get_option(self::CONSENT_OPTION));
    }

    public function testDisablingConsentArmsNoticeWhenExplicitlyRequested(): void
    {
        $collector = new class () extends WpDataTablesCollector {
            protected function isPaid(): bool
            {
                return true;
            }
        };

        UsageTracker::init($collector, 'wpdatatables/wpdatatables.php');
        UsageTracker::setConsent(false, $collector, true);

        $this->assertFalse(UsageTracker::isConsentEnabled($collector));
        $this->assertSame('yes', get_option(self::NOTICE_OPTION));
    }

    public function testDisablingConsentDismissesNoticeByDefault(): void
    {
        $collector = new class () extends WpDataTablesCollector {
            protected function isPaid(): bool
            {
                return true;
            }
        };

        UsageTracker::init($collector, 'wpdatatables/wpdatatables.php');
        UsageTracker::setConsent(false, $collector, true);
        $this->assertSame('yes', get_option(self::NOTICE_OPTION));

        UsageTracker::setConsent(true, $collector);
        UsageTracker::setConsent(false, $collector);

        $this->assertFalse(UsageTracker::isConsentEnabled($collector));
        $this->assertNotSame('yes', get_option(self::NOTICE_OPTION));
    }
    public function testShouldEnableConsentByDefaultForPremiumPackageEvenWhenUnactivated(): void
    {
        $GLOBALS['melograno_usage_tracker_options']['wdtActivated'] = 0;

        $this->assertTrue((new WpDataTablesCollector())->shouldEnableConsentByDefault());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testShouldEnableConsentByDefaultFalseForLitePluginInstall(): void
    {
        define('WDT_INITIAL_LITE_VERSION', '3.4.2.16');

        $this->assertFalse((new WpDataTablesCollector())->shouldEnableConsentByDefault());
    }

    public function testCronSchedule(): void
    {
        $this->assertSame('weekly', (new WpDataTablesCollector())->getCronSchedule());
    }

    public function testCronHookName(): void
    {
        $collector = new WpDataTablesCollector();

        $this->assertSame('melograno_usage_tracker_wpdatatables_send', $collector->getCronHookName());
        $this->assertSame(
            $collector->getCronHookName(),
            BaseCollector::cronHookNameForSlug($collector->getPluginSlug())
        );
    }

    /**
     * @dataProvider licenseTierProvider
     */
    public function testNormalizeLicenseTier(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, WpDataTablesCollector::normalizeLicenseTier($input));
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public function licenseTierProvider(): array
    {
        return [
            'lite' => ['Lite', 'lite'],
            'starter' => ['Starter', 'starter'],
            'standard' => ['Standard', 'standard'],
            'pro' => ['Pro', 'pro'],
            'developer' => ['Developer', 'developer'],
            'null' => [null, null],
            'unknown' => ['Enterprise', null],
        ];
    }

    public function testPluginPayloadOmitsLicenseWhenNotActivated(): void
    {
        $collector = new class () extends WpDataTablesCollector {
            protected function resolveLicenseTier(): ?string
            {
                return null;
            }
        };

        $payload = $this->invokePluginPayload($collector);

        $this->assertArrayNotHasKey('license', $payload);
        $this->assertSame('7.4-test', $payload['plugin_version'] ?? null);
    }

    public function testPluginPayloadIncludesStandardLicenseWhenActivatedWithStandardFiles(): void
    {
        $collector = new class () extends WpDataTablesCollector {
            protected function resolveLicenseTier(): ?string
            {
                return 'standard';
            }
        };

        $payload = $this->invokePluginPayload($collector);

        $this->assertSame('standard', $payload['license'] ?? null);
    }

    public function testResolveLicenseTierUsesFileDetectionEvenWhenUnactivated(): void
    {
        $this->registerWdtmcpTierStubs();

        $GLOBALS['melograno_usage_tracker_options']['wdtActivated'] = 0;

        $tier = $this->invokeResolveLicenseTier(new WpDataTablesCollector());

        $this->assertSame('pro', $tier);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testResolveLicenseTierReturnsLiteForLitePluginInstall(): void
    {
        define('WDT_INITIAL_LITE_VERSION', '3.4.2.16');

        $tier = $this->invokeResolveLicenseTier(new WpDataTablesCollector());

        $this->assertSame('lite', $tier);
    }

    public function testResolveLicenseTierReturnsNullWhenWdtmcpHelpersUnavailable(): void
    {
        if (function_exists('wdtmcp_detect_tier_from_features')) {
            $this->markTestSkipped('wdtmcp stubs already loaded in this process.');
        }

        $tier = $this->invokeResolveLicenseTier(new WpDataTablesCollector());

        $this->assertNull($tier);
    }

    public function testResolveLicenseTierUsesWdtmcpFunctions(): void
    {
        $this->registerWdtmcpTierStubs();

        $tier = $this->invokeResolveLicenseTier(new WpDataTablesCollector());

        $this->assertSame('pro', $tier);
    }

    public function testPluginPayloadIncludesContentCounts(): void
    {
        $collector = new class () extends WpDataTablesCollector {
            protected function resolveLicenseTier(): ?string
            {
                return null;
            }

            protected function resolveContentCount(string $contentType): ?int
            {
                switch ($contentType) {
                    case 'table':
                        return 3;
                    case 'chart':
                        return 1;
                    default:
                        return 0;
                }
            }

            protected function resolveTableTypeCounts(): ?array
            {
                return null;
            }

            protected function resolveChartTypeCounts(): ?array
            {
                return null;
            }

            protected function resolveFirstContentCreatedAt(): ?string
            {
                return null;
            }
        };

        $payload = $this->invokePluginPayload($collector);

        $this->assertSame(3, $payload['tables_count']);
        $this->assertSame(1, $payload['charts_count']);
        $this->assertArrayNotHasKey('first_content_created_at', $payload);
        $this->assertArrayNotHasKey('table_types', $payload);
        $this->assertArrayNotHasKey('chart_types', $payload);
    }

    public function testPluginPayloadIncludesZeroCounts(): void
    {
        $collector = new class () extends WpDataTablesCollector {
            protected function resolveLicenseTier(): ?string
            {
                return null;
            }

            protected function resolveContentCount(string $contentType): ?int
            {
                return 0;
            }

            protected function resolveTableTypeCounts(): ?array
            {
                return [];
            }

            protected function resolveChartTypeCounts(): ?array
            {
                return [];
            }

            protected function resolveFirstContentCreatedAt(): ?string
            {
                return null;
            }
        };

        $payload = $this->invokePluginPayload($collector);

        $this->assertSame(0, $payload['tables_count']);
        $this->assertSame(0, $payload['charts_count']);
        $this->assertArrayNotHasKey('table_types', $payload);
        $this->assertArrayNotHasKey('chart_types', $payload);
    }

    public function testPluginPayloadIncludesTableAndChartTypeCounts(): void
    {
        $collector = new class () extends WpDataTablesCollector {
            protected function resolveLicenseTier(): ?string
            {
                return null;
            }

            protected function resolveContentCount(string $contentType): ?int
            {
                return $contentType === 'table' ? 5 : 3;
            }

            protected function resolveTableTypeCounts(): ?array
            {
                return ['simple' => 3, 'mysql' => 2];
            }

            protected function resolveChartTypeCounts(): ?array
            {
                return ['google_pie_chart' => 2, 'chartjs_line_chart' => 1];
            }

            protected function resolveFirstContentCreatedAt(): ?string
            {
                return null;
            }
        };

        $payload = $this->invokePluginPayload($collector);

        $this->assertSame(['simple' => 3, 'mysql' => 2], $payload['table_types']);
        $this->assertSame(
            ['google_pie_chart' => 2, 'chartjs_line_chart' => 1],
            $payload['chart_types']
        );
    }

    public function testCountGroupedByColumnReturnsTypeMap(): void
    {
        $this->mockGlobalWwpdb(new class () {
            public string $prefix = 'wp_';

            public function get_results(string $query, $output = OBJECT)
            {
                return [
                    ['type_key' => 'simple', 'cnt' => '3'],
                    ['type_key' => 'mysql', 'cnt' => '2'],
                    ['type_key' => '', 'cnt' => '1'],
                ];
            }
        });

        $result = $this->invokeCountGroupedByColumn(new WpDataTablesCollector(), 'wpdatatables', 'table_type');

        $this->assertSame(['simple' => 3, 'mysql' => 2], $result);
    }

    public function testCountGroupedByColumnReturnsNullWhenQueryFails(): void
    {
        $this->mockGlobalWwpdb(new class () {
            public string $prefix = 'wp_';

            public string $last_error = 'Table does not exist';

            public function get_results(string $query, $output = OBJECT)
            {
                return null;
            }
        });

        $result = $this->invokeCountGroupedByColumn(new WpDataTablesCollector(), 'wpdatacharts', 'type');

        $this->assertNull($result);
    }

    public function testCountGroupedByColumnRejectsInvalidTableName(): void
    {
        $wpdb = $this->mockGlobalWwpdb(new class () {
            public string $prefix = 'wp_';

            public bool $queried = false;

            public function get_results(string $query, $output = OBJECT)
            {
                $this->queried = true;

                return [];
            }
        });

        $result = $this->invokeCountGroupedByColumn(
            new WpDataTablesCollector(),
            'evil; DROP TABLE users--',
            'table_type'
        );

        $this->assertNull($result);
        $this->assertFalse($wpdb->queried);
    }

    public function testCountPluginRowsRejectsInvalidTableName(): void
    {
        $wpdb = $this->mockGlobalWwpdb(new class () {
            public string $prefix = 'wp_';

            public bool $queried = false;

            public function get_var(string $query): string
            {
                $this->queried = true;

                return '5';
            }
        });

        $result = $this->invokeCountPluginRows(new WpDataTablesCollector(), 'evil; DROP TABLE users--');

        $this->assertNull($result);
        $this->assertFalse($wpdb->queried);
    }

    public function testCountPluginRowsQueriesAllowedTable(): void
    {
        $this->mockGlobalWwpdb(new class () {
            public string $prefix = 'wp_';

            public function get_var(string $query): string
            {
                return '7';
            }
        });

        $result = $this->invokeCountPluginRows(new WpDataTablesCollector(), 'wpdatatables');

        $this->assertSame(7, $result);
    }

    public function testCountPluginRowsReturnsNullWhenQueryFails(): void
    {
        $this->mockGlobalWwpdb(new class () {
            public string $prefix = 'wp_';

            public string $last_error = 'Table does not exist';

            public function get_var(string $query): ?string
            {
                return null;
            }
        });

        $result = $this->invokeCountPluginRows(new WpDataTablesCollector(), 'wpdatatables');

        $this->assertNull($result);
    }

    public function testResolveContentCountReturnsNullWhenQueryFails(): void
    {
        $this->mockGlobalWwpdb(new class () {
            public string $prefix = 'wp_';

            public string $last_error = 'Table does not exist';

            public function get_var(string $query): ?string
            {
                return null;
            }
        });

        $collector = new WpDataTablesCollector();

        $this->assertNull($this->invokeResolveContentCount($collector, 'table'));
        $this->assertNull($this->invokeResolveContentCount($collector, 'chart'));
    }

    public function testPluginPayloadOmitsContentCountsWhenQueryFails(): void
    {
        $this->mockGlobalWwpdb(new class () {
            public string $prefix = 'wp_';

            public string $last_error = 'Table does not exist';

            public function get_var(string $query): ?string
            {
                return null;
            }

            public function get_results(string $query, $output = OBJECT)
            {
                return null;
            }
        });

        $collector = new class () extends WpDataTablesCollector {
            protected function resolveLicenseTier(): ?string
            {
                return null;
            }
        };

        $payload = $this->invokePluginPayload($collector);

        $this->assertArrayNotHasKey('tables_count', $payload);
        $this->assertArrayNotHasKey('charts_count', $payload);
        $this->assertArrayNotHasKey('table_types', $payload);
        $this->assertArrayNotHasKey('chart_types', $payload);
    }

    public function testPluginPayloadIncludesFirstContentCreatedAtWhenAvailable(): void
    {
        $collector = new class () extends WpDataTablesCollector {
            protected function resolveLicenseTier(): ?string
            {
                return null;
            }

            protected function resolveContentCount(string $contentType): ?int
            {
                switch ($contentType) {
                    case 'table':
                        return 5;
                    case 'chart':
                        return 2;
                    default:
                        return 0;
                }
            }

            protected function resolveTableTypeCounts(): ?array
            {
                return null;
            }

            protected function resolveChartTypeCounts(): ?array
            {
                return null;
            }

            protected function resolveFirstContentCreatedAt(): ?string
            {
                return '2024-02-10T12:00:00+00:00';
            }
        };

        $payload = $this->invokePluginPayload($collector);

        $this->assertSame('2024-02-10T12:00:00+00:00', $payload['first_content_created_at']);
    }

    private function mockGlobalWwpdb(object $mock): object
    {
        global $wpdb;

        if (!$this->wpdbMocked) {
            $this->wpdbExistedBeforeMock = array_key_exists('wpdb', $GLOBALS);
            $this->wpdbBeforeMock = $this->wpdbExistedBeforeMock ? $wpdb : null;
            $this->wpdbMocked = true;
        }

        $wpdb = $mock;

        return $mock;
    }

    private function restoreGlobalWwpdb(): void
    {
        if (!$this->wpdbMocked) {
            return;
        }

        if ($this->wpdbExistedBeforeMock) {
            $GLOBALS['wpdb'] = $this->wpdbBeforeMock;
        } else {
            unset($GLOBALS['wpdb']);
        }

        $this->wpdbMocked = false;
    }

    private function registerWdtmcpTierStubs(): void
    {
        require_once __DIR__ . '/fixtures/wdtmcp_tier_stubs.php';
    }

    private function invokeCountPluginRows(WpDataTablesCollector $collector, string $table): ?int
    {
        $reflection = new ReflectionClass($collector);
        $method = $reflection->getMethod('countPluginRows');
        $method->setAccessible(true);

        return $method->invoke($collector, $table);
    }

    /**
     * @return array<string, int>|null
     */
    private function invokeCountGroupedByColumn(
        WpDataTablesCollector $collector,
        string $table,
        string $column
    ): ?array {
        $reflection = new ReflectionClass($collector);
        $method = $reflection->getMethod('countGroupedByColumn');
        $method->setAccessible(true);

        return $method->invoke($collector, $table, $column);
    }

    private function invokeResolveContentCount(WpDataTablesCollector $collector, string $contentType): ?int
    {
        $reflection = new ReflectionClass($collector);
        $method = $reflection->getMethod('resolveContentCount');
        $method->setAccessible(true);

        return $method->invoke($collector, $contentType);
    }

    private function invokeResolveLicenseTier(WpDataTablesCollector $collector): ?string
    {
        $reflection = new ReflectionClass($collector);
        $method = $reflection->getMethod('resolveLicenseTier');
        $method->setAccessible(true);

        return $method->invoke($collector);
    }

    /**
     * @return array<string, mixed>
     */
    private function invokePluginPayload(WpDataTablesCollector $collector): array
    {
        $reflection = new ReflectionClass($collector);
        $method = $reflection->getMethod('pluginPayload');
        $method->setAccessible(true);

        return $method->invoke($collector);
    }
}
