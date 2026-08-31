<?php

/**
 * Detects whether the loaded MCP Adapter copy can back the wpDataTables integration.
 *
 * @package wpDataTables
 */

namespace WDTMCP\Infrastructure\WP\MCP;

use ReflectionClass;
use ReflectionMethod;
use Throwable;

defined('ABSPATH') or die('Access denied.');

/**
 * Reflection-only checks against WP\MCP classes. Safe to load before any wpDataTables
 * subclass of those classes is required.
 */
class WdtmcpAdapterCompatibility
{
    /**
     * Directory the given MCP Adapter class was loaded from.
     *
     * The PSR-4 prefix is stripped from the file path so copies with a different folder layout
     * still resolve to a comparable root.
     *
     * @param string $className Fully qualified class name inside the WP\MCP namespace.
     * @return string Root directory, or an empty string when it cannot be determined.
     */
    public static function classRoot(string $className): string
    {
        try {
            $file = (new ReflectionClass($className))->getFileName();
        } catch (Throwable $exception) {
            return '';
        }

        if (! is_string($file) || '' === $file) {
            return '';
        }

        $relative = str_replace('\\', '/', substr($className, strlen('WP\\MCP\\'))) . '.php';
        $file     = str_replace('\\', '/', $file);
        $position = strrpos($file, $relative);

        return false === $position ? '' : substr($file, 0, $position);
    }

    /**
     * Collect the reasons the loaded MCP Adapter cannot run the wpDataTables integration.
     *
     * Any plugin may load its own MCP Adapter build before wpDataTables does, so the copy that
     * ends up running can be a different version, a namespace-prefixed build, or a mix of two
     * copies resolved by competing autoloaders. Extending such a copy is a fatal error, so the
     * integration stays off whenever the classes it builds on are missing, final, or still
     * abstract.
     *
     * @param array<string, list<string>> $extendedClasses Map of parent FQCN => methods our subclass implements.
     * @return list<string> Human-readable problems; empty when the loaded copy is usable.
     */
    public static function findProblems(array $extendedClasses): array
    {
        $requiredClasses = array_merge(
            array(
                'WP\\MCP\\Core\\McpAdapter',
                'WP\\MCP\\Transport\\Infrastructure\\McpTransportContext',
            ),
            array_keys($extendedClasses)
        );

        $problems = array();
        $roots    = array();

        foreach ($requiredClasses as $className) {
            if (! class_exists($className)) {
                /* translators: %s: fully qualified class name */
                $problems[] = sprintf(__('missing class %s', 'wpdatatables'), $className);
                continue;
            }

            $root = self::classRoot($className);

            if ('' !== $root) {
                $roots[ $root ] = true;
            }

            if (! isset($extendedClasses[ $className ])) {
                continue;
            }

            $reflection = new ReflectionClass($className);

            if ($reflection->isFinal()) {
                /* translators: %s: fully qualified class name */
                $problems[] = sprintf(__('class %s cannot be extended', 'wpdatatables'), $className);
                continue;
            }

            foreach ($reflection->getMethods(ReflectionMethod::IS_ABSTRACT) as $method) {
                if (! in_array($method->getName(), $extendedClasses[ $className ], true)) {
                    $problems[] = sprintf(
                        /* translators: 1: fully qualified class name, 2: method name */
                        __('class %1$s expects an unsupported %2$s() method', 'wpdatatables'),
                        $className,
                        $method->getName()
                    );
                }
            }
        }

        if (
            class_exists('WP\\MCP\\Core\\McpAdapter')
            && ! method_exists('WP\\MCP\\Core\\McpAdapter', 'create_server')
        ) {
            $problems[] = __('missing method McpAdapter::create_server()', 'wpdatatables');
        }

        // Several plugins bundling the same adapter version is normal and works, so the copies in
        // use are reported only as context for a problem that was already found.
        if ($problems && count($roots) > 1) {
            $problems[] = sprintf(
                /* translators: %s: comma-separated list of directories */
                __('classes come from more than one MCP Adapter copy (%s)', 'wpdatatables'),
                implode(', ', array_keys($roots))
            );
        }

        return $problems;
    }
}
