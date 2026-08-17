<?php

declare(strict_types=1);

namespace AmeliaBooking\Infrastructure\Licence;

class LicenceConstants
{
    public const LITE = 'Lite';
}

class Licence
{
    /** @var string|null */
    private static $licence = 'Lite';

    /** @var bool */
    private static $isPremium = false;

    public static function reset(string $licence = 'Lite', bool $isPremium = false): void
    {
        self::$licence = $licence;
        self::$isPremium = $isPremium;
    }

    /**
     * @return string|null
     */
    public static function getLicence()
    {
        return self::$licence;
    }

    public static function isPremium(): bool
    {
        return self::$isPremium;
    }
}
