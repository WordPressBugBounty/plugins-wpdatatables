<?php

declare(strict_types=1);

namespace Melograno\UsageTracker\Tests;

use Melograno\UsageTracker\Core\Anonymizer;
use PHPUnit\Framework\TestCase;

class AnonymizerTest extends TestCase
{
    public function testStripsSensitiveKeys(): void
    {
        $anonymizer = new Anonymizer();

        $result = $anonymizer->anonymize([
            'plugin' => 'ameliabooking',
            'site_url' => 'https://example.com',
            'nested' => [
                'email' => 'user@example.com',
                'count' => 3,
            ],
        ]);

        $this->assertArrayHasKey('site_id', $result);
        $this->assertArrayNotHasKey('site_url', $result);
        $this->assertArrayNotHasKey('email', $result['nested']);
        $this->assertSame(3, $result['nested']['count']);
    }
}
