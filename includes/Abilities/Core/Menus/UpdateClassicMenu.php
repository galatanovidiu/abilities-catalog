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
 * T2 non-destructive write ability: `og-menus/update-classic-menu`.
 *
 * Wraps `POST /wp/v2/menus/<id>` via the Abilities REST Adapter to update a classic
 * menu (`nav_menu` term). The input schema is OVERRIDDEN to the catalog's flat field
 * set (`id` required, plus `name`/`description`/`locations`); `id` is the path capture,
 * passed straight through, and the route owns the rest — its `locations` field replaces
 * the whole assigned set, so forwarding `[]` clears all locations. The output is
 * OVERRIDDEN to the catalog's three-field set via {@see shapeOutput()}. Permission
 * delegates to the wrapped route, whose `edit_term` check on the `nav_menu` taxonomy
 * maps to `edit_theme_options`; no `require_permission` floor is set, so it stays the
 * route's own coarse check (never stricter). Write annotations
 * (`destructive:false, idempotent:false`) route the call as POST.
 *
 * @since 0.3.0
 */
final class UpdateClassicMenu implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-menus/update-classic-menu';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/menus/(?P<id>[\d]+)',
				'method'          => 'POST',
				'label'           => __( 'Update Classic Menu', 'abilities-catalog' ),
				'description'     => __( 'Updates an existing classic (nav_menu term) menu by ID. Only the supplied fields change.', 'abilities-catalog' ),
				'category'        => 'og-core-menus',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'          => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'The classic menu term ID to update. Use og-menus/list-classic-menus to discover valid menu IDs.', 'abilities-catalog' ),
						),
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
							'description' => __( 'Theme location slugs to assign this menu to. This replaces the menu\'s entire set of assigned locations: any location omitted here is cleared. Use og-menus/list-menu-locations to discover valid location slugs.', 'abilities-catalog' ),
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'name' ),
					'properties'           => array(
						'id'        => array(
							'type'        => 'integer',
							'description' => __( 'The classic menu term ID.', 'abilities-catalog' ),
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
	 * Flattens the REST menu body to the catalog's three-field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST menu body. `id`/`name` copy across with a type cast; `locations` is
	 * re-indexed so it serializes as a JSON array (an empty set stays `[]`, the signal
	 * that all locations were cleared). `$input` and `$response` are part of the
	 * callback signature but unused here — the body carries everything this shape needs.
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
