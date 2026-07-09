<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Settings;

use DateTimeZone;
use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;
use WP_Error;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 non-destructive write ability: `og-settings/update-general`.
 *
 * Updates a field-gated subset of the General Settings screen via
 * `POST /wp/v2/settings`, wrapped through the Abilities REST Adapter. The accepted
 * fields are exactly those the matching read ability {@see GetGeneral} exposes that
 * are safe to change from this tool: site title, tagline, timezone, date and time
 * formats, week start, and language.
 *
 * The input schema is OVERRIDDEN to the catalog's narrow allow-list; {@see shapeInput()}
 * rejects a forbidden key and an unrecognized timezone or uninstalled locale before
 * dispatch, then casts each present field so REST receives the same values the old
 * `execute()` sent. The output is OVERRIDDEN to a flat field set through
 * {@see shapeOutput()}, which reads the active locale back via the non-REST
 * {@see get_locale()} (the settings route does not return it). Permission delegates to
 * the route's own check (`update_items_permissions_check`, which requires
 * `manage_options` — identical to the catalog's old cap), so no `require_permission`
 * floor is set.
 *
 * Security: the site URL (`url`/`home`/`siteurl`), the admin email
 * (`email`/`admin_email`), and the WordPress install URL are deliberately excluded.
 * If the input names any of those keys, the ability writes nothing and returns an
 * `abilities_catalog_field_forbidden` error. Changing the site URL or admin email
 * through an automated tool can lock the administrator out of the site.
 *
 * @since 0.3.0
 */
final class UpdateGeneral implements Ability {

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
		return 'og-settings/update-general';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/settings',
				'method'          => 'POST',
				'label'           => __( 'Update General Settings', 'abilities-catalog' ),
				'description'     => __( 'Updates General Settings: site title, tagline, timezone, date and time formats, week start, and language. The language can only be switched to an already-installed locale. Changing the site URL or admin email is not permitted through this tool.', 'abilities-catalog' ),
				'category'        => 'og-core-settings',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'title'         => array(
							'type'        => 'string',
							'description' => __( 'The site title. Send it as the "title" field (it is stored in the blogname option); do not send a "blogname" key.', 'abilities-catalog' ),
						),
						'description'   => array(
							'type'        => 'string',
							'description' => __( 'The site tagline. Send it as the "description" field (it is stored in the blogdescription option); do not send a "blogdescription" key.', 'abilities-catalog' ),
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
							'minimum'     => 0,
							'maximum'     => 6,
							'description' => __( 'The day the week starts on (0 = Sunday, 6 = Saturday).', 'abilities-catalog' ),
						),
						'language'      => array(
							'type'        => 'string',
							'description' => __( 'The site locale (e.g. "en_US"); empty string for English.', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'   => array(
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
							'description' => __( 'The resulting active site locale (e.g. "en_US").', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'input_callback'  => array( $this, 'shapeInput' ),
				'output_callback' => array( $this, 'shapeOutput' ),
				'meta'            => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
					'screen'       => 'options-general.php',
				),
			)
		);
	}

	/**
	 * Validates and casts the request params before dispatch.
	 *
	 * Wired as the adapter's `input_callback`, so it runs once per `execute()`, at
	 * dispatch, after the ability has validated the input against its schema. It keeps
	 * the catalog's defense-in-depth guards from the old `execute()`: a forbidden key
	 * (site URL or admin email) rejects the whole call, an unrecognized timezone and an
	 * uninstalled locale each reject before any write. It then casts each present field
	 * exactly as the old `execute()` did, so REST receives the same values. Mutating
	 * `$params` here is safe because the callback runs once.
	 *
	 * @param array<string,mixed> $params The validated request params.
	 * @return array<string,mixed>|\WP_Error The params to dispatch, or a WP_Error on a forbidden key, bad timezone, or uninstalled locale.
	 */
	public function shapeInput( array $params ) {
		foreach ( self::FORBIDDEN_KEYS as $forbidden ) {
			if ( array_key_exists( $forbidden, $params ) ) {
				return new WP_Error(
					'abilities_catalog_field_forbidden',
					__( 'Changing the site URL or admin email is not permitted through this tool.', 'abilities-catalog' ),
					array( 'status' => 400 )
				);
			}
		}

		if ( array_key_exists( 'timezone', $params ) ) {
			$timezone = (string) $params['timezone'];

			if ( '' !== $timezone && ! in_array( $timezone, timezone_identifiers_list( DateTimeZone::ALL_WITH_BC ), true ) ) {
				return new WP_Error(
					'abilities_catalog_invalid_timezone',
					__( 'The timezone is not a recognized timezone identifier (e.g. "Europe/Berlin").', 'abilities-catalog' ),
					array( 'status' => 400 )
				);
			}
		}

		if ( array_key_exists( 'language', $params ) ) {
			$language = (string) $params['language'];

			if ( '' !== $language && ! in_array( $language, get_available_languages(), true ) ) {
				return new WP_Error(
					'abilities_catalog_invalid_language',
					__( 'The locale is not installed. Install the language pack before switching to it; an empty string selects English.', 'abilities-catalog' ),
					array( 'status' => 400 )
				);
			}
		}

		foreach ( array( 'title', 'description', 'timezone', 'date_format', 'time_format', 'language' ) as $field ) {
			if ( ! array_key_exists( $field, $params ) ) {
				continue;
			}

			$params[ $field ] = (string) $params[ $field ];
		}

		if ( array_key_exists( 'start_of_week', $params ) ) {
			$params['start_of_week'] = absint( $params['start_of_week'] );
		}

		return $params;
	}

	/**
	 * Flattens the REST settings body to the catalog's field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST settings body. Each field copies across with a type cast; `language` reads
	 * the active locale back via the non-REST {@see get_locale()} (the settings route
	 * does not return it), reporting the post-write state. `$input` and `$response` are
	 * part of the callback signature but unused — the body and live state carry
	 * everything this shape needs.
	 *
	 * @param mixed               $data     The REST settings body (associative array).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat general-settings fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data = is_array( $data ) ? $data : array();

		return array(
			'title'         => (string) ( $data['title'] ?? '' ),
			'description'   => (string) ( $data['description'] ?? '' ),
			'timezone'      => (string) ( $data['timezone'] ?? '' ),
			'date_format'   => (string) ( $data['date_format'] ?? '' ),
			'time_format'   => (string) ( $data['time_format'] ?? '' ),
			'start_of_week' => absint( $data['start_of_week'] ?? 0 ),
			'language'      => (string) get_locale(),
		);
	}
}
