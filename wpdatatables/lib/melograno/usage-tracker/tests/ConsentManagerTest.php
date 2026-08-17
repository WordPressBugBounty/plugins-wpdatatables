<?php

declare(strict_types=1);

namespace Melograno\UsageTracker\Tests;

use Melograno\UsageTracker\Core\ConsentManager;
use PHPUnit\Framework\TestCase;

class ConsentManagerTest extends TestCase
{
    private const OPTION = 'test_usage_tracking_consent';

    protected function tearDown(): void
    {
        if (function_exists('delete_option')) {
            delete_option(self::OPTION);
        }

        parent::tearDown();
    }

    public function testIsEnabledWhenOptionNotConfigured(): void
    {
        $consent = new ConsentManager(self::OPTION);

        $this->assertFalse($consent->isConfigured());
        $this->assertFalse($consent->isEnabled());
    }

    public function testEnableSetsOption(): void
    {
        $consent = new ConsentManager(self::OPTION);
        $consent->enable();

        $this->assertTrue($consent->isConfigured());
        $this->assertTrue($consent->isEnabled());
        $this->assertSame(1, (int) get_option(self::OPTION));
    }

    public function testDisableSetsOptionToZero(): void
    {
        $consent = new ConsentManager(self::OPTION);
        $consent->enable();
        $consent->disable();

        $this->assertTrue($consent->isConfigured());
        $this->assertFalse($consent->isEnabled());
    }
}
