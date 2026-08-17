<?php

declare(strict_types=1);

namespace Melograno\UsageTracker\Tests;

use Melograno\UsageTracker\Collectors\BaseCollector;
use Melograno\UsageTracker\Collectors\ConsentNoticeCollectorInterface;
use Melograno\UsageTracker\Core\ConsentNoticeService;
use Melograno\UsageTracker\Core\UsageTracker;
use PHPUnit\Framework\TestCase;

class ConsentNoticeServiceTest extends TestCase
{
    public const CONSENT_OPTION = 'test_usage_tracking_consent';

    public const NOTICE_OPTION = 'test_usage_tracking_notice';

    protected function tearDown(): void
    {
        delete_option(self::CONSENT_OPTION);
        delete_option(self::NOTICE_OPTION);
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

    private function collector(
        bool $migrate = true,
        bool $showNotice = true,
        bool $enabledByDefault = false,
        ?string $migrationVersion = '2.4.2'
    ): ConsentNoticeCollectorInterface {
        return new class (
            $migrate,
            $showNotice,
            $enabledByDefault,
            $migrationVersion
        ) implements ConsentNoticeCollectorInterface {
            private $migrate;
            private $showNotice;
            private $enabledByDefault;
            private $migrationVersion;

            public function __construct(
                bool $migrate,
                bool $showNotice,
                bool $enabledByDefault,
                ?string $migrationVersion
            ) {
                $this->migrate = $migrate;
                $this->showNotice = $showNotice;
                $this->enabledByDefault = $enabledByDefault;
                $this->migrationVersion = $migrationVersion;
            }

            public function getPluginSlug(): string
            {
                return 'test-plugin';
            }

            public function getConsentOptionName(): string
            {
                return ConsentNoticeServiceTest::CONSENT_OPTION;
            }

            public function getNoticeOptionName(): string
            {
                return ConsentNoticeServiceTest::NOTICE_OPTION;
            }

            public function getOptInMigrationVersion(): ?string
            {
                return $this->migrationVersion;
            }

            public function shouldEnableConsentByDefault(): bool
            {
                return $this->enabledByDefault;
            }

            public function shouldShowAdminNotice(): bool
            {
                return $this->showNotice;
            }

            public function shouldMigrateConsentOnUpgrade(): bool
            {
                return $this->migrate;
            }

            public function getConsentNoticeAjaxPrefix(): string
            {
                return 'test-plugin';
            }

            public function getConsentNoticePresentation(): array
            {
                return [
                    'accentColor' => '#000000',
                    'iconUrl' => 'https://example.com/icon.svg',
                    'iconAlt' => 'Test',
                    'title' => 'Improve Test',
                    'description' => 'Test description',
                    'enableLabel' => 'Improve plugin',
                    'learnMoreLabel' => 'Learn more',
                    'learnMoreUrl' => 'https://example.com/privacy',
                    'dismissLabel' => 'Dismiss',
                ];
            }

            public function renderConsentAdminNotice(): void
            {
            }

            public function getCronHookName(): string
            {
                return 'melograno_usage_tracker_test-plugin_send';
            }

            public function getCronSchedule(): string
            {
                return 'weekly';
            }

            public function collect(): array
            {
                return [];
            }
        };
    }

    private function service(?ConsentNoticeCollectorInterface $collector = null): ConsentNoticeService
    {
        return new ConsentNoticeService($collector ?? $this->collector());
    }

    public function testMigrateOnUpgradeArmsNoticeForEligibleCollector(): void
    {
        UsageTracker::init($this->collector(), 'test-plugin/test-plugin.php', '2.4.1', '2.4.2');

        $this->assertSame('yes', get_option(self::NOTICE_OPTION));
        $this->assertTrue(metadata_exists('option', self::CONSENT_OPTION));
        $this->assertSame(0, (int) get_option(self::CONSENT_OPTION));
    }

    public function testMigrateOnUpgradeSkipsWhenAlreadyMigrated(): void
    {
        update_option(self::CONSENT_OPTION, 1, true);

        UsageTracker::init($this->collector(), 'test-plugin/test-plugin.php', '2.4.2', '2.4.3');

        $this->assertFalse(metadata_exists('option', self::NOTICE_OPTION));
    }

    public function testShouldRunUpgradeMigration(): void
    {
        $service = $this->service();

        $this->assertTrue($service->shouldRunUpgradeMigration('2.4.1', '2.4.2'));
        $this->assertFalse($service->shouldRunUpgradeMigration('2.4.2', '2.4.3'));
        $this->assertFalse($service->shouldRunUpgradeMigration('2.4.0', '2.4.1'));
    }

    public function testSetInitialConsentForNewInstallationArmsNoticeWhenDisabledByDefault(): void
    {
        $collector = $this->collector(false, true, false);
        UsageTracker::init($collector, 'test-plugin/test-plugin.php');

        $this->assertSame('yes', get_option(self::NOTICE_OPTION));
        $this->assertTrue(metadata_exists('option', self::CONSENT_OPTION));
        $this->assertSame(0, (int) get_option(self::CONSENT_OPTION));
    }

    public function testSetInitialConsentForNewInstallationDoesNotArmNoticeWhenEnabledByDefault(): void
    {
        $collector = $this->collector(false, true, true);
        UsageTracker::init($collector, 'test-plugin/test-plugin.php');

        $this->assertFalse(metadata_exists('option', self::NOTICE_OPTION));
        $this->assertTrue(metadata_exists('option', self::CONSENT_OPTION));
        $this->assertSame(1, (int) get_option(self::CONSENT_OPTION));
    }

    public function testMigrateConsentOnUpgradeRunsMigrationForRegisteredCollector(): void
    {
        $collector = $this->collector();
        $pluginFile = 'test-plugin/test-plugin.php';

        UsageTracker::init($collector, $pluginFile, '2.4.1', '2.4.2');

        $this->assertSame('yes', get_option(self::NOTICE_OPTION));
    }

    public function testMigrateConsentOnUpgradeIsNoOpWhenNoCollectorRegistered(): void
    {
        $collector = new class () extends BaseCollector {
            public function getPluginSlug(): string
            {
                return 'test-plugin';
            }

            public function getConsentOptionName(): string
            {
                return ConsentNoticeServiceTest::CONSENT_OPTION;
            }

            protected function pluginPayload(): array
            {
                return [];
            }
        };

        UsageTracker::init($collector, 'test-plugin/test-plugin.php', '2.4.1', '2.4.2');

        $this->assertFalse(metadata_exists('option', self::NOTICE_OPTION));
    }

    public function testMigrateConsentOnUpgradeIsNoOpWhenVersionAlreadyMigrated(): void
    {
        $collector = $this->collector();
        $pluginFile = 'test-plugin/test-plugin.php';

        UsageTracker::init($collector, $pluginFile, '2.4.1', '2.4.2');
        UsageTracker::setConsent(true);

        UsageTracker::init($collector, $pluginFile, '2.4.2', '2.4.3');

        $this->assertNotSame('yes', get_option(self::NOTICE_OPTION));
    }

    public function testBootInitializesConsentWhenNotConfigured(): void
    {
        $collector = $this->collector(false, true, true);
        $pluginFile = 'test-plugin/test-plugin.php';

        UsageTracker::init($collector, $pluginFile);

        $this->assertTrue(metadata_exists('option', self::CONSENT_OPTION));
        $this->assertSame(1, (int) get_option(self::CONSENT_OPTION));
    }

    public function testBootArmsNoticeWhenConsentDisabledByDefault(): void
    {
        $collector = $this->collector(false, true, false);
        $pluginFile = 'test-plugin/test-plugin.php';

        UsageTracker::init($collector, $pluginFile);

        $this->assertTrue(metadata_exists('option', self::CONSENT_OPTION));
        $this->assertSame(0, (int) get_option(self::CONSENT_OPTION));
        $this->assertSame('yes', get_option(self::NOTICE_OPTION));
    }

    public function testDismissedNoticeStaysDismissedAfterReInit(): void
    {
        $collector = $this->collector(false, true, false);
        $pluginFile = 'test-plugin/test-plugin.php';

        UsageTracker::init($collector, $pluginFile);
        $this->assertSame('yes', get_option(self::NOTICE_OPTION));

        update_option(self::NOTICE_OPTION, 'no', true);

        $this->resetUsageTrackerStaticState();
        UsageTracker::init($collector, $pluginFile);

        $this->assertSame('no', get_option(self::NOTICE_OPTION));
    }

    public function testDisablingConsentArmsNoticeOnlyWhenExplicitlyRequested(): void
    {
        $collector = $this->collector(false, true, true);
        $pluginFile = 'test-plugin/test-plugin.php';

        UsageTracker::init($collector, $pluginFile);

        $this->assertFalse(metadata_exists('option', self::NOTICE_OPTION));
        $this->assertTrue(UsageTracker::isConsentEnabled($collector));

        UsageTracker::setConsent(false, $collector, true);

        $this->assertFalse(UsageTracker::isConsentEnabled($collector));
        $this->assertSame('yes', get_option(self::NOTICE_OPTION));
    }

    public function testDisablingConsentDismissesNoticeByDefault(): void
    {
        $collector = $this->collector(false, true, true);
        $pluginFile = 'test-plugin/test-plugin.php';

        UsageTracker::init($collector, $pluginFile);
        UsageTracker::setConsent(false, $collector, true);
        $this->assertSame('yes', get_option(self::NOTICE_OPTION));

        UsageTracker::setConsent(true, $collector);
        UsageTracker::setConsent(false, $collector);

        $this->assertFalse(UsageTracker::isConsentEnabled($collector));
        $this->assertNotSame('yes', get_option(self::NOTICE_OPTION));
    }

    public function testDefinitiveOptOutDismissesArmedNoticeWhenConsentAlreadyDisabled(): void
    {
        $collector = $this->collector();
        $pluginFile = 'test-plugin/test-plugin.php';

        UsageTracker::init($collector, $pluginFile, '2.4.1', '2.4.2');
        $this->assertSame('yes', get_option(self::NOTICE_OPTION));
        $this->assertFalse(UsageTracker::isConsentEnabled($collector));

        UsageTracker::setConsent(false, $collector);

        $this->assertFalse(UsageTracker::isConsentEnabled($collector));
        $this->assertNotSame('yes', get_option(self::NOTICE_OPTION));
    }

    public function testArmedNoticeSurvivesWhenConsentAlreadyDisabledAndArmingRequested(): void
    {
        $collector = $this->collector();
        $pluginFile = 'test-plugin/test-plugin.php';

        UsageTracker::init($collector, $pluginFile, '2.4.1', '2.4.2');
        $this->assertSame('yes', get_option(self::NOTICE_OPTION));

        UsageTracker::setConsent(false, $collector, true);

        $this->assertFalse(UsageTracker::isConsentEnabled($collector));
        $this->assertSame('yes', get_option(self::NOTICE_OPTION));
    }

    public function testDisablingConsentDoesNotArmNoticeWhenCollectorHidesAdminNotice(): void
    {
        $collector = $this->collector(false, false, true);
        $pluginFile = 'test-plugin/test-plugin.php';

        UsageTracker::init($collector, $pluginFile);
        UsageTracker::setConsent(false, $collector, true);

        $this->assertNotSame('yes', get_option(self::NOTICE_OPTION));
    }

    public function testUpdateSettingsDisablingConsentArmsNoticeDuringWelcomeWizard(): void
    {
        $collector = $this->collector(false, true, true);
        $pluginFile = 'test-plugin/test-plugin.php';

        UsageTracker::init($collector, $pluginFile);

        $settings = ['usageTrackingEnabled' => false];
        UsageTracker::updateSettings($settings, $collector, true);

        $this->assertFalse(UsageTracker::isConsentEnabled($collector));
        $this->assertSame('yes', get_option(self::NOTICE_OPTION));
    }

    public function testUpdateSettingsDisablingConsentDismissesNoticeFromGeneralSettings(): void
    {
        $collector = $this->collector(false, true, true);
        $pluginFile = 'test-plugin/test-plugin.php';

        UsageTracker::init($collector, $pluginFile);
        UsageTracker::setConsent(false, $collector, true);
        $this->assertSame('yes', get_option(self::NOTICE_OPTION));

        UsageTracker::setConsent(true, $collector);

        $settings = ['usageTrackingEnabled' => false];
        UsageTracker::updateSettings($settings, $collector);

        $this->assertFalse(UsageTracker::isConsentEnabled($collector));
        $this->assertNotSame('yes', get_option(self::NOTICE_OPTION));
    }
}
