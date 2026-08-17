<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (!function_exists('get_plugins')) {
    function get_plugins(): array
    {
        return [];
    }
}

if (!function_exists('get_plugin_data')) {
    /**
     * @return array<string, mixed>
     */
    function get_plugin_data(string $pluginFile, bool $markup = true, bool $translate = true): array
    {
        return [];
    }
}

if (!function_exists('untrailingslashit')) {
    function untrailingslashit(string $url): string
    {
        return rtrim($url, '/');
    }
}

if (!function_exists('site_url')) {
    function site_url(string $path = '', ?string $scheme = null): string
    {
        return 'https://example.test' . $path;
    }
}

if (!function_exists('plugins_url')) {
    function plugins_url(string $path, string $plugin = ''): string
    {
        return 'https://example.com/wp-content/plugins/ivyforms/' . ltrim($path, '/');
    }
}

if (!function_exists('get_option')) {
    /** @var array<string, mixed> */
    $GLOBALS['melograno_usage_tracker_options'] = [];

    function get_option(string $option, $default = false)
    {
        return array_key_exists($option, $GLOBALS['melograno_usage_tracker_options'])
            ? $GLOBALS['melograno_usage_tracker_options'][$option]
            : $default;
    }

    function update_option(string $option, $value, $autoload = null): bool
    {
        $GLOBALS['melograno_usage_tracker_options'][$option] = $value;

        return true;
    }

    function delete_option(string $option): bool
    {
        unset($GLOBALS['melograno_usage_tracker_options'][$option]);

        return true;
    }

    function metadata_exists(string $meta_type, string $object_id, string $meta_key = ''): bool
    {
        if ($meta_type !== 'option') {
            return false;
        }

        return array_key_exists($object_id, $GLOBALS['melograno_usage_tracker_options']);
    }
}

if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled(string $hook, array $args = []): bool
    {
        return false;
    }

    function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = []): bool
    {
        return true;
    }

    function wp_clear_scheduled_hook(string $hook, array $args = []): int
    {
        return 0;
    }
}

if (!function_exists('plugin_basename')) {
    function plugin_basename(string $file): string
    {
        return $file;
    }
}

if (!function_exists('register_activation_hook')) {
    function register_activation_hook(string $file, callable $callback): void {}
    function register_deactivation_hook(string $file, callable $callback): void {}
    function register_uninstall_hook(string $file, $callback): void {}
}

if (!function_exists('add_action')) {
    /** @var array<string, list<callable>> */
    $GLOBALS['melograno_usage_tracker_actions'] = [];

    function add_action(string $tag, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        $GLOBALS['melograno_usage_tracker_actions'][$tag][] = $callback;

        return true;
    }

    function has_action(string $tag, $callback = false)
    {
        if (!isset($GLOBALS['melograno_usage_tracker_actions'][$tag])) {
            return false;
        }

        if ($callback === false) {
            return true;
        }

        foreach ($GLOBALS['melograno_usage_tracker_actions'][$tag] as $registered) {
            if ($registered === $callback) {
                return true;
            }
        }

        return false;
    }
}
