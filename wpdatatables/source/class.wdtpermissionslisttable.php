<?php

defined('ABSPATH') or die('Access denied.');

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Legacy alias. The permissions screen instantiates `WDTPermissionsListTable`.
 * Implementation: {@see \WPDataTables\Plugin\Admin\ListTables\PermissionsListTable}.
 */
class WDTPermissionsListTable extends \WPDataTables\Plugin\Admin\ListTables\PermissionsListTable
{
}
