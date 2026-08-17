<?php

declare(strict_types=1);

namespace Melograno\UsageTracker\Tests\Stubs;

use AmeliaBooking\Domain\Services\Settings\SettingsService as AmeliaSettingsService;

/**
 * Configurable SettingsService stand-in for AmeliaFeatureTelemetry tests.
 */
final class AmeliaSettingsServiceStub extends AmeliaSettingsService
{
    /** @var array<string, bool> */
    private $enabledFeatures = [];

    /** @var array<string, array<string, mixed>> */
    private $settings = [];

    /**
     * @param array<string, bool> $enabledFeatures
     * @param array<string, array<string, mixed>> $settings
     */
    public function __construct(array $enabledFeatures = [], array $settings = [])
    {
        $this->enabledFeatures = $enabledFeatures;
        $this->settings = $settings;
    }

    /**
     * @param mixed $feature
     */
    public function isFeatureEnabled($feature): bool
    {
        return !empty($this->enabledFeatures[$feature]);
    }

    /**
     * @param mixed $settingCategoryKey
     * @param mixed $settingKey
     * @param mixed $defaultValue
     *
     * @return mixed
     */
    public function getSetting($settingCategoryKey, $settingKey = null, $defaultValue = null)
    {
        if ($settingKey === null) {
            return $this->settings[$settingCategoryKey] ?? $defaultValue;
        }

        if (!isset($this->settings[$settingCategoryKey])) {
            return $defaultValue;
        }

        $category = $this->settings[$settingCategoryKey];
        if (!is_array($category)) {
            return $defaultValue;
        }

        return array_key_exists($settingKey, $category) ? $category[$settingKey] : $defaultValue;
    }
}
