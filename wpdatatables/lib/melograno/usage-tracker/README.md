# melograno/usage-tracker

Shared WordPress library for anonymous plugin usage telemetry across Melograno products (Amelia, wpDataTables, IvyForms).

## Plugin integration

After Composer autoload is loaded:

**Amelia** (includes upgrade migration when crossing the opt-in release version):

```php
$savedVersion = /* host plugin version stored before upgrade */;
$collector = new \Melograno\UsageTracker\Collectors\Plugin\AmeliaCollector();

\Melograno\UsageTracker\Core\UsageTracker::init($collector, __FILE__, $savedVersion, '1.2.3');
```

**IvyForms** (no upgrade migration — notice state is stored in `ivyforms_show_usage_tracking_notice`):

```php
$collector = new \Melograno\UsageTracker\Collectors\Plugin\IvyFormsCollector();

\Melograno\UsageTracker\Core\UsageTracker::init($collector, __FILE__);
```

When the collector implements `ConsentNoticeCollectorInterface`, `init()` registers a deferred admin notice in `admin_footer`, enable/dismiss AJAX handlers, and calls `renderConsentAdminNotice()` on the collector when the notice should be shown. The notice is injected after the host admin UI has loaded (for SPA pages such as Amelia, it waits until the app root has mounted).

Each product collector defines `getPluginSlug()`, `getConsentOptionName()`, and (for opt-in flows) notice/migration settings via `ConsentNoticeCollectorInterface`. Product-specific banner branding (accent color, icon, labels) is returned from `getConsentNoticePresentation()` and rendered via the shared `resources/usage-tracking-notice.php` template.

Host plugin settings UI should read/write consent via `UsageTracker::getSettings()` / `UsageTracker::updateSettings()`. Enabling consent automatically dismisses the admin notice. When disabling consent from general settings, pass `armNoticeOnDisable` as `false` (default) so the notice is dismissed as a definitive opt-out. Pass `true` only for non-definitive flows such as the welcome wizard.

Pass `$savedVersion` and the current plugin version to `init()` when the collector uses upgrade migration (Amelia). IvyForms does not require version arguments.

New-install consent defaults are applied automatically on boot when the consent option has never been stored (`ConsentNoticeCollectorInterface` collectors only). Once the notice is dismissed or tracking is enabled, the notice option stays `'no'` and is not re-armed on later plugin updates.

On Amelia admin pages that strip third-party notices, the usage notice still renders via `admin_footer` and does not need to be re-registered on `admin_notices`. For manual rendering (e.g. tests), use:

```php
\Melograno\UsageTracker\Core\UsageTracker::renderConsentAdminNotice();
```

### Build-time setup (once per plugin)

1. Add the dependency:

```json
"require": {
  "melograno/usage-tracker": "^1.0"
}
```

2. Optionally include in [Strauss](https://github.com/BrianHenryIE/strauss) `packages` if you need a prefixed copy (`AmeliaVendor\Melograno\UsageTracker\...`). Amelia loads the package **without** Strauss prefixing so `Melograno\UsageTracker` resolves via Composer autoload.

3. Run `composer install` (and Strauss if used) before packaging the plugin.

### Local path repository (development)

```json
"repositories": [
  {
    "type": "path",
    "url": "../plugin-usage-tracker"
  }
]
```

## What the library provides

- Weekly WP Cron send to `https://bi.melograno.io/v1/usage` (override with `MELOGRANO_BI_GATE_URL`)
- Per-plugin consent `wp_option` (name from collector; host UI writes it; library reads it for cron/send)
- `NoticeManager` for admin notice option state; auto-dismiss when consent is enabled
- `ConsentNoticeService` for upgrade migration and new-install consent defaults
- `ConsentNoticeCollectorInterface::getConsentNoticePresentation()` for per-product banner branding
- `ConsentNoticeCollectorInterface::renderConsentAdminNotice()` for host-branded admin notice UI
- Common collectors (WP environment, activation stats)
- Product collectors: `AmeliaCollector`, `WpDataTablesCollector`, `IvyFormsCollector`
- `sha256` site identifier (no raw site URL in payload)
- Deactivation (when `__FILE__` passed to `init`): clears scheduled cron only; consent option is kept
- Uninstall: `UsageTracker::deleteStoredOptions()` removes consent and notice options

## Development

```bash
composer install
composer test
```

## Payload schema

```json
{
  "schema_version": 1,
  "sent_at": "2026-05-19T12:00:00Z",
  "plugin": "ameliabooking",
  "site_id": "<sha256 of site_url>",
  "common": { "environment": {}, "activation": {} },
  "plugin_data": {}
}
```

For `wpdatatables`, `plugin_data` may include:

- `tables_count` / `charts_count` — total content counts
- `table_types` — map of `table_type` → count (e.g. `{"simple":4,"mysql":3}`)
- `chart_types` — map of chart `type` → count (e.g. `{"google_pie_chart":2}`)
- `license`, `plugin_version`, `first_content_created_at`
