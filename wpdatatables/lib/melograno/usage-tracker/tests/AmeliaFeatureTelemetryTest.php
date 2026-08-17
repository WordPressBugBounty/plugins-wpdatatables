<?php

declare(strict_types=1);

namespace Melograno\UsageTracker\Tests;

use Melograno\UsageTracker\Collectors\Plugin\AmeliaFeatureTelemetry;
use Melograno\UsageTracker\Tests\Stubs\AmeliaConnectionStub;
use Melograno\UsageTracker\Tests\Stubs\AmeliaContainerStub;
use Melograno\UsageTracker\Tests\Stubs\AmeliaSettingsServiceStub;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/AmeliaFeatureTelemetryStubs.php';

\Melograno\UsageTracker\Tests\Stubs\AmeliaFeatureTelemetryStubLoader::load();

require_once __DIR__ . '/Stubs/AmeliaSettingsServiceStub.php';
require_once __DIR__ . '/Stubs/AmeliaContainerStub.php';

class AmeliaFeatureTelemetryTest extends TestCase
{
    public function testEnabledFeatureWithTableRecordsReturnsTrue(): void
    {
        $connection = new AmeliaConnectionStub();
        $connection->setFetchQueue([['found' => 1]]);
        $settings = new AmeliaSettingsServiceStub(['coupons' => true]);
        $container = new AmeliaContainerStub([
            'app.connection' => $connection,
        ]);

        $result = AmeliaFeatureTelemetry::collect($settings, $container);

        $this->assertSame(['coupons' => true], $result['features']);
        $this->assertSame([], $result['feature_metrics']);
        $this->assertStringContainsString('wp_amelia_coupons', $connection->queries[0]);
    }

    public function testEnabledFeatureWithoutTableRecordsReturnsFalse(): void
    {
        $connection = new AmeliaConnectionStub();
        $connection->setFetchQueue([['found' => 0]]);
        $settings = new AmeliaSettingsServiceStub(['packages' => true]);
        $container = new AmeliaContainerStub([
            'app.connection' => $connection,
        ]);

        $result = AmeliaFeatureTelemetry::collect($settings, $container);

        $this->assertSame(['packages' => false], $result['features']);
        $this->assertStringContainsString('wp_amelia_packages', $connection->queries[0]);
    }

    public function testDisabledFeatureIsOmittedFromPayload(): void
    {
        $connection = new AmeliaConnectionStub();
        $connection->setFetchQueue([['found' => 1]]);
        $settings = new AmeliaSettingsServiceStub(['coupons' => false, 'tax' => true]);
        $container = new AmeliaContainerStub([
            'app.connection' => $connection,
        ]);

        $result = AmeliaFeatureTelemetry::collect($settings, $container);

        $this->assertArrayNotHasKey('coupons', $result['features']);
        $this->assertSame(['tax' => true], $result['features']);
        $this->assertStringContainsString('wp_amelia_taxes', $connection->queries[0]);
    }

    public function testStripeCredentialsStillReportInUseWhenConfigured(): void
    {
        $settings = new AmeliaSettingsServiceStub(
            ['stripe' => true],
            [
                'payments' => [
                    'stripe' => [
                        'enabled' => true,
                        'testMode' => true,
                        'testPublishableKey' => 'pk_test',
                        'testSecretKey' => 'sk_test',
                    ],
                ],
            ]
        );
        $container = new AmeliaContainerStub([
            'app.connection' => new AmeliaConnectionStub(),
        ]);

        $result = AmeliaFeatureTelemetry::collect($settings, $container);

        $this->assertSame(['stripe' => true], $result['features']);
        $this->assertSame(
            ['stripe' => ['usage_count' => 0]],
            $result['feature_metrics']
        );
    }

    public function testStripeNotInUseWhenDisabledOnPaymentsSettings(): void
    {
        $settings = new AmeliaSettingsServiceStub(
            ['stripe' => true],
            [
                'payments' => [
                    'stripe' => [
                        'enabled' => false,
                        'testMode' => true,
                        'testPublishableKey' => 'pk_test',
                        'testSecretKey' => 'sk_test',
                    ],
                ],
            ]
        );
        $container = new AmeliaContainerStub([
            'app.connection' => new AmeliaConnectionStub(),
        ]);

        $result = AmeliaFeatureTelemetry::collect($settings, $container);

        $this->assertSame(['stripe' => false], $result['features']);
        $this->assertSame(
            ['stripe' => ['usage_count' => 0]],
            $result['feature_metrics']
        );
    }

    public function testStripeTransactionCountReportedFromPaymentsTable(): void
    {
        $connection = new AmeliaConnectionStub();
        // groupBooking + multipleLocations checks run before payment gateway counts.
        $connection->setFetchQueue([['found' => 0], ['found' => 0], ['cnt' => 17]]);
        $settings = new AmeliaSettingsServiceStub(
            ['stripe' => true],
            [
                'payments' => [
                    'stripe' => [
                        'enabled' => true,
                        'testMode' => false,
                        'livePublishableKey' => 'pk_live',
                        'liveSecretKey' => 'sk_live',
                    ],
                ],
            ]
        );
        $container = new AmeliaContainerStub([
            'app.connection' => $connection,
        ]);

        $result = AmeliaFeatureTelemetry::collect($settings, $container);

        $this->assertSame(17, $result['feature_metrics']['stripe']['usage_count'] ?? null);
        $this->assertStringContainsString("gateway = 'stripe'", $connection->queries[2]);
    }

    public function testWebhooksConfiguredReturnsTrue(): void
    {
        $settings = new AmeliaSettingsServiceStub(
            ['webhooks' => true],
            [
                'webHooks' => [
                    ['name' => 'Booking added', 'url' => 'https://example.com/hook'],
                ],
            ]
        );
        $container = new AmeliaContainerStub([
            'app.connection' => new AmeliaConnectionStub(),
        ]);

        $result = AmeliaFeatureTelemetry::collect($settings, $container);

        $this->assertSame(['webhooks' => true], $result['features']);
    }

    public function testCustomFieldsUsesTableExistsQuery(): void
    {
        $settings = new AmeliaSettingsServiceStub(['customFields' => true]);

        $connectionWithoutFields = new AmeliaConnectionStub();
        $connectionWithoutFields->setFetchQueue([['found' => 0]]);
        $containerWithoutFields = new AmeliaContainerStub([
            'app.connection' => $connectionWithoutFields,
        ]);

        $result = AmeliaFeatureTelemetry::collect($settings, $containerWithoutFields);

        $this->assertSame(['customFields' => false], $result['features']);
        $this->assertStringContainsString('wp_amelia_custom_fields', $connectionWithoutFields->queries[0]);

        $connectionWithFields = new AmeliaConnectionStub();
        $connectionWithFields->setFetchQueue([['found' => 1]]);
        $containerWithFields = new AmeliaContainerStub([
            'app.connection' => $connectionWithFields,
        ]);

        $resultWithRecords = AmeliaFeatureTelemetry::collect($settings, $containerWithFields);

        $this->assertSame(['customFields' => true], $resultWithRecords['features']);
    }

    public function testTableQueryFailureReturnsFalse(): void
    {
        $settings = new AmeliaSettingsServiceStub(['coupons' => true]);
        $container = new AmeliaContainerStub([
            'app.connection' => new AmeliaConnectionStub(),
        ]);

        $result = AmeliaFeatureTelemetry::collect($settings, $container);

        $this->assertSame(['coupons' => false], $result['features']);
    }

    public function testGroupBookingIncludedWhenServiceAllowsMultiplePersons(): void
    {
        $connection = new AmeliaConnectionStub();
        $connection->setFetchQueue([['found' => 1]]);
        $settings = new AmeliaSettingsServiceStub();
        $container = new AmeliaContainerStub([
            'app.connection' => $connection,
        ]);

        $result = AmeliaFeatureTelemetry::collect($settings, $container);

        $this->assertSame(['groupBooking' => true], $result['features']);
        $this->assertStringContainsString('wp_amelia_services', $connection->queries[0]);
        $this->assertStringContainsString('maxCapacity > 1', $connection->queries[0]);
    }

    public function testGroupBookingOmittedWhenNoServiceAllowsMultiplePersons(): void
    {
        $connection = new AmeliaConnectionStub();
        $connection->setFetchQueue([['found' => 0], ['found' => 0]]);
        $settings = new AmeliaSettingsServiceStub(['coupons' => true]);
        $container = new AmeliaContainerStub([
            'app.connection' => $connection,
        ]);

        $result = AmeliaFeatureTelemetry::collect($settings, $container);

        $this->assertArrayNotHasKey('groupBooking', $result['features']);
        $this->assertSame(['coupons' => false], $result['features']);
    }

    public function testMultilingualSupportIncludedWhenAdditionalLanguageEnabled(): void
    {
        $settings = new AmeliaSettingsServiceStub(
            [],
            ['general' => ['usedLanguages' => ['fr_FR']]]
        );
        $container = new AmeliaContainerStub([
            'app.connection' => new AmeliaConnectionStub(),
        ]);

        $result = AmeliaFeatureTelemetry::collect($settings, $container);

        $this->assertSame(['multilingualSupport' => true], $result['features']);
    }

    public function testMultilingualSupportOmittedWhenNoAdditionalLanguageEnabled(): void
    {
        $connection = new AmeliaConnectionStub();
        $connection->setFetchQueue([['found' => 0]]);
        $settings = new AmeliaSettingsServiceStub(
            ['coupons' => true],
            ['general' => ['usedLanguages' => []]]
        );
        $container = new AmeliaContainerStub([
            'app.connection' => $connection,
        ]);

        $result = AmeliaFeatureTelemetry::collect($settings, $container);

        $this->assertArrayNotHasKey('multilingualSupport', $result['features']);
        $this->assertSame(['coupons' => false], $result['features']);
    }

    public function testMultipleLocationsIncludedWhenAtLeastTwoLocationsExist(): void
    {
        $connection = new AmeliaConnectionStub();
        $connection->setFetchQueue([['found' => 0], ['found' => 1]]);
        $settings = new AmeliaSettingsServiceStub();
        $container = new AmeliaContainerStub([
            'app.connection' => $connection,
        ]);

        $result = AmeliaFeatureTelemetry::collect($settings, $container);

        $this->assertSame(['multipleLocations' => true], $result['features']);
        $this->assertStringContainsString('wp_amelia_locations', $connection->queries[1]);
        $this->assertStringContainsString('COUNT(*) >= 2', $connection->queries[1]);
    }

    public function testMultipleLocationsOmittedWhenFewerThanTwoLocationsExist(): void
    {
        $connection = new AmeliaConnectionStub();
        $connection->setFetchQueue([['found' => 0], ['found' => 0]]);
        $settings = new AmeliaSettingsServiceStub(['coupons' => true]);
        $container = new AmeliaContainerStub([
            'app.connection' => $connection,
        ]);

        $result = AmeliaFeatureTelemetry::collect($settings, $container);

        $this->assertArrayNotHasKey('multipleLocations', $result['features']);
        $this->assertSame(['coupons' => false], $result['features']);
    }

    public function testSmtpIncludedWhenSmtpMailServiceConfigured(): void
    {
        $settings = new AmeliaSettingsServiceStub(
            [],
            [
                'notifications' => [
                    'mailService' => 'smtp',
                    'smtpHost' => 'smtp.example.com',
                    'smtpPort' => '587',
                    'smtpUsername' => 'user@example.com',
                    'smtpPassword' => 'secret',
                ],
            ]
        );
        $container = new AmeliaContainerStub([
            'app.connection' => new AmeliaConnectionStub(),
        ]);

        $result = AmeliaFeatureTelemetry::collect($settings, $container);

        $this->assertSame(['smtp' => true], $result['features']);
    }

    public function testSmtpOmittedWhenMailServiceIsNotSmtp(): void
    {
        $connection = new AmeliaConnectionStub();
        $connection->setFetchQueue([['found' => 0]]);
        $settings = new AmeliaSettingsServiceStub(
            ['coupons' => true],
            [
                'notifications' => [
                    'mailService' => 'wp_mail',
                    'smtpHost' => 'smtp.example.com',
                    'smtpPort' => '587',
                    'smtpUsername' => 'user@example.com',
                    'smtpPassword' => 'secret',
                ],
            ]
        );
        $container = new AmeliaContainerStub([
            'app.connection' => $connection,
        ]);

        $result = AmeliaFeatureTelemetry::collect($settings, $container);

        $this->assertArrayNotHasKey('smtp', $result['features']);
        $this->assertSame(['coupons' => false], $result['features']);
    }

    public function testSmtpOmittedWhenSmtpCredentialsAreMissing(): void
    {
        $connection = new AmeliaConnectionStub();
        $connection->setFetchQueue([['found' => 0]]);
        $settings = new AmeliaSettingsServiceStub(
            ['coupons' => true],
            [
                'notifications' => [
                    'mailService' => 'smtp',
                    'smtpHost' => 'smtp.example.com',
                    'smtpPort' => '587',
                    'smtpUsername' => 'user@example.com',
                    'smtpPassword' => '',
                ],
            ]
        );
        $container = new AmeliaContainerStub([
            'app.connection' => $connection,
        ]);

        $result = AmeliaFeatureTelemetry::collect($settings, $container);

        $this->assertArrayNotHasKey('smtp', $result['features']);
        $this->assertSame(['coupons' => false], $result['features']);
    }

    public function testBuddyBossInUseReflectsBuddyBossPlatformAvailability(): void
    {
        $settings = new AmeliaSettingsServiceStub(['buddyboss' => true]);
        $container = new AmeliaContainerStub([
            'app.connection' => new AmeliaConnectionStub(),
        ]);

        if (!defined('BP_PLATFORM_VERSION')) {
            $result = AmeliaFeatureTelemetry::collect($settings, $container);
            $this->assertSame(['buddyboss' => false], $result['features']);

            define('BP_PLATFORM_VERSION', '2.0.0');
        }

        $result = AmeliaFeatureTelemetry::collect($settings, $container);
        $this->assertSame(['buddyboss' => true], $result['features']);
    }

    public function testOnSiteReportsUsageFromPaymentsTable(): void
    {
        $connection = new AmeliaConnectionStub();
        $connection->setFetchQueue([['found' => 0], ['found' => 1], ['cnt' => 4]]);
        $settings = new AmeliaSettingsServiceStub(
            [],
            ['payments' => ['onSite' => true]]
        );
        $container = new AmeliaContainerStub([
            'app.connection' => $connection,
        ]);

        $result = AmeliaFeatureTelemetry::collect($settings, $container);

        $this->assertSame(['onSite' => true], $result['features']);
        $this->assertSame(
            ['onSite' => ['usage_count' => 4]],
            $result['feature_metrics']
        );
    }

    public function testCartInUseReturnsTrueWhenCartAppointmentExists(): void
    {
        $connection = new AmeliaConnectionStub();
        $connection->setFetchQueue([['found' => 1]]);
        $settings = new AmeliaSettingsServiceStub(['cart' => true]);
        $container = new AmeliaContainerStub([
            'app.connection' => $connection,
        ]);

        $result = AmeliaFeatureTelemetry::collect($settings, $container);

        $this->assertSame(['cart' => true], $result['features']);
        $this->assertStringContainsString('wp_amelia_appointments', $connection->queries[0]);
        $this->assertStringContainsString('parentId', $connection->queries[0]);
        $this->assertStringContainsString('recurringCycle', $connection->queries[0]);
    }

    public function testCartInUseReturnsFalseWhenNoCartAppointmentExists(): void
    {
        $connection = new AmeliaConnectionStub();
        $connection->setFetchQueue([['found' => 0]]);
        $settings = new AmeliaSettingsServiceStub(['cart' => true]);
        $container = new AmeliaContainerStub([
            'app.connection' => $connection,
        ]);

        $result = AmeliaFeatureTelemetry::collect($settings, $container);

        $this->assertSame(['cart' => false], $result['features']);
    }

    public function testWaitingListAppointmentsInUseWhenServiceHasWaitingListEnabled(): void
    {
        $connection = new AmeliaConnectionStub();
        $connection->setFetchQueue([['found' => 1]]);
        $settings = new AmeliaSettingsServiceStub(['waitingListAppointments' => true]);
        $container = new AmeliaContainerStub([
            'app.connection' => $connection,
        ]);

        $result = AmeliaFeatureTelemetry::collect($settings, $container);

        $this->assertSame(['waitingListAppointments' => true], $result['features']);
        $this->assertStringContainsString('wp_amelia_services', $connection->queries[0]);
        $this->assertStringContainsString('settings REGEXP', $connection->queries[0]);
        $this->assertStringContainsString('"waitingList":', $connection->queries[0]);
    }

    public function testWaitingListInUseWhenEventHasWaitingListEnabled(): void
    {
        $connection = new AmeliaConnectionStub();
        $connection->setFetchQueue([['found' => 1]]);
        $settings = new AmeliaSettingsServiceStub(['waitingList' => true]);
        $container = new AmeliaContainerStub([
            'app.connection' => $connection,
        ]);

        $result = AmeliaFeatureTelemetry::collect($settings, $container);

        $this->assertSame(['waitingList' => true], $result['features']);
        $this->assertStringContainsString('wp_amelia_events', $connection->queries[0]);
        $this->assertStringContainsString('settings REGEXP', $connection->queries[0]);
    }

    public function testTimezonesInUseWhenProviderHasNonDefaultTimezone(): void
    {
        $connection = new AmeliaConnectionStub();
        $connection->setFetchQueue([['found' => 1]]);
        $settings = new AmeliaSettingsServiceStub(
            ['timezones' => true],
            ['wordpress' => ['timeZoneString' => 'Europe/Belgrade']]
        );
        $container = new AmeliaContainerStub([
            'app.connection' => $connection,
        ]);

        $result = AmeliaFeatureTelemetry::collect($settings, $container);

        $this->assertSame(['timezones' => true], $result['features']);
        $this->assertStringContainsString('wp_amelia_users', $connection->queries[0]);
        $this->assertStringContainsString("type = 'provider'", $connection->queries[0]);
        $this->assertStringContainsString('timeZone != :defaultTimeZone', $connection->queries[0]);
    }

    public function testTimezonesNotInUseWhenProviderOnlyHasDefaultTimezone(): void
    {
        $connection = new AmeliaConnectionStub();
        $connection->setFetchQueue([['found' => 0]]);
        $settings = new AmeliaSettingsServiceStub(
            ['timezones' => true],
            ['wordpress' => ['timeZoneString' => 'Europe/Belgrade']]
        );
        $container = new AmeliaContainerStub([
            'app.connection' => $connection,
        ]);

        $result = AmeliaFeatureTelemetry::collect($settings, $container);

        $this->assertSame(['timezones' => false], $result['features']);
        $this->assertStringContainsString('timeZone != :defaultTimeZone', $connection->queries[0]);
    }

    public function testCustomPricingSubgroupsReportedWhenFeatureEnabled(): void
    {
        $connection = new AmeliaConnectionStub();
        $connection->setFetchAllResult([
            [
                'customPricing' => json_encode([
                    'enabled' => 'duration',
                    'durations' => ['7200' => ['price' => 120, 'rules' => []]],
                    'persons' => [],
                    'periods' => ['default' => [], 'custom' => []],
                ]),
            ],
            [
                'customPricing' => json_encode([
                    'enabled' => 'person',
                    'durations' => [],
                    'persons' => ['2' => ['price' => 80, 'rules' => []]],
                    'periods' => ['default' => [], 'custom' => []],
                ]),
            ],
            [
                'customPricing' => json_encode([
                    'enabled' => 'period',
                    'durations' => [],
                    'persons' => [],
                    'periods' => [
                        'default' => [['days' => [1, 2], 'ranges' => []]],
                        'custom' => [],
                    ],
                ]),
            ],
        ]);
        $settings = new AmeliaSettingsServiceStub(['customPricing' => true]);
        $container = new AmeliaContainerStub([
            'app.connection' => $connection,
        ]);

        $result = AmeliaFeatureTelemetry::collect($settings, $container);

        $this->assertSame(
            [
                'customPricingByDuration' => true,
                'customPricingByNumberOfPeople' => true,
                'customPricingByDateAndTime' => true,
            ],
            $result['features']
        );
        $this->assertStringContainsString('wp_amelia_services', $connection->queries[0]);
        $this->assertStringContainsString('customPricing IS NOT NULL', $connection->queries[0]);
    }

    public function testCustomPricingSubgroupsFalseWhenEnabledTypeHasNoConfiguredRules(): void
    {
        $connection = new AmeliaConnectionStub();
        $connection->setFetchAllResult([
            [
                'customPricing' => json_encode([
                    'enabled' => 'duration',
                    'durations' => [],
                    'persons' => [],
                    'periods' => ['default' => [], 'custom' => []],
                ]),
            ],
            [
                'customPricing' => json_encode([
                    'enabled' => 'person',
                    'durations' => [],
                    'persons' => [],
                    'periods' => ['default' => [], 'custom' => []],
                ]),
            ],
            [
                'customPricing' => json_encode([
                    'enabled' => 'period',
                    'durations' => [],
                    'persons' => [],
                    'periods' => ['default' => [], 'custom' => []],
                ]),
            ],
        ]);
        $settings = new AmeliaSettingsServiceStub(['customPricing' => true]);
        $container = new AmeliaContainerStub([
            'app.connection' => $connection,
        ]);

        $result = AmeliaFeatureTelemetry::collect($settings, $container);

        $this->assertSame(
            [
                'customPricingByDuration' => false,
                'customPricingByNumberOfPeople' => false,
                'customPricingByDateAndTime' => false,
            ],
            $result['features']
        );
    }

    public function testCustomPricingSubgroupsOmittedWhenFeatureDisabled(): void
    {
        $connection = new AmeliaConnectionStub();
        $connection->setFetchAllResult([
            [
                'customPricing' => json_encode([
                    'enabled' => 'duration',
                    'durations' => ['7200' => ['price' => 120, 'rules' => []]],
                    'persons' => [],
                    'periods' => ['default' => [], 'custom' => []],
                ]),
            ],
        ]);
        $settings = new AmeliaSettingsServiceStub(['customPricing' => false]);
        $container = new AmeliaContainerStub([
            'app.connection' => $connection,
        ]);

        $result = AmeliaFeatureTelemetry::collect($settings, $container);

        $this->assertArrayNotHasKey('customPricingByDuration', $result['features']);
        $this->assertArrayNotHasKey('customPricingByNumberOfPeople', $result['features']);
        $this->assertArrayNotHasKey('customPricingByDateAndTime', $result['features']);
        foreach ($connection->queries as $query) {
            $this->assertStringNotContainsString('customPricing IS NOT NULL', $query);
        }
    }

    public function testCustomPricingByDateAndTimeDetectsSpecificDateRules(): void
    {
        $connection = new AmeliaConnectionStub();
        $connection->setFetchAllResult([
            [
                'customPricing' => json_encode([
                    'enabled' => 'period',
                    'durations' => [],
                    'persons' => [],
                    'periods' => [
                        'default' => [],
                        'custom' => [
                            [
                                'dates' => ['start' => '2026-01-01', 'end' => '2026-01-31'],
                                'ranges' => [],
                            ],
                        ],
                    ],
                ]),
            ],
        ]);
        $settings = new AmeliaSettingsServiceStub(['customPricing' => true]);
        $container = new AmeliaContainerStub([
            'app.connection' => $connection,
        ]);

        $result = AmeliaFeatureTelemetry::collect($settings, $container);

        $this->assertTrue($result['features']['customPricingByDateAndTime']);
        $this->assertFalse($result['features']['customPricingByDuration']);
        $this->assertFalse($result['features']['customPricingByNumberOfPeople']);
    }
}
