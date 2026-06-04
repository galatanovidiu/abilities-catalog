<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Support;

use WP_Error;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extracts the `WP_Error` from a failed internal REST response.
 *
 * Abilities wrap core REST routes via `rest_do_request()` and, on failure,
 * return the response's error. `WP_REST_Response::as_error()` is typed
 * `WP_Error|null` — it returns `null` when the response is not an error. Call
 * sites already guard with `is_error()`, but that guard does not narrow the
 * nullable return type, so the `null` branch leaks past an ability's declared
 * `array<string,mixed>|WP_Error` contract.
 *
 * This helper resolves that branch: it returns the response's `WP_Error` when
 * present, or a generic `WP_Error` fallback otherwise, so every failure path
 * returns a real error.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability.
 *
 * @since 0.3.0
 */
final class RestError {

	/**
	 * Returns the error carried by a failed REST response.
	 *
	 * @param \WP_REST_Response $response A response for which `is_error()` is true.
	 * @return \WP_Error The response's error, or a generic fallback if absent.
	 */
	public static function from( WP_REST_Response $response ): WP_Error {
		$error = $response->as_error();

		if ( $error instanceof WP_Error ) {
			return $error;
		}

		return new WP_Error(
			'rest_request_failed',
			__( 'The request could not be completed.', 'abilities-catalog' ),
			array( 'status' => 500 )
		);
	}
}
