<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace WPDataTables\Controllers;

use Exception;
use RuntimeException;
use WPDataTables\Common\Exceptions\InvalidArgumentException;
use WPDataTables\Common\Exceptions\NotFoundException;
use WPDataTables\Common\Exceptions\ServiceUnavailableException;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST controller envelope for the Lite AI chart suggester.
 *
 * `__invoke()` wraps {@see handle()} and maps typed exceptions to HTTP status
 * codes. Lite does not ship the premium PHP-DI REST stack, so this copy only
 * knows the exceptions the chart suggester raises.
 *
 * @package WPDataTables\Controllers
 */
abstract class Controller
{
    /**
     * @param WP_REST_Request $data
     * @return WP_REST_Response
     */
    public function __invoke(WP_REST_Request $data): WP_REST_Response
    {
        try {
            $response = $this->handle($data);

            if ($response->get_status() !== 200) {
                throw new RuntimeException('Unexpected HTTP status: ' . $response->get_status());
            }

            return new WP_REST_Response([
                'message' => 'ok',
                'data'    => $response->get_data(),
            ], 200);
        } catch (NotFoundException $e) {
            return self::error($e, 404);
        } catch (InvalidArgumentException $e) {
            return self::error($e, 400);
        } catch (ServiceUnavailableException $e) {
            return self::error($e, 503);
        } catch (Exception $e) {
            return self::error($e, 500, 'Unexpected error occurred: ');
        }
    }

    /**
     * Build a structured error response and log it when WP_DEBUG is on.
     *
     * @param Exception $e
     * @param int       $status
     * @param string    $prefix
     * @return WP_REST_Response
     */
    private static function error(Exception $e, int $status, string $prefix = ''): WP_REST_Response
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('wpDataTables error ' . $status . ': ' . $e->getMessage());
        }

        return new WP_REST_Response([
            'message' => $prefix . $e->getMessage(),
            'data'    => [],
        ], $status);
    }

    /**
     * Concrete controllers implement the actual request handling here.
     *
     * @param WP_REST_Request $data
     * @return WP_REST_Response
     */
    abstract protected function handle(WP_REST_Request $data): WP_REST_Response;
}
