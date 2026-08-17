<?php

declare(strict_types=1);

namespace Melograno\UsageTracker\Tests\Stubs;

use AmeliaBooking\Infrastructure\Common\Container;

/**
 * Minimal Amelia container for AmeliaFeatureTelemetry tests.
 */
final class AmeliaContainerStub extends Container
{
    /** @var array<string, mixed> */
    private $services;

    /**
     * @param array<string, mixed> $services
     */
    public function __construct(array $services = [])
    {
        $this->services = $services;
    }

    public function get(string $id)
    {
        if (!array_key_exists($id, $this->services)) {
            throw new \RuntimeException('Unknown container service: ' . $id);
        }

        return $this->services[$id];
    }

    public function getDatabaseConnection()
    {
        return $this->get('app.connection');
    }
}
