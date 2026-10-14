<?php

declare(strict_types=1);

namespace Melograno\UsageTracker\Tests;

use Brain\Monkey;
use Melograno\UsageTracker\Collectors\Plugin\WpDataTablesFeatureTelemetry;
use PHPUnit\Framework\TestCase;

class WpDataTablesFeatureTelemetryTest extends TestCase
{
    private bool $wpdbMocked = false;

    private bool $wpdbExistedBeforeMock = false;

    /** @var mixed */
    private $wpdbBeforeMock;

    /** @var array<string, array{exists: bool, value: mixed}> */
    private array $savedOptionStates = [];

    /** @var list<string> */
    private const DEFAULT_ON_OPTIONS = [
        'wdtGlobalTableLoader',
        'wdtGlobalChartLoader',
        'wdtIncludeBootstrap',
        'wdtIncludeBootstrapBackEnd',
        'wdtGoogleStableVersion',
        'wdtApexStableVersion',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->saveOptionStates();
        $this->disableDefaultOnSiteSettings();
    }

    protected function tearDown(): void
    {
        $this->restoreGlobalWwpdb();
        $this->restoreOptionStates();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testCollectAggregatesSkinsAndFeatures(): void
    {
        $this->mockGlobalWwpdb($this->createWpdbMock([
            'tables' => [
                [
                    'sorting' => '1',
                    'filtering' => '1',
                    'server_side' => '0',
                    'advanced_settings' => json_encode([
                        'tableSkin' => 'material',
                        'fixed_header' => 0,
                        'fixed_columns' => 1,
                    ]),
                ],
                [
                    'sorting' => '1',
                    'filtering' => '0',
                    'server_side' => '0',
                    'advanced_settings' => json_encode([
                        'tableSkin' => 'light',
                        'fixed_header' => 1,
                        'fixed_columns' => 0,
                    ]),
                ],
                [
                    'sorting' => '0',
                    'filtering' => '1',
                    'server_side' => '0',
                    'advanced_settings' => json_encode([
                        'tableSkin' => 'material',
                        'fixed_header' => false,
                        'fixed_columns' => true,
                    ]),
                ],
            ],
        ]));

        $result = WpDataTablesFeatureTelemetry::collect();

        $this->assertSame(['material' => 2, 'light' => 1], $result['skins']);
        $this->assertSame(
            [
                'sorting' => true,
                'filtering' => true,
                'fixedHeader' => true,
                'fixedColumns' => true,
                'nonServerSideTables' => true,
            ],
            $result['features']
        );
        $this->assertSame(
            [
                'sorting' => ['usage_count' => 2],
                'filtering' => ['usage_count' => 2],
                'fixedHeader' => ['usage_count' => 1],
                'fixedColumns' => ['usage_count' => 2],
                'wcag' => ['usage_count' => 0],
                'indexColumn' => ['usage_count' => 0],
                'nonServerSideTables' => ['usage_count' => 3],
            ],
            $result['feature_metrics']
        );
    }

    public function testCollectOmitsFeaturesWithZeroUsage(): void
    {
        $this->mockGlobalWwpdb($this->createWpdbMock([
            'tables' => [
                [
                    'sorting' => '1',
                    'filtering' => '0',
                    'server_side' => '0',
                    'advanced_settings' => json_encode([
                        'tableSkin' => 'aqua',
                        'fixed_header' => 0,
                        'fixed_columns' => 0,
                    ]),
                ],
            ],
        ]));

        $result = WpDataTablesFeatureTelemetry::collect();

        $this->assertSame(
            [
                'sorting' => true,
                'nonServerSideTables' => true,
            ],
            $result['features']
        );
        $this->assertSame(0, $result['feature_metrics']['filtering']['usage_count']);
        $this->assertSame(0, $result['feature_metrics']['fixedHeader']['usage_count']);
        $this->assertSame(0, $result['feature_metrics']['fixedColumns']['usage_count']);
        $this->assertSame(0, $result['feature_metrics']['wcag']['usage_count']);
        $this->assertSame(0, $result['feature_metrics']['indexColumn']['usage_count']);
        $this->assertArrayNotHasKey('filtering', $result['features']);
        $this->assertArrayNotHasKey('fixedHeader', $result['features']);
        $this->assertArrayNotHasKey('fixedColumns', $result['features']);
        $this->assertArrayNotHasKey('wcag', $result['features']);
        $this->assertArrayNotHasKey('indexColumn', $result['features']);
    }

    public function testCollectFallsBackToBaseSkinWhenMissingOrInvalid(): void
    {
        update_option('wdtBaseSkin', 'graphite');

        $this->mockGlobalWwpdb($this->createWpdbMock([
            'tables' => [
                [
                    'sorting' => '0',
                    'filtering' => '0',
                    'server_side' => '0',
                    'advanced_settings' => json_encode(['tableSkin' => 'not-a-skin']),
                ],
                [
                    'sorting' => '0',
                    'filtering' => '0',
                    'server_side' => '0',
                    'advanced_settings' => '{}',
                ],
                [
                    'sorting' => '0',
                    'filtering' => '0',
                    'server_side' => '0',
                    'advanced_settings' => 'not-json',
                ],
            ],
        ]));

        $result = WpDataTablesFeatureTelemetry::collect();

        $this->assertSame(['graphite' => 3], $result['skins']);
        $this->assertSame(['nonServerSideTables' => true], $result['features']);
    }

    public function testCollectReturnsEmptyWhenNoTables(): void
    {
        $this->mockGlobalWwpdb($this->createWpdbMock([
            'tables' => [],
        ]));

        $result = WpDataTablesFeatureTelemetry::collect();

        $this->assertSame([], $result['skins']);
        $this->assertSame([], $result['file_source_actions']);
        $this->assertSame([], $result['features']);
        $this->assertSame(
            [
                'sorting' => ['usage_count' => 0],
                'filtering' => ['usage_count' => 0],
                'fixedHeader' => ['usage_count' => 0],
                'fixedColumns' => ['usage_count' => 0],
                'wcag' => ['usage_count' => 0],
                'indexColumn' => ['usage_count' => 0],
            ],
            $result['feature_metrics']
        );
    }

    public function testCollectReportsFileSourceActionsFromHostOption(): void
    {
        update_option('wpdatatables_usage_tracking_consent', 1);
        update_option(
            WpDataTablesFeatureTelemetry::FILE_SOURCE_ACTIONS_OPTION,
            [
                'addDataToTable' => 2,
                'replaceTableData' => 1,
                'notAllowed' => 9,
            ],
            false
        );

        $this->mockGlobalWwpdb($this->createWpdbMock([
            'tables' => [],
        ]));

        $result = WpDataTablesFeatureTelemetry::collect();

        $this->assertSame(
            [
                'replaceTableData' => 1,
                'addDataToTable' => 2,
            ],
            $result['file_source_actions']
        );
        $this->assertTrue($result['features']['manualFileSourceImport']);
        $this->assertArrayNotHasKey('manualFileSourceImport', $result['feature_metrics']);
    }

    public function testCollectReportsFileSourceActionsFromJsonEncodedOption(): void
    {
        update_option(
            WpDataTablesFeatureTelemetry::FILE_SOURCE_ACTIONS_OPTION,
            json_encode([
                'replaceTable' => 3,
                'addDataToTable' => 1,
            ]),
            false
        );

        $this->mockGlobalWwpdb($this->createWpdbMock([
            'tables' => [],
        ]));

        $result = WpDataTablesFeatureTelemetry::collect();

        $this->assertSame(
            [
                'addDataToTable' => 1,
                'replaceTable' => 3,
            ],
            $result['file_source_actions']
        );
        $this->assertTrue($result['features']['manualFileSourceImport']);
    }

    public function testCollectOmitsFileSourceActionsWhenOptionEmpty(): void
    {
        update_option(
            WpDataTablesFeatureTelemetry::FILE_SOURCE_ACTIONS_OPTION,
            [],
            false
        );

        $this->mockGlobalWwpdb($this->createWpdbMock([
            'tables' => [],
        ]));

        $result = WpDataTablesFeatureTelemetry::collect();

        $this->assertSame([], $result['file_source_actions']);
        $this->assertArrayNotHasKey('manualFileSourceImport', $result['features']);
    }

    public function testDeleteStoredCountersClearsFileSourceActionsOption(): void
    {
        update_option(
            WpDataTablesFeatureTelemetry::FILE_SOURCE_ACTIONS_OPTION,
            ['replaceTable' => 4],
            false
        );

        WpDataTablesFeatureTelemetry::deleteStoredCounters();

        $this->assertFalse(
            get_option(WpDataTablesFeatureTelemetry::FILE_SOURCE_ACTIONS_OPTION, false)
        );
    }

    public function testCollectReturnsNullWhenQueryFails(): void
    {
        $this->mockGlobalWwpdb(new class () {
            public string $prefix = 'wp_';

            public string $last_error = 'Table does not exist';

            public function get_results(string $query, $output = null)
            {
                return null;
            }
        });

        $this->assertNull(WpDataTablesFeatureTelemetry::collect());
    }

    public function testCollectReportsSiteSettingsAsFeaturesOnly(): void
    {
        update_option('wdtGlobalTableLoader', 1);
        update_option('wdtGlobalChartLoader', 1);
        update_option('wdtIncludeBootstrap', 1);
        update_option('wdtIncludeBootstrapBackEnd', 0);
        update_option('wdtGoogleStableVersion', 1);
        update_option('wdtApexStableVersion', 0);
        update_option('wdtGoogleSettings', [
            'client_email' => 'service@example.com',
            'private_key' => 'key',
        ]);

        $this->mockGlobalWwpdb($this->createWpdbMock([
            'tables' => [],
        ]));

        $result = WpDataTablesFeatureTelemetry::collect();

        $this->assertTrue($result['features']['globalTableLoader']);
        $this->assertTrue($result['features']['globalChartLoader']);
        $this->assertTrue($result['features']['bootstrapFrontend']);
        $this->assertTrue($result['features']['stableGoogleCharts']);
        $this->assertTrue($result['features']['googleSheetsApi']);
        $this->assertArrayNotHasKey('bootstrapBackend', $result['features']);
        $this->assertArrayNotHasKey('stableApexCharts', $result['features']);

        foreach ([
            'globalTableLoader',
            'globalChartLoader',
            'bootstrapFrontend',
            'bootstrapBackend',
            'googleSheetsApi',
            'stableGoogleCharts',
            'stableApexCharts',
            'tableManager',
        ] as $code) {
            $this->assertArrayNotHasKey($code, $result['feature_metrics']);
        }
    }

    public function testCollectReportsSeparateDbConnectionWithCount(): void
    {
        update_option('wdtUseSeparateCon', 1);
        update_option('wdtSeparateCon', json_encode([
            ['id' => 'abc', 'name' => 'MYSQL'],
            ['id' => 'def', 'name' => 'PG'],
        ]));

        $this->mockGlobalWwpdb($this->createWpdbMock([
            'tables' => [],
        ]));

        $result = WpDataTablesFeatureTelemetry::collect();

        $this->assertTrue($result['features']['separateDbConnection']);
        $this->assertSame(2, $result['feature_metrics']['separateDbConnection']['usage_count']);
    }

    public function testCollectOmitsSeparateDbConnectionWhenDisabled(): void
    {
        update_option('wdtUseSeparateCon', 0);
        update_option('wdtSeparateCon', json_encode([
            ['id' => 'abc', 'name' => 'MYSQL'],
        ]));

        $this->mockGlobalWwpdb($this->createWpdbMock([
            'tables' => [],
        ]));

        $result = WpDataTablesFeatureTelemetry::collect();

        $this->assertArrayNotHasKey('separateDbConnection', $result['features']);
        $this->assertArrayNotHasKey('separateDbConnection', $result['feature_metrics']);
    }

    public function testCollectReportsColumnAggregates(): void
    {
        $this->mockGlobalWwpdb($this->createWpdbMock([
            'tables' => [],
            'columnAggregates' => [
                'hidden_tables' => '3',
                'formula_tables' => '2',
                'conditional_formatting_tables' => '1',
            ],
        ]));

        $result = WpDataTablesFeatureTelemetry::collect();

        $this->assertTrue($result['features']['hiddenColumns']);
        $this->assertTrue($result['features']['formulaColumns']);
        $this->assertTrue($result['features']['conditionalFormatting']);
        $this->assertSame(3, $result['feature_metrics']['hiddenColumns']['usage_count']);
        $this->assertSame(2, $result['feature_metrics']['formulaColumns']['usage_count']);
        $this->assertSame(1, $result['feature_metrics']['conditionalFormatting']['usage_count']);
    }

    public function testCollectReportsServerSideSplit(): void
    {
        $this->mockGlobalWwpdb($this->createWpdbMock([
            'tables' => [
                [
                    'sorting' => '0',
                    'filtering' => '0',
                    'server_side' => '1',
                    'advanced_settings' => '{}',
                ],
                [
                    'sorting' => '0',
                    'filtering' => '0',
                    'server_side' => '1',
                    'advanced_settings' => '{}',
                ],
                [
                    'sorting' => '0',
                    'filtering' => '0',
                    'server_side' => '0',
                    'advanced_settings' => '{}',
                ],
            ],
        ]));

        $result = WpDataTablesFeatureTelemetry::collect();

        $this->assertTrue($result['features']['serverSideTables']);
        $this->assertTrue($result['features']['nonServerSideTables']);
        $this->assertSame(2, $result['feature_metrics']['serverSideTables']['usage_count']);
        $this->assertSame(1, $result['feature_metrics']['nonServerSideTables']['usage_count']);
    }

    public function testCollectReportsTableSizeBucketsFromSimpleTablesOnly(): void
    {
        $this->mockGlobalWwpdb($this->createWpdbMock([
            'tables' => [],
            'simpleSizes' => [
                ['table_id' => '1', 'row_count' => '5'],
                ['table_id' => '2', 'row_count' => '10'],
                ['table_id' => '3', 'row_count' => '25'],
            ],
        ]));

        $result = WpDataTablesFeatureTelemetry::collect();

        $this->assertTrue($result['features']['tablesUpTo10Rows']);
        $this->assertTrue($result['features']['tablesOver10Rows']);
        // simple: 5,10 → upTo10; 25 → over10
        $this->assertSame(2, $result['feature_metrics']['tablesUpTo10Rows']['usage_count']);
        $this->assertSame(1, $result['feature_metrics']['tablesOver10Rows']['usage_count']);
    }

    public function testCollectReportsTableManagerWhenAccessRulesNonEmpty(): void
    {
        update_option('wpdt_access_rules', [
            'version' => 1,
            'rules' => [
                [
                    'id' => 'rule-1',
                    'type' => 'role',
                    'role' => 'editor',
                    'resource' => 'tables',
                    'permissions' => ['list_tables'],
                    'item_ids' => null,
                ],
            ],
        ]);

        $this->mockGlobalWwpdb($this->createWpdbMock([
            'tables' => [],
        ]));

        $result = WpDataTablesFeatureTelemetry::collect();

        $this->assertTrue($result['features']['tableManager']);
        $this->assertArrayNotHasKey('tableManager', $result['feature_metrics']);
    }

    public function testCollectOmitsTableManagerWhenAccessRulesEmptyOrAbsent(): void
    {
        update_option('wpdt_access_rules', [
            'version' => 1,
            'rules' => [],
        ]);

        $this->mockGlobalWwpdb($this->createWpdbMock([
            'tables' => [],
        ]));

        $result = WpDataTablesFeatureTelemetry::collect();

        $this->assertArrayNotHasKey('tableManager', $result['features']);

        delete_option('wpdt_access_rules');

        $result = WpDataTablesFeatureTelemetry::collect();

        $this->assertArrayNotHasKey('tableManager', $result['features']);
    }

    public function testCollectReportsWcagAndIndexColumn(): void
    {
        $this->mockGlobalWwpdb($this->createWpdbMock([
            'tables' => [
                [
                    'sorting' => '0',
                    'filtering' => '0',
                    'server_side' => '0',
                    'advanced_settings' => json_encode([
                        'table_wcag' => 1,
                        'index_column' => 0,
                    ]),
                ],
                [
                    'sorting' => '0',
                    'filtering' => '0',
                    'server_side' => '0',
                    'advanced_settings' => json_encode([
                        'table_wcag' => true,
                        'index_column' => 1,
                    ]),
                ],
                [
                    'sorting' => '0',
                    'filtering' => '0',
                    'server_side' => '0',
                    'advanced_settings' => json_encode([
                        'table_wcag' => 0,
                        'index_column' => false,
                    ]),
                ],
            ],
        ]));

        $result = WpDataTablesFeatureTelemetry::collect();

        $this->assertTrue($result['features']['wcag']);
        $this->assertTrue($result['features']['indexColumn']);
        $this->assertSame(2, $result['feature_metrics']['wcag']['usage_count']);
        $this->assertSame(1, $result['feature_metrics']['indexColumn']['usage_count']);
    }

    public function testCollectReportsTransformValueForNonServerSideTablesOnly(): void
    {
        $this->mockGlobalWwpdb($this->createWpdbMock([
            'tables' => [
                [
                    'id' => '1',
                    'sorting' => '0',
                    'filtering' => '0',
                    'server_side' => '0',
                    'advanced_settings' => '{}',
                ],
                [
                    'id' => '2',
                    'sorting' => '0',
                    'filtering' => '0',
                    'server_side' => '0',
                    'advanced_settings' => '{}',
                ],
                [
                    'id' => '3',
                    'sorting' => '0',
                    'filtering' => '0',
                    'server_side' => '0',
                    'advanced_settings' => '{}',
                ],
                [
                    'id' => '4',
                    'sorting' => '0',
                    'filtering' => '0',
                    'server_side' => '1',
                    'advanced_settings' => '{}',
                ],
            ],
            'transformValueColumns' => [
                [
                    'table_id' => '1',
                    'advanced_settings' => json_encode(['transformValueText' => 'upper']),
                ],
                [
                    'table_id' => '1',
                    'advanced_settings' => json_encode(['transformValueText' => 'lower']),
                ],
                [
                    'table_id' => '2',
                    'advanced_settings' => json_encode(['transformValueText' => '']),
                ],
                [
                    'table_id' => '3',
                    'advanced_settings' => json_encode(['transformValueText' => 'trim']),
                ],
                // Server-side table — must be excluded by the query predicate.
                [
                    'table_id' => '4',
                    'advanced_settings' => json_encode(['transformValueText' => 'ss-transform']),
                ],
            ],
        ]));

        $result = WpDataTablesFeatureTelemetry::collect();

        $this->assertTrue($result['features']['transformValue']);
        $this->assertSame(2, $result['feature_metrics']['transformValue']['usage_count']);
    }

    public function testCollectOmitsTransformValueWhenUnused(): void
    {
        $this->mockGlobalWwpdb($this->createWpdbMock([
            'tables' => [],
            'transformValueColumns' => [
                [
                    'table_id' => '1',
                    'advanced_settings' => json_encode(['transformValueText' => '']),
                ],
                [
                    'table_id' => '2',
                    'advanced_settings' => '{}',
                ],
            ],
        ]));

        $result = WpDataTablesFeatureTelemetry::collect();

        $this->assertArrayNotHasKey('transformValue', $result['features']);
        $this->assertArrayNotHasKey('transformValue', $result['feature_metrics']);
    }

    public function testCollectReportsMasterDetailAndCascadeFilteringCounts(): void
    {
        $this->mockGlobalWwpdb($this->createWpdbMock([
            'tables' => [
                [
                    'sorting' => '0',
                    'filtering' => '0',
                    'server_side' => '0',
                    'advanced_settings' => json_encode([
                        'masterDetail' => 1,
                        'cascadeFiltering' => 0,
                    ]),
                ],
                [
                    'sorting' => '0',
                    'filtering' => '0',
                    'server_side' => '0',
                    'advanced_settings' => json_encode([
                        'masterDetail' => true,
                        'cascadeFiltering' => 1,
                    ]),
                ],
                [
                    'sorting' => '0',
                    'filtering' => '0',
                    'server_side' => '0',
                    'advanced_settings' => json_encode([
                        'masterDetail' => 0,
                        'cascadeFiltering' => false,
                    ]),
                ],
            ],
        ]));

        $result = WpDataTablesFeatureTelemetry::collect();

        $this->assertTrue($result['features']['masterDetail']);
        $this->assertTrue($result['features']['cascadeFiltering']);
        $this->assertSame(2, $result['feature_metrics']['masterDetail']['usage_count']);
        $this->assertSame(1, $result['feature_metrics']['cascadeFiltering']['usage_count']);
    }

    public function testCollectOmitsMasterDetailAndCascadeWhenUnused(): void
    {
        $this->mockGlobalWwpdb($this->createWpdbMock([
            'tables' => [
                [
                    'sorting' => '0',
                    'filtering' => '0',
                    'server_side' => '0',
                    'advanced_settings' => '{}',
                ],
            ],
        ]));

        $result = WpDataTablesFeatureTelemetry::collect();

        $this->assertArrayNotHasKey('masterDetail', $result['features']);
        $this->assertArrayNotHasKey('cascadeFiltering', $result['features']);
        $this->assertArrayNotHasKey('masterDetail', $result['feature_metrics']);
        $this->assertArrayNotHasKey('cascadeFiltering', $result['feature_metrics']);
    }

    public function testCollectOmitsReportBuilderFlagsWhenTableMissing(): void
    {
        $this->mockGlobalWwpdb($this->createWpdbMock([
            'tables' => [],
            'reportsTableExists' => false,
        ]));

        $result = WpDataTablesFeatureTelemetry::collect();

        $this->assertArrayNotHasKey('reportBuilderAddon', $result['features']);
        $this->assertArrayNotHasKey('reportBuilderStandalone', $result['features']);
        $this->assertArrayNotHasKey('reportBuilderAddon', $result['feature_metrics']);
        $this->assertArrayNotHasKey('reportBuilderStandalone', $result['feature_metrics']);
    }

    public function testCollectReportsReportBuilderAddonOnly(): void
    {
        $this->mockGlobalWwpdb($this->createWpdbMock([
            'tables' => [],
            'reportsTableExists' => true,
            'reportsAddonCount' => 3,
            'reportsStandaloneCount' => 0,
        ]));

        $result = WpDataTablesFeatureTelemetry::collect();

        $this->assertTrue($result['features']['reportBuilderAddon']);
        $this->assertArrayNotHasKey('reportBuilderStandalone', $result['features']);
        $this->assertSame(3, $result['feature_metrics']['reportBuilderAddon']['usage_count']);
        $this->assertArrayNotHasKey('reportBuilderStandalone', $result['feature_metrics']);
    }

    public function testCollectReportsReportBuilderStandaloneOnly(): void
    {
        $this->mockGlobalWwpdb($this->createWpdbMock([
            'tables' => [],
            'reportsTableExists' => true,
            'reportsAddonCount' => 0,
            'reportsStandaloneCount' => 2,
        ]));

        $result = WpDataTablesFeatureTelemetry::collect();

        $this->assertTrue($result['features']['reportBuilderStandalone']);
        $this->assertArrayNotHasKey('reportBuilderAddon', $result['features']);
        $this->assertSame(2, $result['feature_metrics']['reportBuilderStandalone']['usage_count']);
        $this->assertArrayNotHasKey('reportBuilderAddon', $result['feature_metrics']);
    }

    public function testCollectReportsMixedReportBuilderModes(): void
    {
        $this->mockGlobalWwpdb($this->createWpdbMock([
            'tables' => [],
            'reportsTableExists' => true,
            'reportsAddonCount' => 4,
            'reportsStandaloneCount' => 1,
            'reportTableIdColumn' => 'wpdatatable_id',
        ]));

        $result = WpDataTablesFeatureTelemetry::collect();

        $this->assertTrue($result['features']['reportBuilderAddon']);
        $this->assertTrue($result['features']['reportBuilderStandalone']);
        $this->assertSame(4, $result['feature_metrics']['reportBuilderAddon']['usage_count']);
        $this->assertSame(1, $result['feature_metrics']['reportBuilderStandalone']['usage_count']);
    }

    /**
     * @param array{
     *     tables?: list<array<string, mixed>>,
     *     columnAggregates?: array<string, mixed>|null,
     *     transformValueColumns?: list<array<string, mixed>>,
     *     simpleSizes?: list<array<string, mixed>>,
     *     reportsTableExists?: bool,
     *     reportsAddonCount?: int,
     *     reportsStandaloneCount?: int,
     *     reportTableIdColumn?: string
     * } $config
     */
    private function createWpdbMock(array $config): object
    {
        $tables = $config['tables'] ?? [];
        $columnAggregates = array_key_exists('columnAggregates', $config)
            ? $config['columnAggregates']
            : [
                'hidden_tables' => '0',
                'formula_tables' => '0',
                'conditional_formatting_tables' => '0',
            ];
        $transformValueColumns = $config['transformValueColumns'] ?? [];
        $simpleSizes = $config['simpleSizes'] ?? [];
        $reportsTableExists = $config['reportsTableExists'] ?? false;
        $reportsAddonCount = (int) ($config['reportsAddonCount'] ?? 0);
        $reportsStandaloneCount = (int) ($config['reportsStandaloneCount'] ?? 0);
        $reportTableIdColumn = $config['reportTableIdColumn'] ?? 'table_id';

        return new class (
            $tables,
            $columnAggregates,
            $transformValueColumns,
            $simpleSizes,
            $reportsTableExists,
            $reportsAddonCount,
            $reportsStandaloneCount,
            $reportTableIdColumn
        ) {
            public string $prefix = 'wp_';

            public string $last_error = '';

            /** @var list<array<string, mixed>> */
            private array $tables;

            /** @var array<string, mixed>|null */
            private $columnAggregates;

            /** @var list<array<string, mixed>> */
            private array $transformValueColumns;

            /** @var list<array<string, mixed>> */
            private array $simpleSizes;

            private bool $reportsTableExists;

            private int $reportsAddonCount;

            private int $reportsStandaloneCount;

            private string $reportTableIdColumn;

            /**
             * @param list<array<string, mixed>> $tables
             * @param array<string, mixed>|null $columnAggregates
             * @param list<array<string, mixed>> $transformValueColumns
             * @param list<array<string, mixed>> $simpleSizes
             */
            public function __construct(
                array $tables,
                $columnAggregates,
                array $transformValueColumns,
                array $simpleSizes,
                bool $reportsTableExists,
                int $reportsAddonCount,
                int $reportsStandaloneCount,
                string $reportTableIdColumn
            ) {
                $this->tables = $tables;
                $this->columnAggregates = $columnAggregates;
                $this->transformValueColumns = $transformValueColumns;
                $this->simpleSizes = $simpleSizes;
                $this->reportsTableExists = $reportsTableExists;
                $this->reportsAddonCount = $reportsAddonCount;
                $this->reportsStandaloneCount = $reportsStandaloneCount;
                $this->reportTableIdColumn = $reportTableIdColumn;
            }

            public function prepare(string $query, ...$args): string
            {
                $i = 0;

                return (string) preg_replace_callback(
                    '/%[sdf]/',
                    static function () use (&$i, $args) {
                        $value = $args[$i] ?? '';
                        $i++;

                        return is_string($value) ? $value : (string) $value;
                    },
                    $query
                );
            }

            public function get_var(string $query)
            {
                if (strpos($query, 'SHOW TABLES LIKE') !== false) {
                    return $this->reportsTableExists ? $this->prefix . 'wpdatareports' : null;
                }

                if (strpos($query, 'wpdatareports') !== false && strpos($query, 'COUNT(*)') !== false) {
                    if (strpos($query, 'IS NOT NULL') !== false) {
                        return (string) $this->reportsAddonCount;
                    }
                    if (strpos($query, 'IS NULL') !== false) {
                        return (string) $this->reportsStandaloneCount;
                    }
                }

                return null;
            }

            public function get_results(string $query, $output = null)
            {
                if (strpos($query, 'SHOW COLUMNS FROM') !== false) {
                    if (!$this->reportsTableExists) {
                        return [];
                    }

                    return [
                        ['Field' => 'id'],
                        ['Field' => 'name'],
                        ['Field' => $this->reportTableIdColumn],
                    ];
                }
                if (strpos($query, 'wpdatatables_columns') !== false
                    && strpos($query, 'INNER JOIN') !== false
                ) {
                    // Without the server_side predicate, SS transform columns leak into the result
                    // and the expected usage_count assertion fails.
                    if (strpos($query, 'server_side') === false) {
                        return $this->transformValueColumns;
                    }

                    $nonServerSideIds = [];
                    foreach ($this->tables as $table) {
                        $serverSide = $table['server_side'] ?? '0';
                        if ($serverSide === '0' || $serverSide === 0 || $serverSide === '' || $serverSide === null) {
                            $tableId = $table['id'] ?? null;
                            if ($tableId !== null && $tableId !== '') {
                                $nonServerSideIds[(string) $tableId] = true;
                            }
                        }
                    }

                    if ($nonServerSideIds === []) {
                        return $this->transformValueColumns;
                    }

                    return array_values(array_filter(
                        $this->transformValueColumns,
                        static function (array $row) use ($nonServerSideIds): bool {
                            $tableId = $row['table_id'] ?? null;

                            return $tableId !== null
                                && $tableId !== ''
                                && isset($nonServerSideIds[(string) $tableId]);
                        }
                    ));
                }
                if (strpos($query, 'wpdatatables_rows') !== false) {
                    return $this->simpleSizes;
                }
                if (preg_match('/FROM\s+' . preg_quote($this->prefix, '/') . 'wpdatatables(\s|$)/', $query) === 1
                    || preg_match('/FROM\s+wp_wpdatatables(\s|$)/', $query) === 1
                ) {
                    return $this->tables;
                }

                return [];
            }

            public function get_row(string $query, $output = null)
            {
                if (strpos($query, 'wpdatatables_columns') !== false) {
                    return $this->columnAggregates;
                }

                return null;
            }

            /**
             * @return list<string>
             */
            public function get_col(string $query)
            {
                return [];
            }
        };
    }

    private function disableDefaultOnSiteSettings(): void
    {
        foreach (self::DEFAULT_ON_OPTIONS as $option) {
            update_option($option, 0);
        }
        update_option('wdtUseSeparateCon', 0);
        update_option('wdtSeparateCon', '');
        update_option('wdtGoogleSettings', '');
        delete_option('wpdatatables_usage_tracking_consent');
        delete_option(WpDataTablesFeatureTelemetry::FILE_SOURCE_ACTIONS_OPTION);
        delete_option('wpdt_access_rules');
    }

    /**
     * @return list<string>
     */
    private function managedOptions(): array
    {
        return array_merge(self::DEFAULT_ON_OPTIONS, [
            'wdtBaseSkin',
            'wdtUseSeparateCon',
            'wdtSeparateCon',
            'wdtGoogleSettings',
            'wpdatatables_usage_tracking_consent',
            WpDataTablesFeatureTelemetry::FILE_SOURCE_ACTIONS_OPTION,
            'wpdt_access_rules',
        ]);
    }

    private function saveOptionStates(): void
    {
        foreach ($this->managedOptions() as $option) {
            $exists = metadata_exists('option', $option);
            $this->savedOptionStates[$option] = [
                'exists' => $exists,
                'value' => $exists ? get_option($option) : null,
            ];
        }
    }

    private function restoreOptionStates(): void
    {
        foreach ($this->savedOptionStates as $option => $state) {
            if ($state['exists']) {
                update_option($option, $state['value']);
            } else {
                delete_option($option);
            }
        }

        $this->savedOptionStates = [];
    }

    /**
     * @param object $wpdb
     */
    private function mockGlobalWwpdb($wpdb): void
    {
        $this->wpdbExistedBeforeMock = array_key_exists('wpdb', $GLOBALS);
        $this->wpdbBeforeMock = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = $wpdb;
        $this->wpdbMocked = true;
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
}
