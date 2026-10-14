<?php

declare(strict_types=1);

namespace Melograno\UsageTracker\Collectors\Plugin;

/**
 * Ivy Forms feature telemetry: integrations, configured features, and template usage.
 */
final class IvyFormsFeatureTelemetry
{
    /** @var array<string, string> settings/integrationSettings slug => telemetry key */
    private const INTEGRATION_KEYS = [
        'wpdatatables' => 'wpdatatables',
        'ameliabooking' => 'ameliabooking',
        'google_sheets' => 'google_sheets',
        'mailchimp' => 'mailchimp',
        'webhooks' => 'webhooks',
        'zapier' => 'zapier',
        'public-api' => 'public_api',
        'public_api' => 'public_api',
        'salesforce' => 'salesforce',
    ];

    /** Integrations that report feature_metrics.usage_count (distinct forms). */
    private const COUNT_METRIC_KEYS = [
        'webhooks',
        'google_sheets',
    ];

    /**
     * Real DB `type` values this plugin can store, from FieldType.php::BASE_ALLOWED_TYPES
     * (27 Lite types) + Essentials fieldConfig() (4) + Growth fieldConfig() (2). Acts as an
     * allowlist — unrecognized/removed types are silently skipped.
     *
     * @var list<string>
     */
    private const FIELD_TYPES = [
        // Lite (base)
        'email', 'text', 'textarea', 'number', 'slider', 'phone', 'website', 'recaptcha',
        'turnstile', 'hcaptcha', 'html', 'name', 'radio', 'checkbox', 'gdpr', 'address',
        'select', 'multi-select', 'time', 'date', 'rating', 'file-upload', 'product',
        'quantity', 'total', 'section', 'page',
        // Pro — Essentials
        'date_time', 'password', 'signature', 'rich_text',
        // Pro — Growth
        'likert', 'nps',
    ];

    /**
     * Table-existence probe results for the current collection, table name => exists.
     *
     * @var array<string, bool>
     */
    private static $tableExists = [];

    /**
     * @return array{
     *     features: array<string, bool>,
     *     feature_metrics: array<string, array{usage_count: int}>,
     *     templates: array<string, int>,
     *     themes: array<string, int>,
     *     fields: array<string, bool>
     * }
     */
    public static function collect(): array
    {
        self::$tableExists = [];

        $settings = self::readSettings();
        $integrationResult = self::collectIntegrations($settings);
        $styling = self::collectStyling();
        $multiRow = self::collectMultiRowMetrics();
        $configuredFeatures = self::collectConfiguredFeatures($settings);
        $zeroRow = self::collectZeroRowMetrics();

        $features = array_merge(
            $integrationResult['features'],
            $configuredFeatures,
            $styling['features'],
            $multiRow['features'],
            $zeroRow['features']
        );

        return [
            'features' => $features,
            'feature_metrics' => array_merge(
                $integrationResult['feature_metrics'],
                $multiRow['feature_metrics'],
                $zeroRow['feature_metrics']
            ),
            'templates' => self::collectTemplates(),
            'themes' => $styling['themes'],
            'fields' => self::usedFieldTypeKeysMap(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function readSettings(): array
    {
        $raw = get_option('ivyforms_settings');
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{
     *     features: array<string, bool>,
     *     feature_metrics: array<string, array{usage_count: int}>
     * }
     */
    private static function collectIntegrations(array $settings): array
    {
        $integrations = $settings['integrations'] ?? [];
        if (!is_array($integrations)) {
            $integrations = [];
        }

        $formUsage = self::formIntegrationUsage();
        $formCounts = self::integrationFormCounts();
        $partnerUsage = self::partnerPluginUsage();

        $features = [];
        $featureMetrics = [];
        $telemetryKeys = array_unique(array_values(self::INTEGRATION_KEYS));

        foreach ($telemetryKeys as $telemetryKey) {
            $config = self::integrationConfig($integrations, $telemetryKey);
            $globallyEnabled = is_array($config) && !empty($config['enabled']);
            if (!$globallyEnabled) {
                continue;
            }

            $inUse = self::isIntegrationInUse(
                $telemetryKey,
                $settings,
                $formUsage,
                $formCounts,
                $partnerUsage
            );
            $features[$telemetryKey] = $inUse;

            if (in_array($telemetryKey, self::COUNT_METRIC_KEYS, true)) {
                $count = (int) ($formCounts[$telemetryKey] ?? 0);
                if ($count > 0) {
                    $featureMetrics[$telemetryKey] = ['usage_count' => $count];
                }
            }
        }

        return [
            'features' => $features,
            'feature_metrics' => $featureMetrics,
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, bool> $formUsage
     * @param array<string, int> $formCounts
     * @param array<string, bool> $partnerUsage
     */
    private static function isIntegrationInUse(
        string $telemetryKey,
        array $settings,
        array $formUsage,
        array $formCounts,
        array $partnerUsage
    ): bool {
        switch ($telemetryKey) {
            case 'wpdatatables':
            case 'ameliabooking':
                return !empty($partnerUsage[$telemetryKey]);
            case 'mailchimp':
                return !empty($formUsage['mailchimp']);
            case 'webhooks':
            case 'google_sheets':
                return ((int) ($formCounts[$telemetryKey] ?? 0)) > 0;
            case 'public_api':
                return self::publicApiKeysExist($settings);
            case 'zapier':
                return !empty($formUsage['zapier']) || !empty($partnerUsage['zapier']);
            case 'salesforce':
                return !empty($formUsage['salesforce']);
            default:
                return false;
        }
    }

    /**
     * @param array<string, mixed> $settings
     */
    private static function publicApiKeysExist(array $settings): bool
    {
        $developer = $settings['developer'] ?? [];
        if (!is_array($developer)) {
            return false;
        }

        $keys = $developer['public_api_keys'] ?? [];

        return is_array($keys) && $keys !== [];
    }

    /**
     * @param array<string, mixed> $integrations
     * @return array<string, mixed>|null
     */
    private static function integrationConfig(array $integrations, string $telemetryKey): ?array
    {
        $candidates = [$telemetryKey];
        if ($telemetryKey === 'public_api') {
            $candidates[] = 'public-api';
        }

        foreach ($candidates as $key) {
            if (isset($integrations[$key]) && is_array($integrations[$key])) {
                return $integrations[$key];
            }
        }

        return null;
    }

    /**
     * Form-level usage from ivyforms_forms.integrationSettings.
     * Mailchimp: non-empty listId. Zapier/Salesforce: enabled flag.
     *
     * @return array<string, bool>
     */
    private static function formIntegrationUsage(): array
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_col')) {
            return [];
        }

        $table = $wpdb->prefix . 'ivyforms_forms';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_col("SELECT integrationSettings FROM `{$table}`");
        if (!is_array($rows)) {
            return [];
        }

        $usage = [];
        foreach ($rows as $raw) {
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                continue;
            }

            $mailchimp = $decoded['mailchimp'] ?? null;
            if (is_array($mailchimp)) {
                $listId = isset($mailchimp['listId']) ? trim((string) $mailchimp['listId']) : '';
                if ($listId !== '') {
                    $usage['mailchimp'] = true;
                }
            }

            foreach (['zapier', 'salesforce'] as $slug) {
                $config = $decoded[$slug] ?? null;
                if (is_array($config) && !empty($config['enabled'])) {
                    $usage[$slug] = true;
                }
            }
        }

        return $usage;
    }

    /**
     * Distinct forms with at least one enabled webhook / Google Sheet row.
     *
     * @return array<string, int>
     */
    private static function integrationFormCounts(): array
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
            return [];
        }

        $counts = [];
        $prefix = $wpdb->prefix . 'ivyforms_';

        // Both tables are Pro-only and absent on lite installs.
        foreach (['google_sheets', 'webhooks'] as $key) {
            $counts[$key] = 0;

            $table = $prefix . $key;
            if (!self::tableExists($table)) {
                continue;
            }

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $counts[$key] = (int) $wpdb->get_var(
                "SELECT COUNT(DISTINCT form_id) FROM `{$table}` WHERE enabled = 1"
            );
            self::logQueryError("{$key} form count");
        }

        return $counts;
    }

    /**
     * Partner-plugin and Zapier subscriber signals.
     *
     * @return array<string, bool>
     */
    private static function partnerPluginUsage(): array
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
            return [];
        }

        $usage = [];
        $prefix = $wpdb->prefix;

        // Partner / Pro tables, absent unless the other plugin is installed.
        $probes = [
            'wpdatatables' => [$prefix . 'wpdatatables', "table_type = 'ivyforms'"],
            'ameliabooking' => [$prefix . 'amelia_customer_bookings', 'ivyEntryId IS NOT NULL'],
            'ameliabooking_packages' => [$prefix . 'amelia_packages_to_customers', 'ivyEntryId IS NOT NULL'],
            'zapier' => [$prefix . 'ivyforms_zapier_subscribers', ''],
        ];

        foreach ($probes as $key => [$table, $where]) {
            if (!self::tableExists($table)) {
                continue;
            }

            $sql = "SELECT COUNT(*) FROM `{$table}`" . ($where === '' ? '' : " WHERE {$where}");
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $count = (int) $wpdb->get_var($sql);
            self::logQueryError("{$key} partner usage count");

            if ($count > 0) {
                $usage[$key === 'ameliabooking_packages' ? 'ameliabooking' : $key] = true;
            }
        }

        return $usage;
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, bool>
     */
    private static function collectConfiguredFeatures(array $settings): array
    {
        $features = [];

        if (self::hasConversationalForms()) {
            $features['conversational'] = true;
        }

        if (self::hasMultipageForms()) {
            $features['multipage'] = true;
        }

        if (self::hasSmartLogic()) {
            $features['smart_logic'] = true;
        }

        if (self::hasCustomPermissions()) {
            $features['permissions'] = true;
        }

        $security = $settings['security'] ?? [];
        if (is_array($security)) {
            if (self::isCaptchaConfigured($security['recaptcha'] ?? null)) {
                $features['recaptcha'] = true;
            }
            if (self::isCaptchaConfigured($security['turnstile'] ?? null)) {
                $features['turnstile'] = true;
            }
            if (self::isCaptchaConfigured($security['hcaptcha'] ?? null)) {
                $features['hcaptcha'] = true;
            }
        }

        $general = $settings['general'] ?? [];
        if (is_array($general)) {
            if (!empty($general['mcp_enabled'])) {
                $features['mcp'] = true;
            }
            if (!empty($general['wcagBackend'])) {
                $features['wcag_backend'] = true;
            }
        }

        return $features;
    }

    private static function hasConversationalForms(): bool
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
            return false;
        }

        $table = $wpdb->prefix . 'ivyforms_forms';
        $count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}` WHERE formType = 'conversational'"
        );

        return $count > 0;
    }

    private static function hasMultipageForms(): bool
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_col')) {
            return false;
        }

        $table = $wpdb->prefix . 'ivyforms_forms';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_col("SELECT settings FROM `{$table}`");
        if (!is_array($rows)) {
            return false;
        }

        foreach ($rows as $raw) {
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                continue;
            }
            $pages = $decoded['pages'] ?? null;
            if (is_array($pages) && count($pages) > 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, bool>
     */
    private static function usedFieldTypeKeysMap(): array
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_col')) {
            return [];
        }

        $table = $wpdb->prefix . 'ivyforms_fields';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $types = $wpdb->get_col("SELECT DISTINCT type FROM `{$table}` WHERE type IS NOT NULL AND type <> ''");
        if (!is_array($types)) {
            return [];
        }

        $keys = [];
        foreach ($types as $type) {
            if (is_string($type) && in_array($type, self::FIELD_TYPES, true)) {
                $keys[str_replace('-', '_', $type)] = true;
            }
        }

        return $keys;
    }

    /**
     * Lightweight styling-customization signals: non-default theme, custom CSS classes,
     * advanced style mode. Every form gets a fully-populated default `styleSettings` JSON,
     * so "non-empty" is not a customization signal on its own.
     *
     * @return array{features: array<string, bool>, themes: array<string, int>}
     */
    private static function collectStyling(): array
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_col')) {
            return ['features' => [], 'themes' => []];
        }

        $table = $wpdb->prefix . 'ivyforms_forms';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_col("SELECT styleSettings FROM `{$table}`");
        if (!is_array($rows)) {
            return ['features' => [], 'themes' => []];
        }

        $features = [];
        $themes = [];
        foreach ($rows as $raw) {
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                continue;
            }

            $theme = $decoded['selectedTheme'] ?? null;
            if (is_string($theme) && $theme !== '' && $theme !== 'ivy-default') {
                $themes[$theme] = ($themes[$theme] ?? 0) + 1;
                $features['custom_styling'] = true;
            }

            $cssClass = trim((string) ($decoded['customCssClass'] ?? ''));
            $additionalCss = trim((string) ($decoded['additionalCssClasses'] ?? ''));
            if ($cssClass !== '' || $additionalCss !== '') {
                $features['custom_css'] = true;
                $features['custom_styling'] = true;
            }

            if (($decoded['styleMode'] ?? null) === 'advanced') {
                $features['advanced_style_mode'] = true;
                $features['custom_styling'] = true;
            }
        }

        return ['features' => $features, 'themes' => $themes];
    }

    private static function hasCustomPermissions(): bool
    {
        $option = get_option('ivyforms_access_rules');
        if (!is_array($option)) {
            return false;
        }

        $rules = $option['rules'] ?? null;

        return is_array($rules) && $rules !== [];
    }

    /**
     * Smart logic (conditional triggering) is stored identically — via the shared
     * Sanitizer::sanitizeNotificationSmartLogic() normalizer — on notifications and
     * confirmations (Lite) and on Google Sheets and Webhooks (Pro), always as
     * `{"enabled":<bool>,"match":...,"rules":[...]}` with "enabled" first. The disabled/
     * default state is never an empty string, so we match on the "enabled":true marker
     * rather than excluding blank values.
     *
     * @var array<string, string> table suffix => column name
     */
    private const SMART_LOGIC_TABLES = [
        'notifications' => 'smartLogic',
        'confirmations' => 'smartLogic',
        'google_sheets' => 'smart_logic',
        'webhooks' => 'smart_logic',
    ];

    private static function hasSmartLogic(): bool
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
            return false;
        }

        $prefix = $wpdb->prefix . 'ivyforms_';
        foreach (self::SMART_LOGIC_TABLES as $suffix => $column) {
            $table = $prefix . $suffix;
            // Google Sheets / Webhooks are Pro-only, so a missing table must not abort the loop.
            if (!self::tableExists($table)) {
                continue;
            }

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $count = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` LIKE '%\"enabled\":true%'"
            );
            self::logQueryError("{$suffix} smart logic probe");

            if ($count > 0) {
                return true;
            }
        }

        return false;
    }

    private static function multiRowFormCount(string $tableSuffix): int
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
            return 0;
        }

        $table = $wpdb->prefix . 'ivyforms_' . $tableSuffix;

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM (
                SELECT formId FROM `{$table}`
                GROUP BY formId
                HAVING COUNT(*) > 1
             ) AS multi"
        );
    }

    /**
     * 2+ confirmations/notifications per form, reported both as a presence flag and as a
     * feature_metrics usage_count (forms with 2+ rows), mirroring webhooks/google_sheets.
     *
     * @return array{features: array<string, bool>, feature_metrics: array<string, array{usage_count: int}>}
     */
    private static function collectMultiRowMetrics(): array
    {
        $features = [];
        $metrics = [];

        foreach (['confirmations' => 'confirmations', 'notifications' => 'notifications'] as $suffix => $key) {
            $count = self::multiRowFormCount($suffix);
            if ($count > 0) {
                $features[$key] = true;
                $metrics[$key] = ['usage_count' => $count];
            }
        }

        return ['features' => $features, 'feature_metrics' => $metrics];
    }

    private static function zeroRowFormCount(string $tableSuffix): int
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
            return 0;
        }

        $formsTable = $wpdb->prefix . 'ivyforms_forms';
        $table = $wpdb->prefix . 'ivyforms_' . $tableSuffix;

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$formsTable}` f
             LEFT JOIN `{$table}` t ON t.formId = f.id
             WHERE t.id IS NULL"
        );
    }

    /**
     * Forms whose default confirmation/notification was deleted (0 rows — every form gets
     * exactly one of each on creation, see FormCreateService), reported both as a presence
     * flag and as a feature_metrics usage_count, mirroring the 2+ (multi-row) case.
     *
     * @return array{features: array<string, bool>, feature_metrics: array<string, array{usage_count: int}>}
     */
    private static function collectZeroRowMetrics(): array
    {
        $features = [];
        $metrics = [];

        foreach (['confirmations' => 'zero_confirmations', 'notifications' => 'zero_notifications'] as $suffix => $key) {
            $count = self::zeroRowFormCount($suffix);
            if ($count > 0) {
                $features[$key] = true;
                $metrics[$key] = ['usage_count' => $count];
            }
        }

        return ['features' => $features, 'feature_metrics' => $metrics];
    }

    /**
     * @param mixed $config
     */
    private static function isCaptchaConfigured($config): bool
    {
        if (!is_array($config)) {
            return false;
        }

        $siteKey = isset($config['siteKey']) ? trim((string) $config['siteKey']) : '';
        $secretKey = isset($config['secretKey']) ? trim((string) $config['secretKey']) : '';

        return $siteKey !== '' && $secretKey !== '';
    }

    /**
     * Whether $table exists, cached for the current collection.
     *
     * wpdb::get_var() does not throw when a query fails: it returns null, sets
     * $wpdb->last_error and routes the MySQL error through wpdb::print_error(), which
     * writes to error_log and echoes when WP_DEBUG_DISPLAY is on. A missing Pro or
     * partner table therefore has to be ruled out up front — try/catch cannot see it.
     */
    private static function tableExists(string $table): bool
    {
        if (isset(self::$tableExists[$table])) {
            return self::$tableExists[$table];
        }

        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
            return false;
        }

        // `_` is a LIKE wildcard, so the table name has to be escaped when possible.
        if (method_exists($wpdb, 'prepare') && method_exists($wpdb, 'esc_like')) {
            $query = $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table));
        } else {
            $query = "SHOW TABLES LIKE '" . str_replace(['\\', "'"], '', $table) . "'";
        }

        $found = $wpdb->get_var($query);

        return self::$tableExists[$table] = is_string($found) && $found !== '';
    }

    /**
     * Reports a failed probe that got past the table-existence gate. Debug-only: a
     * telemetry probe must never add noise to a production error log.
     */
    private static function logQueryError(string $context): void
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        global $wpdb;

        if (empty($wpdb->last_error)) {
            return;
        }

        error_log('[melograno/usage-tracker] ivyForms ' . $context . ' failed: ' . $wpdb->last_error);
    }

    /**
     * @return array<string, int>
     */
    private static function collectTemplates(): array
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
            return [];
        }

        $table = $wpdb->prefix . 'ivyforms_forms';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            "SELECT templateId, COUNT(*) AS cnt
             FROM `{$table}`
             WHERE templateId IS NOT NULL AND templateId <> ''
             GROUP BY templateId",
            defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A'
        );

        if (!is_array($rows)) {
            return [];
        }

        $templates = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = isset($row['templateId']) ? trim((string) $row['templateId']) : '';
            if ($id === '' || strlen($id) > 128) {
                continue;
            }
            $templates[$id] = (int) ($row['cnt'] ?? 0);
        }

        return $templates;
    }
}
