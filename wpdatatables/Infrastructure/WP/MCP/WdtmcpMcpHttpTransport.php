<?php

/**
 * HTTP transport used by the wpDataTables MCP server.
 *
 * @package wpDataTables
 */

namespace WDTMCP\Infrastructure\WP\MCP;

use WP\MCP\Transport\HttpTransport;
use WP\MCP\Transport\Infrastructure\McpTransportContext;

defined('ABSPATH') or die('Access denied.');

/**
 * Registers REST routes immediately, matching the timing workaround used by IvyForms.
 */
class WdtmcpMcpHttpTransport extends HttpTransport
{
    public function __construct(McpTransportContext $transportContext)
    {
        parent::__construct($transportContext);

        // A different MCP Adapter copy may not expose route registration at all.
        if (! method_exists($this, 'register_routes')) {
            return;
        }

        // Outside a REST request the parent hook still fires at the right time, and registering
        // routes here would only trigger a _doing_it_wrong notice.
        if (! doing_action('rest_api_init') && ! did_action('rest_api_init')) {
            return;
        }

        remove_action('rest_api_init', array($this, 'register_routes'), 16);

        try {
            $this->register_routes();
        } catch (\Throwable $exception) {
            error_log('wpDataTables MCP: route registration failed - ' . $exception->getMessage());
        }
    }
}
