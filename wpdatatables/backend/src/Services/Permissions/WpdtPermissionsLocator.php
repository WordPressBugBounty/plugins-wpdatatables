<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace WPDataTables\Services\Permissions;

use WPDataTables\Controllers\Permissions\PermissionsController;
use WPDataTables\Plugin\Admin\PermissionsPageController;
use WPDataTables\Repository\Permissions\AccessRulesRepository;

/**
 * Builds the permissions object graph without PHP-DI.
 *
 * Lite keeps the legacy bootstrap. This locator is the single place that
 * constructs the access-rules services premium resolves from the container.
 *
 * @package WPDataTables\Services\Permissions
 */
final class WpdtPermissionsLocator
{
    /** @var PermissionsService|null */
    private static $service;

    /** @var PermissionsEnforcer|null */
    private static $enforcer;

    /** @var PermissionsAdminService|null */
    private static $adminService;

    /** @var PermissionsController|null */
    private static $controller;

    /** @var PermissionsPageController|null */
    private static $pageController;

    /** @var AdminMenuPermissionMapper|null */
    private static $menuMapper;

    /**
     * @return PermissionsService
     */
    public static function service(): PermissionsService
    {
        self::boot();

        return self::$service;
    }

    /**
     * @return PermissionsEnforcer
     */
    public static function enforcer(): PermissionsEnforcer
    {
        self::boot();

        return self::$enforcer;
    }

    /**
     * @return PermissionsAdminService
     */
    public static function adminService(): PermissionsAdminService
    {
        self::boot();

        return self::$adminService;
    }

    /**
     * @return PermissionsController
     */
    public static function controller(): PermissionsController
    {
        self::boot();

        return self::$controller;
    }

    /**
     * @return PermissionsPageController
     */
    public static function pageController(): PermissionsPageController
    {
        self::boot();

        return self::$pageController;
    }

    /**
     * @return AdminMenuPermissionMapper
     */
    public static function menuMapper(): AdminMenuPermissionMapper
    {
        self::boot();

        return self::$menuMapper;
    }

    /**
     * @return void
     */
    private static function boot(): void
    {
        if (self::$service instanceof PermissionsService) {
            return;
        }

        $repository = new AccessRulesRepository();
        $validator = new AccessRulesValidator(
            new LiteItemIdChecker('wpdatatables'),
            new LiteItemIdChecker('wpdatacharts')
        );
        $resolver = new PermissionResolver($repository);
        self::$enforcer = new PermissionsEnforcer($resolver);
        self::$service = new PermissionsService(self::$enforcer, $resolver);
        self::$adminService = new PermissionsAdminService($repository, $validator);
        self::$controller = new PermissionsController(self::$adminService);
        self::$pageController = new PermissionsPageController();
        self::$menuMapper = new AdminMenuPermissionMapper(self::$service);
    }
}
