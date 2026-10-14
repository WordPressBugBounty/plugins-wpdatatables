<?php

declare(strict_types=1);

namespace Melograno\UsageTracker\Tests;

use Brain\Monkey;
use Melograno\UsageTracker\Collectors\Plugin\IvyFormsFeatureTelemetry;
use PHPUnit\Framework\TestCase;

class IvyFormsFeatureTelemetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        unset($GLOBALS['melograno_usage_tracker_options']['ivyforms_settings']);
        unset($GLOBALS['melograno_usage_tracker_options']['ivyforms_access_rules']);
        unset($GLOBALS['wpdb']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['melograno_usage_tracker_options']['ivyforms_settings']);
        unset($GLOBALS['melograno_usage_tracker_options']['ivyforms_access_rules']);
        unset($GLOBALS['wpdb']);
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testCollectReturnsEmptyWhenNoSettingsOrDb(): void
    {
        $result = IvyFormsFeatureTelemetry::collect();

        $this->assertSame([], $result['features']);
        $this->assertSame([], $result['feature_metrics']);
        $this->assertSame([], $result['templates']);
        $this->assertSame([], $result['themes']);
        $this->assertSame([], $result['fields']);
    }

    public function testCollectReportsEnabledIntegrationNotInUseAsFalse(): void
    {
        $GLOBALS['melograno_usage_tracker_options']['ivyforms_settings'] = json_encode([
            'integrations' => [
                'mailchimp' => ['enabled' => true],
            ],
        ]);
        $this->mockWpdb([]);

        $result = IvyFormsFeatureTelemetry::collect();

        $this->assertSame(['mailchimp' => false], $result['features']);
        $this->assertSame([], $result['feature_metrics']);
    }

    public function testCollectOmitsIntegrationWhenTabDisabledEvenIfInUse(): void
    {
        $GLOBALS['melograno_usage_tracker_options']['ivyforms_settings'] = json_encode([
            'integrations' => [
                'mailchimp' => ['enabled' => false],
            ],
        ]);
        $this->mockWpdb([
            'get_col' => [
                [json_encode(['mailchimp' => ['listId' => 'abc123']])],
                [],
                [],
            ],
            'get_var' => array_fill(0, 10, 0),
            'get_results' => [[]],
        ]);

        $result = IvyFormsFeatureTelemetry::collect();

        $this->assertArrayNotHasKey('mailchimp', $result['features']);
    }

    public function testCollectReportsMailchimpInUseFromListId(): void
    {
        $GLOBALS['melograno_usage_tracker_options']['ivyforms_settings'] = json_encode([
            'integrations' => [
                'mailchimp' => ['enabled' => true],
            ],
        ]);
        $this->mockWpdb([
            'get_col' => [
                [json_encode(['mailchimp' => ['listId' => 'list-1', 'fieldMappings' => []]])],
                [],
                [],
            ],
            'get_var' => array_fill(0, 10, 0),
            'get_results' => [[]],
        ]);

        $result = IvyFormsFeatureTelemetry::collect();

        $this->assertTrue($result['features']['mailchimp']);
    }

    public function testCollectReportsWpDataTablesFromTableType(): void
    {
        $GLOBALS['melograno_usage_tracker_options']['ivyforms_settings'] = json_encode([
            'integrations' => [
                'wpdatatables' => ['enabled' => true],
            ],
        ]);
        $this->mockWpdb([
            'get_col' => [[], [], []],
            'get_var' => [
                0, // google sheets forms
                0, // webhooks forms
                2, // wpdatatables ivyforms tables
                0, // amelia bookings
                0, // amelia packages
                0, // zapier
                0, // conversational
                0, // smart logic notifications
                0, // smart logic confirmations
                0, // multiple confirmations
            ],
            'get_results' => [[]],
        ]);

        $result = IvyFormsFeatureTelemetry::collect();

        $this->assertTrue($result['features']['wpdatatables']);
    }

    public function testCollectReportsAmeliaFromIvyEntryId(): void
    {
        $GLOBALS['melograno_usage_tracker_options']['ivyforms_settings'] = json_encode([
            'integrations' => [
                'ameliabooking' => ['enabled' => true],
            ],
        ]);
        $this->mockWpdb([
            'get_col' => [[], [], []],
            'get_var' => [
                0, // google sheets
                0, // webhooks
                0, // wpdatatables
                1, // amelia customer_bookings
                0, // amelia packages
                0, // zapier
                0, 0, 0, 0,
            ],
            'get_results' => [[]],
        ]);

        $result = IvyFormsFeatureTelemetry::collect();

        $this->assertTrue($result['features']['ameliabooking']);
    }

    public function testCollectReportsPublicApiFromDeveloperKeys(): void
    {
        $GLOBALS['melograno_usage_tracker_options']['ivyforms_settings'] = json_encode([
            'integrations' => [
                'public-api' => ['enabled' => true],
            ],
            'developer' => [
                'public_api_keys' => [
                    ['id' => 'ifk-1', 'hash' => 'abc'],
                ],
            ],
        ]);
        $this->mockWpdb([
            'get_col' => [[], [], []],
            'get_var' => array_fill(0, 10, 0),
            'get_results' => [[]],
        ]);

        $result = IvyFormsFeatureTelemetry::collect();

        $this->assertTrue($result['features']['public_api']);
        $this->assertArrayNotHasKey('public-api', $result['features']);
    }

    public function testCollectReportsPublicApiFalseWhenNoKeys(): void
    {
        $GLOBALS['melograno_usage_tracker_options']['ivyforms_settings'] = json_encode([
            'integrations' => [
                'public-api' => ['enabled' => true],
            ],
            'developer' => [
                'public_api_keys' => [],
            ],
        ]);
        $this->mockWpdb([
            'get_col' => [[], [], []],
            'get_var' => array_fill(0, 10, 0),
            'get_results' => [[]],
        ]);

        $result = IvyFormsFeatureTelemetry::collect();

        $this->assertSame(['public_api' => false], $result['features']);
    }

    public function testCollectReportsWebhookUsageCount(): void
    {
        $GLOBALS['melograno_usage_tracker_options']['ivyforms_settings'] = json_encode([
            'integrations' => [
                'webhooks' => ['enabled' => true],
                'google_sheets' => ['enabled' => true],
            ],
        ]);
        $this->mockWpdb([
            'get_col' => [[], [], []],
            'get_var' => [
                1, // google sheets distinct forms
                3, // webhooks distinct forms
                0, // wpdatatables
                0, // amelia bookings
                0, // amelia packages
                0, // zapier
                0, 0, 0, 0,
            ],
            'get_results' => [[]],
        ]);

        $result = IvyFormsFeatureTelemetry::collect();

        $this->assertTrue($result['features']['webhooks']);
        $this->assertTrue($result['features']['google_sheets']);
        $this->assertSame(
            [
                'google_sheets' => ['usage_count' => 1],
                'webhooks' => ['usage_count' => 3],
            ],
            $result['feature_metrics']
        );
    }

    public function testCollectReportsSecurityAndMcpFeatures(): void
    {
        $GLOBALS['melograno_usage_tracker_options']['ivyforms_settings'] = json_encode([
            'security' => [
                'recaptcha' => ['siteKey' => 'site', 'secretKey' => 'secret'],
                'turnstile' => ['siteKey' => '', 'secretKey' => ''],
            ],
            'general' => [
                'mcp_enabled' => true,
                'wcagBackend' => true,
            ],
        ]);
        $this->mockWpdb([]);

        $result = IvyFormsFeatureTelemetry::collect();

        $this->assertTrue($result['features']['recaptcha']);
        $this->assertTrue($result['features']['mcp']);
        $this->assertTrue($result['features']['wcag_backend']);
        $this->assertArrayNotHasKey('turnstile', $result['features']);
    }

    public function testCollectOmitsWcagBackendWhenDisabled(): void
    {
        $GLOBALS['melograno_usage_tracker_options']['ivyforms_settings'] = json_encode([
            'general' => [
                'wcagBackend' => false,
            ],
        ]);
        $this->mockWpdb([]);

        $result = IvyFormsFeatureTelemetry::collect();

        $this->assertArrayNotHasKey('wcag_backend', $result['features']);
    }

    public function testCollectReportsConversationalAndFieldTypes(): void
    {
        $this->mockWpdb([
            'get_col' => [
                [], // integrationSettings
                [], // styleSettings
                [], // settings multipage
                ['signature', 'likert', 'text'], // field types
            ],
            'get_var' => [
                0, // google sheets
                0, // webhooks
                0, // wpdatatables
                0, // amelia bookings
                0, // amelia packages
                0, // zapier
                0, // multiple confirmations
                0, // multiple notifications
                2, // conversational
                0, // smart logic notifications
                0, // smart logic confirmations
            ],
            'get_results' => [[]],
        ]);

        $result = IvyFormsFeatureTelemetry::collect();

        $this->assertTrue($result['features']['conversational']);
        $this->assertTrue($result['fields']['signature']);
        $this->assertTrue($result['fields']['likert']);
        $this->assertTrue($result['fields']['text']);
        $this->assertArrayNotHasKey('signature', $result['features']);
        $this->assertArrayNotHasKey('likert', $result['features']);
    }

    public function testCollectFieldTypesDoNotCrossContaminate(): void
    {
        $this->mockWpdb([
            'get_col' => [
                [], // integrationSettings
                [], // styleSettings
                [], // settings multipage
                ['date', 'time', 'date_time', 'html', 'rich_text', 'multi-select', 'file-upload', 'unknown-type'],
            ],
            'get_var' => array_fill(0, 11, 0),
            'get_results' => [[]],
        ]);

        $result = IvyFormsFeatureTelemetry::collect();

        $this->assertSame(
            [
                'date' => true,
                'time' => true,
                'date_time' => true,
                'html' => true,
                'rich_text' => true,
                'multi_select' => true,
                'file_upload' => true,
            ],
            $result['fields']
        );
    }

    public function testCollectReportsMultipleConfirmationsAndNotificationsUsageCount(): void
    {
        $this->mockWpdb([
            'get_col' => [[], [], [], []],
            'get_var' => [
                0, // google sheets
                0, // webhooks
                0, // wpdatatables
                0, // amelia bookings
                0, // amelia packages
                0, // zapier
                2, // multiple confirmations (forms with 2+ confirmations)
                3, // multiple notifications (forms with 2+ notifications)
                0, // conversational
                0, // smart logic notifications
                0, // smart logic confirmations
            ],
            'get_results' => [[]],
        ]);

        $result = IvyFormsFeatureTelemetry::collect();

        $this->assertTrue($result['features']['confirmations']);
        $this->assertTrue($result['features']['notifications']);
        $this->assertSame(
            [
                'confirmations' => ['usage_count' => 2],
                'notifications' => ['usage_count' => 3],
            ],
            $result['feature_metrics']
        );
    }

    public function testCollectReportsSmartLogicFromEnabledNotification(): void
    {
        $getVar = array_fill(0, 13, 0);
        $getVar[9] = 1; // smart logic notifications match ("enabled":true)
        $this->mockWpdb([
            'get_col' => [[], [], []],
            'get_var' => $getVar,
            'get_results' => [[]],
        ]);

        $result = IvyFormsFeatureTelemetry::collect();

        $this->assertTrue($result['features']['smart_logic']);
    }

    public function testCollectReportsSmartLogicFromEnabledGoogleSheetsOrWebhooks(): void
    {
        $getVar = array_fill(0, 13, 0);
        $getVar[11] = 1; // smart logic google_sheets match ("enabled":true)
        $this->mockWpdb([
            'get_col' => [[], [], []],
            'get_var' => $getVar,
            'get_results' => [[]],
        ]);

        $result = IvyFormsFeatureTelemetry::collect();

        $this->assertTrue($result['features']['smart_logic']);
    }

    public function testCollectOmitsSmartLogicWhenOnlyDefaultDisabledStatePresent(): void
    {
        $this->mockWpdb([
            'get_col' => [[], [], []],
            'get_var' => array_fill(0, 13, 0),
            'get_results' => [[]],
        ]);

        $result = IvyFormsFeatureTelemetry::collect();

        $this->assertArrayNotHasKey('smart_logic', $result['features']);
    }

    public function testCollectSmartLogicSkipsMissingProTableAndKeepsProbing(): void
    {
        // notifications/confirmations report no smart logic, so the probe has to reach the
        // Pro tables: google_sheets is absent and webhooks carries the signal.
        $GLOBALS['wpdb'] = $this->mockProTableWpdb(1);

        $result = IvyFormsFeatureTelemetry::collect();

        $this->assertSame(
            [],
            $GLOBALS['wpdb']->missingTableQueries,
            'the absent google_sheets table must never be queried'
        );
        $this->assertSame(
            [
                'wp_ivyforms_notifications',
                'wp_ivyforms_confirmations',
                'wp_ivyforms_webhooks',
            ],
            $GLOBALS['wpdb']->smartLogicProbes,
            'a missing table must be skipped without aborting the probe loop'
        );
        $this->assertSame('', $GLOBALS['wpdb']->last_error);
        $this->assertTrue($result['features']['smart_logic']);
    }

    public function testCollectOmitsSmartLogicWhenProTablesMissingAndLiteTablesEmpty(): void
    {
        $GLOBALS['wpdb'] = $this->mockProTableWpdb(0);

        $result = IvyFormsFeatureTelemetry::collect();

        $this->assertSame(
            [],
            $GLOBALS['wpdb']->missingTableQueries,
            'the absent google_sheets table must never be queried'
        );
        $this->assertSame(
            [
                'wp_ivyforms_notifications',
                'wp_ivyforms_confirmations',
                'wp_ivyforms_webhooks',
            ],
            $GLOBALS['wpdb']->smartLogicProbes,
            'a missing table must be skipped without aborting the probe loop'
        );
        $this->assertSame('', $GLOBALS['wpdb']->last_error);
        $this->assertArrayNotHasKey('smart_logic', $result['features']);
    }

    /**
     * wpdb double for the smart logic probe: `wp_ivyforms_google_sheets` is absent, so its
     * `SHOW TABLES LIKE` probe finds nothing and any data query against it returns null
     * and sets last_error (wpdb never throws for a missing table). `wp_ivyforms_webhooks`
     * exists and returns $webhooksSmartLogic for its smart logic probe, and both Lite
     * tables report 0. Records which tables the probe touched so tests can assert the loop
     * got past the Lite tables and never queried the absent one.
     */
    private function mockProTableWpdb(int $webhooksSmartLogic): object
    {
        return new class ($webhooksSmartLogic) {
            public string $prefix = 'wp_';

            public string $last_error = '';

            /** @var list<string> tables the smart logic probe queried, in order */
            public array $smartLogicProbes = [];

            /** @var list<string> tables probed with SHOW TABLES LIKE, in order */
            public array $existenceProbes = [];

            /** @var list<string> data queries that hit the absent table */
            public array $missingTableQueries = [];

            private int $webhooksSmartLogic;

            public function __construct(int $webhooksSmartLogic)
            {
                $this->webhooksSmartLogic = $webhooksSmartLogic;
            }

            public function get_col($query = null)
            {
                return [];
            }

            public function get_var($query = null, $x = 0, $y = 0)
            {
                $query = is_string($query) ? $query : '';
                $this->last_error = '';

                if (preg_match("/SHOW TABLES LIKE '([^']+)'/", $query, $m) === 1) {
                    $this->existenceProbes[] = $m[1];

                    return $m[1] === 'wp_ivyforms_google_sheets' ? null : $m[1];
                }

                $table = $this->tableOf($query);
                $isSmartLogicProbe = strpos($query, 'smartLogic` LIKE') !== false
                    || strpos($query, 'smart_logic` LIKE') !== false;

                if ($isSmartLogicProbe) {
                    $this->smartLogicProbes[] = $table;
                }

                if ($table === 'wp_ivyforms_google_sheets') {
                    $this->missingTableQueries[] = $table;
                    $this->last_error = "Table 'wordpress.{$table}' doesn't exist";

                    return null;
                }

                if ($isSmartLogicProbe && $table === 'wp_ivyforms_webhooks') {
                    return $this->webhooksSmartLogic;
                }

                return 0;
            }

            public function get_results($query = null, $output = null)
            {
                return [];
            }

            private function tableOf(string $query): string
            {
                return preg_match('/(?:FROM|JOIN)\s+`([^`]+)`/', $query, $m) === 1 ? $m[1] : '';
            }
        };
    }

    public function testCollectReportsZeroConfirmationsAndNotificationsUsageCount(): void
    {
        $getVar = array_fill(0, 15, 0);
        $getVar[13] = 4; // forms with 0 confirmations (default deleted)
        $getVar[14] = 5; // forms with 0 notifications (default deleted)
        $this->mockWpdb([
            'get_col' => [[], [], []],
            'get_var' => $getVar,
            'get_results' => [[]],
        ]);

        $result = IvyFormsFeatureTelemetry::collect();

        $this->assertTrue($result['features']['zero_confirmations']);
        $this->assertTrue($result['features']['zero_notifications']);
        $this->assertSame(
            [
                'zero_confirmations' => ['usage_count' => 4],
                'zero_notifications' => ['usage_count' => 5],
            ],
            $result['feature_metrics']
        );
    }

    public function testCollectOmitsZeroRowMetricsWhenEveryFormHasDefaults(): void
    {
        $this->mockWpdb([
            'get_col' => [[], [], []],
            'get_var' => array_fill(0, 15, 0),
            'get_results' => [[]],
        ]);

        $result = IvyFormsFeatureTelemetry::collect();

        $this->assertArrayNotHasKey('zero_confirmations', $result['features']);
        $this->assertArrayNotHasKey('zero_notifications', $result['features']);
        $this->assertArrayNotHasKey('zero_confirmations', $result['feature_metrics']);
        $this->assertArrayNotHasKey('zero_notifications', $result['feature_metrics']);
    }

    public function testCollectReportsPermissionsWhenAccessRulesNonEmpty(): void
    {
        $GLOBALS['melograno_usage_tracker_options']['ivyforms_access_rules'] = [
            'version' => 1,
            'rules' => [
                ['role' => 'editor', 'capability' => 'manage_forms'],
            ],
        ];
        $this->mockWpdb([]);

        $result = IvyFormsFeatureTelemetry::collect();

        $this->assertTrue($result['features']['permissions']);
    }

    public function testCollectOmitsPermissionsWhenAccessRulesEmptyOrAbsent(): void
    {
        $GLOBALS['melograno_usage_tracker_options']['ivyforms_access_rules'] = [
            'version' => 1,
            'rules' => [],
        ];
        $this->mockWpdb([]);

        $result = IvyFormsFeatureTelemetry::collect();

        $this->assertArrayNotHasKey('permissions', $result['features']);

        unset($GLOBALS['melograno_usage_tracker_options']['ivyforms_access_rules']);
        $result = IvyFormsFeatureTelemetry::collect();

        $this->assertArrayNotHasKey('permissions', $result['features']);
    }

    public function testCollectReportsStylingCustomizationAndThemes(): void
    {
        $this->mockWpdb([
            'get_col' => [
                [], // integrationSettings
                [
                    json_encode(['selectedTheme' => 'ivy-default', 'styleMode' => 'quick', 'customCssClass' => '', 'additionalCssClasses' => '']),
                    json_encode(['selectedTheme' => 'midnight', 'styleMode' => 'quick', 'customCssClass' => '', 'additionalCssClasses' => '']),
                    json_encode(['selectedTheme' => 'midnight', 'styleMode' => 'advanced', 'customCssClass' => 'my-class', 'additionalCssClasses' => '']),
                ],
                [], // settings multipage
                [], // field types
            ],
            'get_var' => array_fill(0, 11, 0),
            'get_results' => [[]],
        ]);

        $result = IvyFormsFeatureTelemetry::collect();

        $this->assertTrue($result['features']['custom_styling']);
        $this->assertTrue($result['features']['custom_css']);
        $this->assertTrue($result['features']['advanced_style_mode']);
        $this->assertSame(['midnight' => 2], $result['themes']);
    }

    public function testCollectReportsNoStylingSignalsForDefaultStyleSettings(): void
    {
        $this->mockWpdb([
            'get_col' => [
                [],
                [json_encode(['selectedTheme' => 'ivy-default', 'styleMode' => 'quick', 'customCssClass' => '', 'additionalCssClasses' => ''])],
                [],
                [],
            ],
            'get_var' => array_fill(0, 11, 0),
            'get_results' => [[]],
        ]);

        $result = IvyFormsFeatureTelemetry::collect();

        $this->assertArrayNotHasKey('custom_styling', $result['features']);
        $this->assertArrayNotHasKey('custom_css', $result['features']);
        $this->assertArrayNotHasKey('advanced_style_mode', $result['features']);
        $this->assertSame([], $result['themes']);
    }

    public function testCollectReportsTemplates(): void
    {
        $this->mockWpdb([
            'get_col' => [[], [], []],
            'get_var' => array_fill(0, 10, 0),
            'get_results' => [[
                ['templateId' => 'contact_form', 'cnt' => '2'],
                ['templateId' => 'blank_form', 'cnt' => '4'],
            ]],
        ]);

        $result = IvyFormsFeatureTelemetry::collect();

        $this->assertSame([
            'contact_form' => 2,
            'blank_form' => 4,
        ], $result['templates']);
    }

    public function testCollectNeverQueriesAbsentProAndPartnerTablesOnLiteInstall(): void
    {
        $GLOBALS['melograno_usage_tracker_options']['ivyforms_settings'] = json_encode([
            'integrations' => [
                'google_sheets' => ['enabled' => true],
                'webhooks' => ['enabled' => true],
                'wpdatatables' => ['enabled' => true],
                'ameliabooking' => ['enabled' => true],
                'zapier' => ['enabled' => true],
            ],
        ]);
        $missing = [
            'wp_ivyforms_google_sheets',
            'wp_ivyforms_webhooks',
            'wp_ivyforms_zapier_subscribers',
            'wp_wpdatatables',
            'wp_amelia_customer_bookings',
            'wp_amelia_packages_to_customers',
        ];
        $this->mockWpdb([
            'get_col' => [[], [], []],
            'get_var' => array_fill(0, 10, 0),
            'get_results' => [[]],
        ], $missing);

        $result = IvyFormsFeatureTelemetry::collect();

        $this->assertSame(
            [],
            $GLOBALS['wpdb']->missingTableQueries,
            'absent Pro/partner tables must be gated out, not queried and caught'
        );
        $this->assertSame('', $GLOBALS['wpdb']->last_error, 'no probe may leave a DB error behind');
        $this->assertSame(
            [
                'wpdatatables' => false,
                'ameliabooking' => false,
                'google_sheets' => false,
                'webhooks' => false,
                'zapier' => false,
            ],
            $result['features']
        );
        $this->assertSame([], $result['feature_metrics']);
        $this->assertContains('wp_ivyforms_google_sheets', $GLOBALS['wpdb']->existenceProbes);
        $this->assertSame(
            1,
            count(array_keys($GLOBALS['wpdb']->existenceProbes, 'wp_ivyforms_google_sheets', true)),
            'table existence must be probed once per collection, not once per call site'
        );
    }

    public function testCollectLetsCollectionExceptionsPropagate(): void
    {
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';

            public function get_col($query = null)
            {
                throw new \RuntimeException('db unavailable');
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('db unavailable');

        IvyFormsFeatureTelemetry::collect();
    }

    public function testCollectNormalizesPublicApiKey(): void
    {
        $GLOBALS['melograno_usage_tracker_options']['ivyforms_settings'] = json_encode([
            'integrations' => [
                'public-api' => ['enabled' => true],
            ],
        ]);
        $this->mockWpdb([
            'get_col' => [[], [], []],
            'get_var' => array_fill(0, 10, 0),
            'get_results' => [[]],
        ]);

        $result = IvyFormsFeatureTelemetry::collect();

        $this->assertArrayHasKey('public_api', $result['features']);
        $this->assertArrayNotHasKey('public-api', $result['features']);
        $this->assertFalse($result['features']['public_api']);
    }

    /**
     * `SHOW TABLES LIKE` probes are answered from $missingTables and never consume the
     * get_var queue, so the positional queues above describe data queries only.
     *
     * Real wpdb never throws on a failed query, so a query against a missing table
     * returns null and sets last_error — exactly as wpdb::get_var() would.
     *
     * @param array{
     *     get_col?: list<mixed>,
     *     get_var?: list<mixed>,
     *     get_results?: list<mixed>
     * } $queues
     * @param list<string> $missingTables tables absent from this install
     */
    private function mockWpdb(array $queues, array $missingTables = []): void
    {
        $colQueue = $queues['get_col'] ?? [];
        $varQueue = $queues['get_var'] ?? [];
        $resultsQueue = $queues['get_results'] ?? [];

        $GLOBALS['wpdb'] = new class ($colQueue, $varQueue, $resultsQueue, $missingTables) {
            public string $prefix = 'wp_';

            public string $last_error = '';

            /** @var list<string> tables probed with SHOW TABLES LIKE, in order */
            public array $existenceProbes = [];

            /** @var list<string> data queries that hit a table this install lacks */
            public array $missingTableQueries = [];

            /** @var list<mixed> */
            private array $colQueue;

            /** @var list<mixed> */
            private array $varQueue;

            /** @var list<mixed> */
            private array $resultsQueue;

            /** @var list<string> */
            private array $missingTables;

            /**
             * @param list<mixed> $colQueue
             * @param list<mixed> $varQueue
             * @param list<mixed> $resultsQueue
             * @param list<string> $missingTables
             */
            public function __construct(
                array $colQueue,
                array $varQueue,
                array $resultsQueue,
                array $missingTables
            ) {
                $this->colQueue = $colQueue;
                $this->varQueue = $varQueue;
                $this->resultsQueue = $resultsQueue;
                $this->missingTables = $missingTables;
            }

            public function get_col($query = null)
            {
                if ($this->colQueue === []) {
                    return [];
                }

                return array_shift($this->colQueue);
            }

            public function get_var($query = null, $x = 0, $y = 0)
            {
                $query = is_string($query) ? $query : '';
                $this->last_error = '';

                $probed = $this->existenceProbeTarget($query);
                if ($probed !== null) {
                    $this->existenceProbes[] = $probed;

                    return in_array($probed, $this->missingTables, true) ? null : $probed;
                }

                $table = $this->tableOf($query);
                if ($table !== '' && in_array($table, $this->missingTables, true)) {
                    $this->missingTableQueries[] = $table;
                    $this->last_error = "Table 'wordpress.{$table}' doesn't exist";

                    return null;
                }

                if ($this->varQueue === []) {
                    return 0;
                }

                return array_shift($this->varQueue);
            }

            public function get_results($query = null, $output = null)
            {
                if ($this->resultsQueue === []) {
                    return [];
                }

                return array_shift($this->resultsQueue);
            }

            private function existenceProbeTarget(string $query): ?string
            {
                return preg_match("/SHOW TABLES LIKE '([^']+)'/", $query, $m) === 1 ? $m[1] : null;
            }

            private function tableOf(string $query): string
            {
                return preg_match('/(?:FROM|JOIN)\s+`([^`]+)`/', $query, $m) === 1 ? $m[1] : '';
            }
        };
    }
}
