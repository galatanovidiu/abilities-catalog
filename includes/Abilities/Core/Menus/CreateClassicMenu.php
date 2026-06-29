<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Menus;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 non-destructive write ability: `og-menus/create-classic-menu`.
 *
 * Wraps `POST /wp/v2/menus` via the Abilities REST Adapter to create a classic menu
 * (`nav_menu` term). The menus controller inherits its create permission from the
 * terms controller. Because `nav_menu` is non-hierarchical, the create route checks
 * the taxonomy's `assign_terms` capability; for the `nav_menu` taxonomy every term
 * capability maps to `edit_theme_options`. Permission delegates to the route's own
 * check (no `require_permission` floor is set), so the call requires
 * `edit_theme_options` exactly as the route demands.
 *
 * The input schema is OVERRIDDEN to a closed set (`name`, `description`,
 * `locations`) so raw REST term fields stay hidden; {@see shapeInput()} sanitizes
 * the location slugs before dispatch. The output is OVERRIDDEN to the catalog's flat
 * field set through {@see shapeOutput()}. Write annotations
 * (`readonly:false, destructive:false, idempotent:false`) route the call as POST.
 *
 * @since 0.3.0
 */
final class CreateClassicMenu implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-menus/create-classic-menu';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/menus',
				'method'          => 'POST',
				'label'           => __( 'Create Classic Menu', 'abilities-catalog' ),
				'description'     => __( 'Creates a new classic (nav_menu term) menu. Optionally assigns it to theme locations.', 'abilities-catalog' ),
				'category'        => 'og-core-menus',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'name'        => array(
							'type'        => 'string',
							'description' => __( 'The menu name.', 'abilities-catalog' ),
						),
						'description' => array(
							'type'        => 'string',
							'description' => __( 'The menu description.', 'abilities-catalog' ),
						),
						'locations'   => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Theme location slugs to assign this menu to.', 'abilities-catalog' ),
						),
					),
					'required'             => array( 'name' ),
					'additionalProperties' => false,
				),
				'input_callback'  => array( $this, 'shapeInput' ),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'name' ),
					'properties'           => array(
						'id'        => array(
							'type'        => 'integer',
							'description' => __( 'The new classic menu term ID.', 'abilities-catalog' ),
						),
						'name'      => array(
							'type'        => 'string',
							'description' => __( 'The resulting menu name.', 'abilities-catalog' ),
						),
						'locations' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'The theme locations now assigned to the menu.', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_callback' => array( $this, 'shapeOutput' ),
				'meta'            => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
					'screen'       => 'nav-menus.php',
				),
			)
		);
	}

	/**
	 * Sanitizes the location slugs before the request dispatches.
	 *
	 * Wired as the adapter's `input_callback`, so it runs once after the ability
	 * validates input against its schema and before `rest_do_request()`. Each theme
	 * location slug is normalized with `sanitize_key()`, mirroring the original
	 * execute path; `name` and `description` pass through to the route untouched.
	 *
	 * @param array<string,mixed> $params The validated ability input.
	 * @return array<string,mixed> The params with location slugs sanitized.
	 */
	public function shapeInput( array $params ): array {
		if ( isset( $params['locations'] ) && is_array( $params['locations'] ) ) {
			$params['locations'] = array_map( 'sanitize_key', $params['locations'] );
		}

		return $params;
	}

	/**
	 * Flattens the REST menu body to the catalog's `id`, `name`, `locations` set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST menu body. `locations` is reindexed with `array_values()` so it serializes
	 * as a JSON array. `$input` and `$response` are part of the callback signature but
	 * unused here — the body carries everything this shape needs.
	 *
	 * @param mixed               $data     The REST menu body (associative array).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat menu fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data = is_array( $data ) ? $data : array();

		return array(
			'id'        => (int) ( $data['id'] ?? 0 ),
			'name'      => (string) ( $data['name'] ?? '' ),
			'locations' => isset( $data['locations'] ) && is_array( $data['locations'] ) ? array_values( $data['locations'] ) : array(),
		);
	}
}
