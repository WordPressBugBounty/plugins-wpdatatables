<?php

/**
 * Boots the Lite chart suggester without the premium PHP-DI container.
 *
 * @package WPDataTables\Plugin
 */

namespace WPDataTables\Plugin;

use WPDataTables\Controllers\Rest\Ai\SuggestChartController;
use WPDataTables\Services\Ai\AiService;
use WPDataTables\Services\Ai\OutputValidator;
use WPDataTables\Services\Ai\PromptBuilder;
use WPDataTables\Services\Ai\TableMetaService;
use WP_REST_Request;
use WP_REST_Server;

defined('ABSPATH') or die('Access denied.');

/**
 * Registers the chart-suggester REST route, admin assets, and wizard panel.
 */
class AiBootstrap
{
    /** REST namespace. Matches premium `Routes::$routeNamespace`. */
    const ROUTE_NAMESPACE = 'wpdatatables/v1';

    /** Admin page hook suffix for the Create Chart wizard. */
    const CHART_WIZARD_HOOK = 'wpdatatables_page_wpdatatables-chart-wizard';

    /** @var AiService|null */
    private static $ai;

    /**
     * Wire hooks once the classic plugin classes are loaded.
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('rest_api_init', array(self::class, 'registerRoutes'));
        add_action('wpdatatables_chart_wizard_ai_suggester', array(self::class, 'renderChartSuggesterSection'));
        add_action('admin_enqueue_scripts', array(self::class, 'enqueueAdmin'));
    }

    /**
     * POST /wpdatatables/v1/ai/suggest-chart
     *
     * @return void
     */
    public static function registerRoutes(): void
    {
        $controller = new SuggestChartController(
            self::ai(),
            new PromptBuilder(),
            new OutputValidator(),
            new TableMetaService()
        );

        register_rest_route(self::ROUTE_NAMESPACE, '/ai/suggest-chart', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => $controller,
            'permission_callback' => array(self::class, 'canUseAi'),
            'args'                => array(
                'table_id'          => array(
                    'type'              => 'integer',
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                ),
                'description'       => array(
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_textarea_field',
                ),
                'available_engines' => array(
                    'type'    => 'array',
                    'default' => array(),
                    'items'   => array(
                        'type' => 'string',
                    ),
                ),
                'model'             => array(
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));
    }

    /**
     * Whether the current request may use the AI endpoints.
     *
     * Default matches the chart wizard: create-charts, or any edit-charts grant.
     * An explicit `wpdatatables/rest/ai_capability` filter value still wins.
     *
     * @param WP_REST_Request|null $request
     * @return bool
     */
    public static function canUseAi($request = null): bool
    {
        $capability = apply_filters('wpdatatables/rest/ai_capability', null);
        if (is_string($capability) && $capability !== '') {
            $allowed = current_user_can($capability);
        } elseif (!function_exists('wdt_permissions_service')) {
            $allowed = current_user_can('manage_options');
        } else {
            $permissions = wdt_permissions_service();
            $allowed     = $permissions->canCreateCharts() || $permissions->canEditChart(null);
        }

        if (!$allowed || !$request instanceof WP_REST_Request) {
            return $allowed;
        }

        $tableId = (int) $request->get_param('table_id');
        if ($tableId <= 0) {
            return $allowed;
        }
        if (!function_exists('wdt_user_can_use_table_as_chart_source')) {
            return false;
        }

        return wdt_user_can_use_table_as_chart_source($tableId);
    }

    /**
     * Render the AI Chart Type Suggester on chart wizard step 1.
     *
     * Always outputs markup: an active form when AI is configured, or an
     * unavailable card with a link to WordPress AI settings.
     *
     * @return void
     */
    public static function renderChartSuggesterSection(): void
    {
        $ai = self::ai();
        $available = $ai->isAvailable();

        $wdt_ai_available           = $available;
        $wdt_ai_models              = $available ? $ai->listTextModels() : array();
        $wdt_ai_default_model       = $available ? $ai->defaultModel() : '';
        $wdt_ai_unavailable_message = $ai->getUnavailableMessage();
        $wdt_ai_settings_url        = $ai->getSettingsUrl();
        $wdt_ai_settings_action     = $ai->getSettingsActionLabel();
        $wdt_ai_tables = class_exists('WPDataTable')
            ? \WPDataTable::getAllTablesExceptSimple()
            : array();
        if (function_exists('wdt_user_can_use_table_as_chart_source')) {
            $wdt_ai_tables = array_values(array_filter(
                (array) $wdt_ai_tables,
                static function ($table) {
                    $tableId = is_array($table) ? (int) ($table['id'] ?? 0) : 0;

                    return wdt_user_can_use_table_as_chart_source($tableId);
                }
            ));
        }

        include WDT_TEMPLATE_PATH . 'admin/chart_wizard/chart_ai_suggester.inc.php';
    }

    /**
     * Enqueue AI scripts on the chart wizard admin page.
     *
     * @param string $hook Current admin page hook suffix.
     * @return void
     */
    public static function enqueueAdmin($hook): void
    {
        if ($hook !== self::CHART_WIZARD_HOOK) {
            return;
        }

        $ai = self::ai();

        wp_enqueue_script(
            'wdt-ai',
            WDT_ROOT_URL . 'assets/js/ai/wdt-ai.js',
            array('jquery'),
            WDT_CURRENT_VERSION,
            true
        );

        $chartSuggesterPath = WDT_ROOT_PATH . 'assets/js/ai/wdt-ai-chart-suggester.js';
        wp_enqueue_script(
            'wdt-ai-chart-suggester',
            WDT_ROOT_URL . 'assets/js/ai/wdt-ai-chart-suggester.js',
            array('jquery', 'wdt-ai', 'wdt-bootstrap-select'),
            file_exists($chartSuggesterPath) ? (string) filemtime($chartSuggesterPath) : WDT_CURRENT_VERSION,
            true
        );

        $available = $ai->isAvailable();

        wp_localize_script('wdt-ai', 'wpAiSettings', array(
            'aiAvailable'       => $available,
            'aiSettingsUrl'     => $ai->getSettingsUrl(),
            'aiSettingsAction'  => $ai->getSettingsActionLabel(),
            'unavailableReason' => $ai->getUnavailableReasonCode(),
            'nonce'             => wp_create_nonce('wp_rest'),
            'restUrl'           => rest_url(self::ROUTE_NAMESPACE . '/ai/'),
            'models'            => $available ? $ai->listTextModels() : array(),
            'defaultModel'      => $available ? $ai->defaultModel() : '',
        ));
    }

    /**
     * @return AiService
     */
    private static function ai(): AiService
    {
        if (self::$ai === null) {
            self::$ai = new AiService();
        }

        return self::$ai;
    }
}
