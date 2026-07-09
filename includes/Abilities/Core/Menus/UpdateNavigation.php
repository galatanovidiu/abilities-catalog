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
 * T2 non-destructive write ability: `og-menus/update-navigation`.
 *
 * Wraps `POST /wp/v2/navigation/<id>` via the Abilities REST Adapter to update a
 * block-based navigation menu (`wp_navigation` post). The input and output schemas
 * are OVERRIDDEN to the catalog's narrow contract; {@see shapeInput()} drops an
 * empty `status` (which core would otherwise reject) so it is not forwarded, and
 * {@see shapeOutput()} flattens the rendered `title` and adds the site-editor
 * `edit_link` via the non-REST {@see get_edit_post_link()}.
 *
 * Permission delegates to the route's own check (no `require_permission` floor):
 * the posts controller's `update_item_permissions_check` enforces the object-level
 * `edit_post` capability (which `map_meta_cap` resolves to `edit_theme_options` for
 * `wp_navigation`), surfacing the specific `rest_cannot_edit` 403 / `rest_post_invalid_id`
 * 404 instead of the Abilities API collapsing it into a generic permission failure.
 * Write annotations (`readonly:false, destructive:false, idempotent:false`) route the
 * call as POST.
 *
 * @since 0.3.0
 */
final class UpdateNavigation implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-menus/update-navigation';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/navigation/(?P<id>[\d]+)',
				'method'          => 'POST',
				'label'           => __( 'Update Navigation Menu', 'abilities-catalog' ),
				'description'     => __( 'Updates an existing block-based navigation menu by ID. Only the supplied fields change.', 'abilities-catalog' ),
				'category'        => 'og-core-menus',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'      => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'The navigation menu ID to update. Discover IDs with `og-menus/list-navigation`.', 'abilities-catalog' ),
						),
						'title'   => array(
							'type'        => 'string',
							'description' => __( 'The navigation menu title.', 'abilities-catalog' ),
						),
						'content' => array(
							'type'        => 'string',
							'description' => __( 'The serialized block markup for the menu items.', 'abilities-catalog' ),
						),
						'status'  => array(
							'type'        => 'string',
							'enum'        => array( 'draft', 'pending', 'private', 'publish' ),
							'description' => __( 'The navigation menu post status.', 'abilities-catalog' ),
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'status' ),
					'properties'           => array(
						'id'        => array(
							'type'        => 'integer',
							'description' => __( 'The navigation menu ID.', 'abilities-catalog' ),
						),
						'title'     => array(
							'type'        => 'string',
							'description' => __( 'The resulting navigation menu title.', 'abilities-catalog' ),
						),
						'status'    => array(
							'type'        => 'string',
							'description' => __( 'The resulting navigation menu post status.', 'abilities-catalog' ),
						),
						'edit_link' => array(
							'type'        => 'string',
							'description' => __( 'The site-editor URL for editing the navigation menu.', 'abilities-catalog' ),
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
				),
			)
		);
	}

	/**
	 * Drops an empty `status` before dispatch.
	 *
	 * Wired as the adapter's `input_callback`; runs once at dispatch as the current
	 * user. The route rejects an empty `status` (it is not a valid post status), but
	 * the schema's enum lets a caller omit it entirely. `title` and `content` are
	 * forwarded untouched — including `''`, so a caller can clear the title or empty
	 * the menu markup, which core writes as-is on update. `id` fills the route's
	 * `(?P<id>[\d]+)` capture.
	 *
	 * @param array<string,mixed> $params The validated request params.
	 * @return array<string,mixed> The params to dispatch.
	 */
	public function shapeInput( array $params ): array {
		if ( isset( $params['status'] ) && '' === $params['status'] ) {
			unset( $params['status'] );
		}

		return $params;
	}

	/**
	 * Reshapes the updated REST navigation body to the catalog's flat field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success. `title`
	 * is un-nested from its `{ rendered, raw }` shape, and `edit_link` is built from
	 * the non-REST {@see get_edit_post_link()} (the site-editor URL, which the route
	 * does not return). `$response` is part of the callback signature but unused.
	 *
	 * @param mixed               $data     The REST navigation body (associative array).
	 * @param array<string,mixed> $input    The original ability input.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat navigation fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data        = is_array( $data ) ? $data : array();
		$resolved_id = (int) ( $data['id'] ?? absint( $input['id'] ?? 0 ) );

		$title = '';
		if ( isset( $data['title'] ) ) {
			$title = is_array( $data['title'] )
				? (string) ( $data['title']['rendered'] ?? $data['title']['raw'] ?? '' )
				: (string) $data['title'];
		}

		return array(
			'id'        => $resolved_id,
			'title'     => $title,
			'status'    => (string) ( $data['status'] ?? '' ),
			'edit_link' => $resolved_id > 0 ? (string) get_edit_post_link( $resolved_id, 'raw' ) : '',
		);
	}
}
