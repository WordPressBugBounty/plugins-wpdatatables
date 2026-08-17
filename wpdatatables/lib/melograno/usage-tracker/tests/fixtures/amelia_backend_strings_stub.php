<?php

declare(strict_types=1);

namespace AmeliaBooking\Infrastructure\WP\Translations;

class BackendStrings
{
    /** @var array<string, string> */
    private const STRINGS = [
        'improve_amelia' => 'Improve Amelia',
        'usage_tracking_description' => 'Help us improve Amelia by sharing anonymous data about your plugin usage.',
        'improve_plugin' => 'Improve plugin',
        'learn_more' => 'Learn more',
    ];

    public static function get(string $key): string
    {
        return self::STRINGS[$key] ?? $key;
    }
}
