<?php

/**
 * Boots the wpDataTables MCP integration after the loaded adapter is confirmed usable.
 *
 * @package wpDataTables
 */

namespace WDTMCP\Infrastructure\WP\MCP;

use Throwable;
use WP\MCP\Core\McpAdapter;

defined('ABSPATH') or die('Access denied.');

/**
 * Orchestrates notices, compatibility gating, requires, and hook registration.
 *
 * Loaded from the main plugin file before any class that extends WP\MCP\* is required.
 */
class WdtmcpBootstrap
{
    /**
     * Parent classes this build subclasses, mapped to methods we implement.
     *
     * @return array<string, list<string>>
     */
    private static function extendedClasses(): array
    {
        return array(
            'WP\\MCP\\Transport\\HttpTransport' => array('__construct', 'register_routes'),
        );
    }

    /**
     * Plugin files needed once the adapter has passed the compatibility gate.
     *
     * @return list<string>
     */
    private static function integrationFiles(): array
    {
        return array(
            'Infrastructure/WP/MCP/Helpers/McpHelpers/WdtmcpAbilitiesHelper.php',
            'Infrastructure/WP/MCP/Helpers/McpHelpers/WdtmcpLicenseGuideHelper.php',
            'Infrastructure/WP/MCP/WdtmcpAbilitiesRegistrar.php',
            'Infrastructure/WP/MCP/WdtmcpMcpHttpTransport.php',
            'Infrastructure/WP/MCP/WdtmcpMcpServerRegistrar.php',
        );
    }

    /**
     * Load and register the wpDataTables MCP Adapter integration.
     */
    public static function load(): void
    {
        global $wp_version;

        if (version_compare($wp_version, WDTMCP_MIN_WP_VERSION, '<')) {
            self::registerUnavailableNotice(
                sprintf(
                    /* translators: 1: required WordPress version, 2: current WordPress version */
                    __(
                        'MCP integration requires WordPress %1$s or newer. Your site is running WordPress %2$s.',
                        'wpdatatables'
                    ),
                    WDTMCP_MIN_WP_VERSION,
                    $wp_version
                )
            );

            return;
        }

        if (! function_exists('wp_register_ability') || ! class_exists(McpAdapter::class)) {
            $missing = array();

            if (! function_exists('wp_register_ability')) {
                $missing[] = __('WordPress Abilities API', 'wpdatatables');
            }

            if (! class_exists(McpAdapter::class)) {
                $missing[] = __('MCP Adapter', 'wpdatatables');
            }

            self::registerUnavailableNotice(
                sprintf(
                    /* translators: %s: comma-separated list of missing components */
                    __(
                        'MCP integration is unavailable because required components are missing: %s. '
                        . 'Try reinstalling or updating wpDataTables.',
                        'wpdatatables'
                    ),
                    implode(', ', $missing)
                )
            );

            return;
        }

        $incompatibilities = WdtmcpAdapterCompatibility::findProblems(self::extendedClasses());

        if ($incompatibilities) {
            self::registerUnavailableNotice(
                sprintf(
                    /* translators: %s: comma-separated list of reasons the loaded MCP Adapter is unusable */
                    __(
                        'Another plugin loaded an MCP Adapter that wpDataTables cannot build on (%s), '
                        . 'so MCP features stay disabled. Update or deactivate the conflicting plugin to enable them.',
                        'wpdatatables'
                    ),
                    implode('; ', $incompatibilities)
                )
            );

            return;
        }

        foreach (self::integrationFiles() as $relativePath) {
            require_once WDT_ROOT_PATH . $relativePath;
        }

        try {
            McpAdapter::instance();
        } catch (Throwable $exception) {
            self::registerUnavailableNotice(
                sprintf(
                    /* translators: %s: error message reported by the MCP Adapter */
                    __('The MCP Adapter could not be started: %s', 'wpdatatables'),
                    $exception->getMessage()
                )
            );

            return;
        }

        add_action('mcp_adapter_init', array(WdtmcpMcpServerRegistrar::class, 'init'));
        add_action('wp_abilities_api_categories_init', array(WdtmcpAbilitiesRegistrar::class, 'registerCategories'));
        add_action('wp_abilities_api_init', array(WdtmcpAbilitiesRegistrar::class, 'registerAbilities'));

        if (function_exists('wdtmcp_maybe_migrate_css_to_global')) {
            add_action('init', 'wdtmcp_maybe_migrate_css_to_global', 99);
        }
    }

    /**
     * Show an admin notice when MCP integration cannot be loaded.
     *
     * Limited to wpDataTables screens so a broken MCP setup never interrupts admins working on
     * unrelated parts of the site.
     *
     * @param string $message Human-readable reason (already translated).
     */
    private static function registerUnavailableNotice(string $message): void
    {
        if (! is_admin()) {
            return;
        }

        add_action(
            'admin_notices',
            static function () use ($message): void {
                if (! current_user_can('manage_options')) {
                    return;
                }

                $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';

                if (strpos($page, 'wpdatatables') === false) {
                    return;
                }
                ?>
                <div class="notice notice-warning">
                    <p>
                        <strong><?php esc_html_e('wpDataTables MCP:', 'wpdatatables'); ?></strong>
                        <?php echo esc_html($message); ?>
                    </p>
                </div>
                <?php
            }
        );
    }
}
