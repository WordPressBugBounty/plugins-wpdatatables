<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace WPDataTables\Services\Permissions;

/**
 * Checks that scoped permission item ids exist in Lite's tables or charts table.
 *
 * Lite does not ship the premium repository layer. This checker is the only
 * persistence dependency {@see AccessRulesValidator} needs.
 *
 * @package WPDataTables\Services\Permissions
 */
class LiteItemIdChecker
{
    /** @var string */
    private $tableSuffix;

    /**
     * @param string $tableSuffix Unprefixed table name, e.g. `wpdatatables`.
     */
    public function __construct(string $tableSuffix)
    {
        $this->tableSuffix = $tableSuffix;
    }

    /**
     * @param array<int> $ids
     * @return list<int>
     */
    public function filterExistingIds(array $ids): array
    {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
        if ($ids === array()) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $table = $wpdb->prefix . $this->tableSuffix;
        $sql = $wpdb->prepare(
            "SELECT id FROM {$table} WHERE id IN ({$placeholders})",
            ...$ids
        );
        $found = $wpdb->get_col($sql);

        return array_map('intval', is_array($found) ? $found : array());
    }
}
