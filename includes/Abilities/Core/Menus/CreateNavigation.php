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
 * Write ability: `og-menus/create-navigation`.
 *
 * Wraps `POST /wp/v2/navigation` via the Abilities REST Adapter to create a
 * block-based navigation menu (`wp_navigation` post). The block content lives in
 * `content` as serialized block markup. The input and output schemas are OVERRIDDEN
 * to the catalog's narrow contract (a subset of create fields in, a flat field set
 * out); {@see shapeInput()} drops empty `title`/`content` and normalizes `status`
 * with `sanitize_key()` before dispatch, and {@see shapeOutput()} flattens the body,
 * coercing the `title` from its `{ raw|rendered }` shape and adding the site-editor
 * `edit_link` via the non-REST {@see get_edit_post_link()} (which the route does not
 * return).
 *
 * The `wp_navigation` post type maps every editing capability to
 * `edit_theme_options`, so the route's `create_item_permissions_check` enforces that
 * cap. Permission delegates to the route's own check (no `require_permission` floor):
 * the route cap equals the catalog cap, so the route stays the authority and its real
 * `WP_Error` reaches the caller instead of the Abilities API collapsing it into a
 * generic permission failure. Write annotations
 * (`readonly:false, destructive:false, idempotent:false`) route the call as POST.
 *
 * @since 0.3.0
 */
final class CreateNavigation implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-menus/create-navigation';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/navigation',
				'method'          => 'POST',
				'label'           => __( 'Create Navigation Menu', 'abilities-catalog' ),
				'description'     => __( 'Creates a new block-based navigation menu. The content is serialized block markup. Created as a draft unless a status is supplied.', 'abilities-catalog' ),
				'category'        => 'og-core-menus',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
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
							'description' => __( 'The navigation menu post status. Created as a draft when omitted.', 'abilities-catalog' ),
						),
					),
					'required'             => array( 'title' ),
					'additionalProperties' => false,
				),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'status' ),
					'properties'           => array(
						'id'        => array(
							'type'        => 'integer',
							'description' => __( 'The new navigation menu ID.', 'abilities-catalog' ),
						),
						'title'     => array(
							'type'        => 'string',
							'description' => __( 'The navigation menu title.', 'abilities-catalog' ),
						),
						'status'    => array(
							'type'        => 'string',
							'description' => __( 'The resulting navigation menu post status.', 'abilities-catalog' ),
						),
						'link'      => array(
							'type'        => 'string',
							'description' => __( 'The navigation menu permalink.', 'abilities-catalog' ),
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
	 * Normalizes the curated input before dispatch.
	 *
	 * Wired as the adapter's `input_callback`, so it runs once per `execute()` after
	 * the ability validates input against its schema. It mirrors the old execute()
	 * shaping: empty `title`/`content` are dropped so the route applies its own
	 * default, and `status` passes through `sanitize_key()` when present. The route
	 * sanitizes again at dispatch; this only normalizes the curated input shape.
	 *
	 * @param array<string,mixed> $params The validated request params.
	 * @return array<string,mixed> The normalized request params.
	 */
	public function shapeInput( array $params ): array {
		foreach ( array( 'title', 'content' ) as $field ) {
			if ( ! isset( $params[ $field ] ) || '' !== $params[ $field ] ) {
				continue;
			}

			unset( $params[ $field ] );
		}

		if ( isset( $params['status'] ) && '' !== $params['status'] ) {
			$params['status'] = sanitize_key( (string) $params['status'] );
		}

		return $params;
	}

	/**
	 * Flattens the REST navigation body to the catalog's field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST `wp_navigation` body. `title` is un-nested from its `{ rendered|raw }`
	 * shape, and `edit_link` is built from the non-REST {@see get_edit_post_link()}
	 * (which the route does not return). `$input` and `$response` are part of the
	 * callback signature but unused — the body carries everything this shape needs.
	 *
	 * @param mixed               $data     The REST navigation body (associative array).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat navigation fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data = is_array( $data ) ? $data : array();
		$id   = (int) ( $data['id'] ?? 0 );

		$title = '';
		if ( isset( $data['title'] ) ) {
			$title = is_array( $data['title'] )
				? (string) ( $data['title']['rendered'] ?? $data['title']['raw'] ?? '' )
				: (string) $data['title'];
		}

		return array(
			'id'        => $id,
			'title'     => $title,
			'status'    => (string) ( $data['status'] ?? '' ),
			'link'      => (string) ( $data['link'] ?? '' ),
			'edit_link' => $id > 0 ? (string) get_edit_post_link( $id, 'raw' ) : '',
		);
	}
}
