<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Support;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rebuilds a REST error so no credential-sensitive input can reach the caller.
 *
 * The Abilities run controller forwards an ability's `WP_Error` straight to the
 * client without applying the output schema, so a wrapped REST controller's error
 * — whose message or `data.params`/`data.details` may reflect submitted input —
 * would otherwise be echoed verbatim to the in-browser AI agent (and the model).
 * For abilities that carry a secret (user password, application-password
 * plaintext, connector API key), that is a credential-leak path.
 *
 * This helper returns a new error that preserves only the original error CODE and
 * the HTTP status, replacing the message with a generic one and dropping all other
 * data (params, details). The code is a safe category label (e.g.
 * `rest_invalid_param`) and never contains a submitted value.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability.
 *
 * @since 0.2.0
 */
final class SecretSafeError {

	/**
	 * Returns a redacted copy of a REST error, safe to return from a
	 * secret-bearing ability.
	 *
	 * @param \WP_Error $error The original error from the wrapped REST request.
	 * @return \WP_Error A new error with the original code and status, a generic
	 *                  message, and no other data.
	 */
	public static function redact( WP_Error $error ): WP_Error {
		$code   = $error->get_error_code();
		$code   = is_string( $code ) && '' !== $code ? $code : 'webmcp_write_failed';
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;

		return new WP_Error(
			$code,
			__( 'The request could not be completed. Details are withheld because this operation handles sensitive data.', 'abilities-catalog' ),
			array( 'status' => $status )
		);
	}
}
