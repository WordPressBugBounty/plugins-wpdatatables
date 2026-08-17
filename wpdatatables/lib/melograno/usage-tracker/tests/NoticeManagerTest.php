<?php

declare(strict_types=1);

namespace Melograno\UsageTracker\Tests;

use Melograno\UsageTracker\Core\NoticeManager;
use PHPUnit\Framework\TestCase;

class NoticeManagerTest extends TestCase
{
    private const OPTION = 'test_usage_tracking_notice';

    protected function tearDown(): void
    {
        if (function_exists('delete_option')) {
            delete_option(self::OPTION);
        }

        parent::tearDown();
    }

    public function testIsArmedWhenOptionNotSet(): void
    {
        $notice = new NoticeManager(self::OPTION);

        $this->assertFalse($notice->isArmed());
    }

    public function testArmSetsOptionToYes(): void
    {
        $notice = new NoticeManager(self::OPTION);
        $notice->arm();

        $this->assertTrue($notice->isArmed());
        $this->assertSame('yes', get_option(self::OPTION));
    }

    public function testDismissSetsOptionToNo(): void
    {
        $notice = new NoticeManager(self::OPTION);
        $notice->arm();
        $notice->dismiss();

        $this->assertFalse($notice->isArmed());
        $this->assertSame('no', get_option(self::OPTION));
    }
}
