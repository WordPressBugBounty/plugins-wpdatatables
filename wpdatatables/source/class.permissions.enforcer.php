<?php

defined('ABSPATH') or die('Access denied.');

use WPDataTables\Services\Permissions\PermissionsEnforcer;
use WPDataTables\Services\Permissions\WpdtPermissionsLocator;

/**
 * WDTPermissionsEnforcer
 *
 * Backward-compatibility facade. The implementation lives in
 * {@see PermissionsEnforcer}. Call sites keep using `WDTPermissionsEnforcer::*`.
 */
class WDTPermissionsEnforcer
{
    /**
     * @return PermissionsEnforcer
     */
    private static function enforcer()
    {
        return WpdtPermissionsLocator::enforcer();
    }

    /**
     * Check if current user can view a specific table.
     *
     * @param int $tableId The table ID to check
     * @return bool True if user can view, false otherwise
     */
    public static function canUserViewTable($tableId)
    {
        return self::enforcer()->canUserViewTable($tableId);
    }

    /**
     * Check if current user can view a specific chart.
     *
     * @param int $chartId The chart ID to check
     * @return bool True if user can view, false otherwise
     */
    public static function canUserViewChart($chartId)
    {
        return self::enforcer()->canUserViewChart($chartId);
    }
}
