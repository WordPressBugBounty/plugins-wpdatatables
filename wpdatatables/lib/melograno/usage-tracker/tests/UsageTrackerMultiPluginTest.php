<?php

declare(strict_types=1);

namespace Melograno\UsageTracker\Tests;

use Melograno\UsageTracker\Collectors\BaseCollector;
use Melograno\UsageTracker\Core\UsageTracker;
use PHPUnit\Framework\TestCase;

class UsageTrackerMultiPluginTest extends TestCase
{
    public const OPTION_A = 'plugin_a_usage_tracking_consent';

    public const OPTION_B = 'plugin_b_usage_tracking_consent';

    protected function tearDown(): void
    {
        delete_option(self::OPTION_A);
        delete_option(self::OPTION_B);
        $GLOBALS['melograno_usage_tracker_actions'] = [];
        $this->resetUsageTrackerStaticState();
        parent::tearDown();
    }

    private function resetUsageTrackerStaticState(): void
    {
        $ref = new \ReflectionClass(UsageTracker::class);

        foreach (['bootstrapped', 'instances'] as $property) {
            $prop = $ref->getProperty($property);
            $prop->setAccessible(true);
            $prop->setValue([]);
        }
    }

    private function collectorA(): BaseCollector
    {
        return new class () extends BaseCollector {
            public function getPluginSlug(): string
            {
                return 'plugin-a';
            }

            public function getConsentOptionName(): string
            {
                return UsageTrackerMultiPluginTest::OPTION_A;
            }

            protected function pluginPayload(): array
            {
                return [];
            }
        };
    }

    private function collectorB(): BaseCollector
    {
        return new class () extends BaseCollector {
            public function getPluginSlug(): string
            {
                return 'plugin-b';
            }

            public function getConsentOptionName(): string
            {
                return UsageTrackerMultiPluginTest::OPTION_B;
            }

            protected function pluginPayload(): array
            {
                return [];
            }
        };
    }

    public function testEachCollectorBootsIndependently(): void
    {
        $collectorA = $this->collectorA();
        $collectorB = $this->collectorB();

        UsageTracker::init($collectorA, 'plugin-a/plugin-a.php');
        UsageTracker::init($collectorB, 'plugin-b/plugin-b.php');

        $this->assertTrue(has_action($collectorA->getCronHookName()));
        $this->assertTrue(has_action($collectorB->getCronHookName()));
    }

    public function testConsentWritesStayScopedToCollector(): void
    {
        $collectorA = $this->collectorA();
        $collectorB = $this->collectorB();

        UsageTracker::init($collectorA, 'plugin-a/plugin-a.php');
        UsageTracker::init($collectorB, 'plugin-b/plugin-b.php');

        UsageTracker::setConsent(true, $collectorA);
        UsageTracker::setConsent(false, $collectorB);

        $this->assertTrue(UsageTracker::isConsentEnabled($collectorA));
        $this->assertFalse(UsageTracker::isConsentEnabled($collectorB));
        $this->assertTrue(metadata_exists('option', self::OPTION_A));
        $this->assertTrue(metadata_exists('option', self::OPTION_B));
        $this->assertSame(1, (int) get_option(self::OPTION_A));
        $this->assertSame(0, (int) get_option(self::OPTION_B));
    }

    public function testFacadeRequiresCollectorWhenMultipleTrackersRegistered(): void
    {
        UsageTracker::init($this->collectorA(), 'plugin-a/plugin-a.php');
        UsageTracker::init($this->collectorB(), 'plugin-b/plugin-b.php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Multiple usage trackers are registered');

        UsageTracker::setConsent(true);
    }

    public function testUpdateSettingsTargetsExplicitCollector(): void
    {
        $collectorA = $this->collectorA();
        $collectorB = $this->collectorB();

        UsageTracker::init($collectorA, 'plugin-a/plugin-a.php');
        UsageTracker::init($collectorB, 'plugin-b/plugin-b.php');

        $settings = ['usageTrackingEnabled' => true];
        UsageTracker::updateSettings($settings, $collectorB);

        $this->assertTrue(UsageTracker::isConsentEnabled($collectorB));
        $this->assertFalse(UsageTracker::isConsentEnabled($collectorA));
    }
}
