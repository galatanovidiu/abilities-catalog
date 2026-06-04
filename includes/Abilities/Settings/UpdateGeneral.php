<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Settings;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 non-destructive write ability: `settings/update-general`.
 *
 * Updates a field-gated subset of the General Settings screen via
 * `POST /wp/v2/settings`. The accepted fields are exactly those the matching
 * read ability {@see GetGeneral} exposes that are safe to change from this tool:
 * site title, tagline, timezone, date and time formats, week start, and language.
 *
 * Security: the site URL (`url`/`home`/`siteurl`), the admin email
 * (`email`/`admin_email`), and the WordPress install URL are deliberately
 * excluded. If the input names any of those keys, the ability writes nothing and
 * returns a `webmcp_field_forbidden` error. Changing the site URL or admin email
 * through an automated tool can lock the administrator out of the site.
 *
 * @since 0.3.0
 */
final class UpdateGeneral implements Ability {

	/**
	 * REST settings parameters this ability is allowed to write.
	 *
	 * Keys are the REST setting names; values are the underlying option keys
	 * (for documentation only — the REST route maps them).
	 *
	 * @var array<string,string>
	 */
	private const ALLOWED_PARAMS = array(
		'title'         => 'blogname',
		'description'   => 'blogdescription',
		'timezone'      => 'timezone_string',
		'date_format'   => 'date_format',
		'time_format'   => 'time_format',
		'start_of_week' => 'start_of_week',
		'language'      => 'WPLANG',
	);

	/**
	 * Input keys that are never allowed; their presence rejects the whole call.
	 *
	 * Covers the REST names and the raw option names for the site URL and the
	 * administration email, to prevent administrator lock-out.
	 *
	 * @var string[]
	 */
	private const FORBIDDEN_KEYS = array( 'url', 'home', 'siteurl', 'email', 'admin_email' );

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'settings/update-general';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Update General Settings', 'abilities-catalog' ),
			'description'         => __( 'Updates General Settings: site title, tagline, timezone, date and time formats, week start, and language. Changing the site URL or admin email is not permitted through this tool.', 'abilities-catalog' ),
			'category'            => 'settings',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'title'         => array(
						'type'        => 'string',
						'description' => __( 'The site title (blogname).', 'abilities-catalog' ),
					),
					'description'   => array(
						'type'        => 'string',
						'description' => __( 'The site tagline (blogdescription).', 'abilities-catalog' ),
					),
					'timezone'      => array(
						'type'        => 'string',
						'description' => __( 'The timezone string (e.g. "Europe/Berlin").', 'abilities-catalog' ),
					),
					'date_format'   => array(
						'type'        => 'string',
						'description' => __( 'The PHP date format string.', 'abilities-catalog' ),
					),
					'time_format'   => array(
						'type'        => 'string',
						'description' => __( 'The PHP time format string.', 'abilities-catalog' ),
					),
					'start_of_week' => array(
						'type'        => 'integer',
						'description' => __( 'The day the week starts on (0 = Sunday, 6 = Saturday).', 'abilities-catalog' ),
					),
					'language'      => array(
						'type'        => 'string',
						'description' => __( 'The site locale (e.g. "en_US"); empty string for English.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'title' ),
				'properties'           => array(
					'title'         => array(
						'type'        => 'string',
						'description' => __( 'The resulting site title.', 'abilities-catalog' ),
					),
					'description'   => array(
						'type'        => 'string',
						'description' => __( 'The resulting site tagline.', 'abilities-catalog' ),
					),
					'timezone'      => array(
						'type'        => 'string',
						'description' => __( 'The resulting timezone string.', 'abilities-catalog' ),
					),
					'date_format'   => array(
						'type'        => 'string',
						'description' => __( 'The resulting date format string.', 'abilities-catalog' ),
					),
					'time_format'   => array(
						'type'        => 'string',
						'description' => __( 'The resulting time format string.', 'abilities-catalog' ),
					),
					'start_of_week' => array(
						'type'        => 'integer',
						'description' => __( 'The resulting week start day.', 'abilities-catalog' ),
					),
					'language'      => array(
						'type'        => 'string',
						'description' => __( 'The resulting site locale.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'screen'       => 'options-general.php',
			),
		);
	}

	/**
	 * Permission check: the current user may manage options.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user can manage options.
	 */
	public function hasPermission( $input = null ): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Executes the ability by writing the allowed General Settings via REST.
	 *
	 * Rejects the call when the input names a forbidden key (site URL or admin
	 * email), writing nothing. Otherwise dispatches `POST /wp/v2/settings` with
	 * only the allow-listed parameters and reads the values back.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The resulting general settings, or a WP_Error.
	 */
	public function execute( $input = null ) {
		$input = is_array( $input ) ? $input : array();

		foreach ( self::FORBIDDEN_KEYS as $forbidden ) {
			if ( array_key_exists( $forbidden, $input ) ) {
				return new WP_Error(
					'webmcp_field_forbidden',
					__( 'Changing the site URL or admin email is not permitted through this tool.', 'abilities-catalog' ),
					array( 'status' => 400 )
				);
			}
		}

		$request = new WP_REST_Request( 'POST', '/wp/v2/settings' );

		foreach ( array( 'title', 'description', 'timezone', 'date_format', 'time_format', 'language' ) as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			$request->set_param( $field, (string) $input[ $field ] );
		}

		if ( array_key_exists( 'start_of_week', $input ) ) {
			$request->set_param( 'start_of_week', absint( $input['start_of_week'] ) );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return $response->as_error();
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return array(
			'title'         => (string) ( $data['title'] ?? '' ),
			'description'   => (string) ( $data['description'] ?? '' ),
			'timezone'      => (string) ( $data['timezone'] ?? '' ),
			'date_format'   => (string) ( $data['date_format'] ?? '' ),
			'time_format'   => (string) ( $data['time_format'] ?? '' ),
			'start_of_week' => absint( $data['start_of_week'] ?? 0 ),
			'language'      => (string) ( $data['language'] ?? '' ),
		);
	}
}
