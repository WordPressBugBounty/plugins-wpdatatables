<?php

declare(strict_types=1);

if (!function_exists('wdtmcp_detect_integrations')) {
    function wdtmcp_detect_integrations(): array
    {
        return ['folders' => true];
    }
}

if (!function_exists('wdtmcp_detect_tier_from_features')) {
    function wdtmcp_detect_tier_from_features(array $integrations): string
    {
        return 'pro';
    }
}

if (!function_exists('wdtmcp_detect_tier')) {
    function wdtmcp_detect_tier(array $integrations): string
    {
        if (!wdtmcp_is_main_license_active()) {
            return 'free';
        }

        return wdtmcp_detect_tier_from_features($integrations);
    }
}

if (!function_exists('wdtmcp_is_main_license_active')) {
    function wdtmcp_is_main_license_active(): bool
    {
        return $GLOBALS['wdtmcp_test_license_active'] ?? false;
    }
}
