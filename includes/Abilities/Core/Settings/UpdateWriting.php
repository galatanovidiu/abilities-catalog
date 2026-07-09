<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Settings;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\BooleanInput;
use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 non-destructive write ability: `og-settings/update-writing`.
 *
 * Updates the Writing Settings screen via `POST /wp/v2/settings`, wrapped through
 * the Abilities REST Adapter. The accepted fields mirror the matching read ability
 * {@see GetWriting}: default category, default post format, and the smilies
 * conversion flag. All three are exposed by the core REST settings registry, so the
 * write goes entirely through one REST dispatch, which sanitizes each value.
 *
 * The input schema is OVERRIDDEN to expose only those three fields; {@see shapeInput()}
 * rejects an empty update and casts each present field before dispatch. The output is
 * OVERRIDDEN to the catalog's flat field set through {@see shapeOutput()}. Permission
 * delegates to the route's own check (`update_items_permissions_check`, which requires
 * `manage_options` — identical to the catalog's old cap), so no `require_permission`
 * floor is set. Note that `use_smilies` is a legacy field: the modern wp-admin Writing
 * Settings screen only renders its control on installs upgraded from before WordPress
 * 4.3, but the option remains writable everywhere.
 *
 * @since 0.3.0
 */
final class UpdateWriting implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-settings/update-writing';
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
				'label'           => __( 'Update Writing Settings', 'abilities-catalog' ),
				'description'     => __( 'Updates Writing Settings: default category, default post format, and the legacy smilies conversion flag (the latter is not shown on the modern wp-admin screen).', 'abilities-catalog' ),
				'category'        => 'og-core-settings',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'default_category'    => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'The default post category term ID. Discover IDs with og-terms/list-terms for the "category" taxonomy.', 'abilities-catalog' ),
						),
						'default_post_format' => array(
							'type'        => 'string',
							'enum'        => array( 'standard', 'aside', 'chat', 'gallery', 'link', 'image', 'quote', 'status', 'video', 'audio' ),
							'description' => __( 'The default post format (e.g. "standard").', 'abilities-catalog' ),
						),
						'use_smilies'         => array(
							'type'        => 'boolean',
							'description' => __( 'Whether to convert text smileys to graphics on display (legacy field, not shown on the modern Writing Settings screen).', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'default_category', 'default_post_format', 'use_smilies' ),
					'properties'           => array(
						'default_category'    => array(
							'type'        => 'integer',
							'description' => __( 'The resulting default post category term ID.', 'abilities-catalog' ),
						),
						'default_post_format' => array(
							'type'        => 'string',
							'description' => __( 'The resulting default post format.', 'abilities-catalog' ),
						),
						'use_smilies'         => array(
							'type'        => 'boolean',
							'description' => __( 'The resulting smilies conversion flag.', 'abilities-catalog' ),
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
					'screen'       => 'options-writing.php',
				),
			)
		);
	}

	/**
	 * Validates and casts the request params before dispatch.
	 *
	 * Wired as the adapter's `input_callback`, so it runs once per `execute()`, at
	 * dispatch, after the ability has validated the input against its schema. It rejects
	 * an update that touches none of the three known fields, then casts each present
	 * field exactly as the old `execute()` did, so REST receives the same sanitized
	 * values. Mutating `$params` here is safe because the callback runs once.
	 *
	 * @param array<string,mixed> $params The validated request params.
	 * @return array<string,mixed>|\WP_Error The params to dispatch, or a WP_Error on an empty update.
	 */
	public function shapeInput( array $params ) {
		$known = array( 'default_category', 'default_post_format', 'use_smilies' );
		if ( array() === array_intersect( $known, array_keys( $params ) ) ) {
			return new \WP_Error(
				'abilities_catalog_no_fields',
				__( 'Provide at least one Writing Setting to update.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		if ( array_key_exists( 'default_category', $params ) ) {
			$params['default_category'] = absint( $params['default_category'] );
		}

		if ( array_key_exists( 'default_post_format', $params ) ) {
			$params['default_post_format'] = (string) $params['default_post_format'];
		}

		if ( array_key_exists( 'use_smilies', $params ) ) {
			$params['use_smilies'] = BooleanInput::sanitize( $params['use_smilies'] );
		}

		return $params;
	}

	/**
	 * Flattens the REST settings body to the catalog's three-field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST settings body. Each field copies across with a type cast; the falsy
	 * `default_post_format` sentinel (a default install stores `0`) normalizes to
	 * `standard`. `$input` and `$response` are part of the callback signature but unused
	 * here — the body carries everything this shape needs.
	 *
	 * @param mixed               $data     The REST settings body (associative array).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat writing-settings fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data = is_array( $data ) ? $data : array();

		$post_format = $data['default_post_format'] ?? '';

		return array(
			'default_category'    => absint( $data['default_category'] ?? 0 ),
			'default_post_format' => (string) ( $post_format ?: 'standard' ),
			'use_smilies'         => (bool) ( $data['use_smilies'] ?? false ),
		);
	}
}
