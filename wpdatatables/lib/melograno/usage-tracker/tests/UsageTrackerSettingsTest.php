<?php

declare(strict_types=1);

namespace Melograno\UsageTracker\Tests;

use Melograno\UsageTracker\Collectors\BaseCollector;
use Melograno\UsageTracker\Core\UsageTracker;
use PHPUnit\Framework\TestCase;

class UsageTrackerSettingsTest extends TestCase
{
    public const OPTION = 'test_plugin_usage_tracking_consent';

    private const PLUGIN_FILE = 'test-plugin/test-plugin.php';

    protected function tearDown(): void
    {
        delete_option(self::OPTION);
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

    private function collector(): BaseCollector
    {
        return new class () extends BaseCollector {
            public function getPluginSlug(): string
            {
                return 'test-plugin';
            }

            public function getConsentOptionName(): string
            {
                return UsageTrackerSettingsTest::OPTION;
            }

            protected function pluginPayload(): array
            {
                return [];
            }
        };
    }

    private function initTracker(): void
    {
        UsageTracker::init($this->collector(), self::PLUGIN_FILE);
    }

    public function testGetSettingsReturnsConsentState(): void
    {
        $this->initTracker();

        UsageTracker::setConsent(true);
        $settings = UsageTracker::getSettings();

        $this->assertArrayHasKey('usageTrackingEnabled', $settings);
        $this->assertTrue($settings['usageTrackingEnabled']);
    }

    public function testGetSettingsReturnsDisabledWhenConsentOff(): void
    {
        $this->initTracker();

        UsageTracker::setConsent(false);
        $settings = UsageTracker::getSettings();

        $this->assertFalse($settings['usageTrackingEnabled']);
        $this->assertTrue(metadata_exists('option', self::OPTION));
        $this->assertSame(0, (int) get_option(self::OPTION));
    }

    public function testUpdateSettingsAppliesConsent(): void
    {
        $this->initTracker();

        UsageTracker::setConsent(true);

        $settings = ['usageTrackingEnabled' => false, 'someOtherKey' => 'value'];
        UsageTracker::updateSettings($settings);

        $this->assertFalse(UsageTracker::isConsentEnabled());
    }

    public function testUpdateSettingsUnsetsHandledKeys(): void
    {
        $this->initTracker();

        $settings = ['usageTrackingEnabled' => true, 'someOtherKey' => 'value'];
        UsageTracker::updateSettings($settings);

        $this->assertArrayNotHasKey('usageTrackingEnabled', $settings);
        $this->assertArrayHasKey('someOtherKey', $settings);
        $this->assertSame('value', $settings['someOtherKey']);
    }

    public function testUpdateSettingsIgnoresMissingKeys(): void
    {
        $this->initTracker();

        UsageTracker::setConsent(true);

        $settings = ['unrelatedKey' => 42];
        UsageTracker::updateSettings($settings);

        $this->assertTrue(UsageTracker::isConsentEnabled());
        $this->assertSame(['unrelatedKey' => 42], $settings);
    }
}
