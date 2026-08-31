<?php

/**
 * Registers the wpDataTables MCP server.
 *
 * @package wpDataTables
 */

namespace WDTMCP\Infrastructure\WP\MCP;

defined('ABSPATH') or die('Access denied.');

class WdtmcpMcpServerRegistrar
{
    private const SERVER_ID = 'wpdatatables-mcp-server';

    private const ABILITY_IDS = array(
        'wpdatatables/update-table-settings',
        'wpdatatables/edit-table',
        'wpdatatables/list-media',
        'wpdatatables/list-tables',
        'wpdatatables/get-table-info',
        'wpdatatables/get-table-data',
        'wpdatatables/list-charts',
        'wpdatatables/get-chart-info',
        'wpdatatables/get-system-info',
        'wpdatatables/get-mcp-license-matrix',
        'wpdatatables/get-changelog',
        'wpdatatables/create-simple-table',
        'wpdatatables/update-simple-table-styles',
        'wpdatatables/upload-media-from-url',
        'wpdatatables/update-global-settings',
        'wpdatatables/create-chart',
        'wpdatatables/edit-chart',
    );

    /**
     * @param mixed $adapter The adapter instance.
     */
    public static function init($adapter): void
    {
        if (! is_object($adapter) || ! method_exists($adapter, 'create_server')) {
            self::reportFailure(
                __('the loaded MCP Adapter does not provide create_server().', 'wpdatatables'),
                'adapter unusable'
            );

            return;
        }

        // Another copy of the integration - Lite running next to Pro, or a second init pass -
        // already registered the server, so leave it alone instead of triggering a duplicate error.
        if (method_exists($adapter, 'get_server') && null !== $adapter->get_server(self::SERVER_ID)) {
            return;
        }

        try {
            $result = self::createServer($adapter);
        } catch (\Throwable $exception) {
            // A foreign MCP Adapter copy can expose a different create_server() signature or
            // internals; report the mismatch instead of taking the whole request down.
            self::reportFailure($exception->getMessage(), 'create_server failed');

            return;
        }

        if ($result instanceof \WP_Error) {
            self::reportFailure($result->get_error_message(), 'create_server failed');
        }
    }

    /**
     * Register the wpDataTables MCP server on the given adapter.
     *
     * @param object $adapter The adapter instance.
     * @return mixed Adapter return value, typically the adapter itself or a WP_Error.
     */
    private static function createServer(object $adapter)
    {
        $description = 'Exposes wpDataTables functionality as MCP tools for data discovery, '
            . 'table creation, configuration, media, and charts.';

        return $adapter->create_server(
            self::SERVER_ID,
            'mcp',
            self::SERVER_ID,
            'wpDataTables MCP Server',
            $description,
            defined('WDT_CURRENT_VERSION') ? 'v' . WDT_CURRENT_VERSION : '1.0.0',
            array(WdtmcpMcpHttpTransport::class),
            null,
            null,
            self::ABILITY_IDS,
            array(),
            array(),
            static function () {
                if (is_user_logged_in()) {
                    return current_user_can('manage_options');
                }

                $username = (string) (filter_input(INPUT_SERVER, 'PHP_AUTH_USER', FILTER_UNSAFE_RAW) ?? '');
                $password = (string) (filter_input(INPUT_SERVER, 'PHP_AUTH_PW', FILTER_UNSAFE_RAW) ?? '');
                $username = $username !== '' ? wp_unslash($username) : '';
                $password = $password !== '' ? wp_unslash($password) : '';

                if (! $username || ! $password || ! function_exists('wp_authenticate_application_password')) {
                    return false;
                }

                $user = wp_authenticate_application_password(null, $username, $password);

                return $user instanceof \WP_User && user_can($user, 'manage_options');
            }
        );
    }

    /**
     * Log the reason the MCP server could not be registered and show it to administrators.
     *
     * The notice is limited to wpDataTables screens, so an MCP conflict never shows up while an
     * admin is editing unrelated content.
     *
     * @param string $reason Human-readable reason.
     * @param string $stage  Short label for where registration failed.
     */
    private static function reportFailure(string $reason, string $stage = 'server registration failed'): void
    {
        $message = 'wpDataTables MCP: ' . $stage . ' - ' . $reason;

        error_log($message);

        if (! is_admin() || ! current_user_can('manage_options')) {
            return;
        }

        $page = (string) (filter_input(INPUT_GET, 'page', FILTER_UNSAFE_RAW) ?? '');

        if (strpos($page, 'wpdatatables') === false) {
            return;
        }

        add_action(
            'admin_notices',
            static function () use ($message): void {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($message) . '</p></div>';
            }
        );
    }
}
