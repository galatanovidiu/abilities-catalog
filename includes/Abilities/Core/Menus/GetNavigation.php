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
 * Read ability: `og-menus/get-navigation`.
 *
 * Wraps `GET /wp/v2/navigation/<id>` via the Abilities REST Adapter and shapes the
 * response into a flat field set. The block-based navigation menu stores its items
 * as serialized blocks inside `content`; {@see shapeOutput()} returns that raw
 * serialized markup (not rendered HTML) so it matches the field's documented contract
 * and round-trips into `og-menus/update-navigation`, the same shape
 * `og-templates/get-template` and `og-templates/get-pattern` return. The raw form is an
 * edit-context REST field, so {@see shapeInput()} defaults the request to `edit` context
 * (a property-level schema default does not reach the route); `view` returns the
 * rendered HTML instead. Permission delegates to the route's own
 * check (no `require_permission` floor): the `wp_navigation` post type maps its edit
 * caps to `edit_theme_options`, and the route's edit-context check enforces exactly
 * that, surfacing the specific `rest_forbidden_context` (401/403) instead of the
 * Abilities API collapsing it into a generic permission failure. Read-only.
 *
 * @since 0.1.0
 */
final class GetNavigation implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-menus/get-navigation';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/navigation/(?P<id>[\d]+)',
				'method'          => 'GET',
				'label'           => __( 'Get Navigation Menu', 'abilities-catalog' ),
				'description'     => __( 'Returns a single block-based navigation menu by ID, including its serialized block content.', 'abilities-catalog' ),
				'category'        => 'og-core-menus',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'      => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'The navigation menu ID. Discover IDs with `og-menus/list-navigation`.', 'abilities-catalog' ),
						),
						'context' => array(
							'type'        => 'string',
							'enum'        => array( 'view', 'edit' ),
							'default'     => 'edit',
							'description' => __( 'Scope of the request. Defaults to "edit", which returns the raw serialized block markup for `content` (round-trippable into update-navigation); "view" returns the rendered HTML instead.', 'abilities-catalog' ),
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'id' ),
					'properties'           => array(
						'id'        => array(
							'type'        => 'integer',
							'description' => __( 'The navigation menu ID.', 'abilities-catalog' ),
						),
						'title'     => array(
							'type'        => 'string',
							'description' => __( 'The navigation menu title.', 'abilities-catalog' ),
						),
						'content'   => array(
							'type'        => 'string',
							'description' => __( 'The serialized block content of the menu.', 'abilities-catalog' ),
						),
						'status'    => array(
							'type'        => 'string',
							'description' => __( 'The navigation menu post status.', 'abilities-catalog' ),
						),
						'date'      => array(
							'type'        => 'string',
							'description' => __( 'The publish date in site time.', 'abilities-catalog' ),
						),
						'modified'  => array(
							'type'        => 'string',
							'description' => __( 'The last-modified date in site time.', 'abilities-catalog' ),
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
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Defaults the request `context` to `edit`.
	 *
	 * Wired as the adapter's `input_callback`, it runs after input validation. The raw
	 * serialized block markup is an edit-context REST field, so when the caller omits
	 * `context` this pins it to `edit` (mirroring the original `$input['context'] ?? 'edit'`).
	 * A property-level schema default does not reach the route, so the default must be
	 * supplied here; `view` stays selectable and returns the rendered HTML instead.
	 *
	 * @param array<string,mixed> $params The validated ability input.
	 * @return array<string,mixed> The params with `context` guaranteed set.
	 */
	public function shapeInput( array $params ): array {
		$params['context'] = $params['context'] ?? 'edit';

		return $params;
	}

	/**
	 * Reshapes the REST navigation body to the catalog's flat field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST navigation body. The route exposes `title` and `content` as `raw`/`rendered`
	 * objects; {@see coerceField()} collapses each to a single string (preferring the
	 * stored `raw` form). `edit_link` is built from the non-REST {@see get_edit_post_link()}
	 * (which the route does not return). `$response` is part of the callback signature
	 * but unused — the body carries everything this shape needs.
	 *
	 * @param mixed               $data     The REST navigation body (associative array).
	 * @param array<string,mixed> $input    The original ability input.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat navigation fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data        = is_array( $data ) ? $data : array();
		$resolved_id = (int) ( $data['id'] ?? absint( $input['id'] ?? 0 ) );

		return array(
			'id'        => $resolved_id,
			'title'     => $this->coerceField( $data['title'] ?? '' ),
			'content'   => $this->coerceField( $data['content'] ?? '' ),
			'status'    => (string) ( $data['status'] ?? '' ),
			'date'      => (string) ( $data['date'] ?? '' ),
			'modified'  => (string) ( $data['modified'] ?? '' ),
			'edit_link' => $resolved_id > 0 ? (string) get_edit_post_link( $resolved_id, 'raw' ) : '',
		);
	}

	/**
	 * Coerces a `wp_navigation` field that may be a string or a
	 * `raw`/`rendered` object into a single string.
	 *
	 * Prefers the `raw` (stored) form over `rendered`, matching
	 * `og-templates/get-template` and `og-templates/get-pattern`: for `content` the `raw`
	 * form is the serialized block markup, for `title` the stored title. `raw` is an
	 * edit-context field (this ability's default context); `rendered` is the
	 * fallback used under the `view` context.
	 *
	 * @param mixed $value The field value from the REST response.
	 * @return string The string representation of the field.
	 */
	private function coerceField( $value ): string {
		if ( is_array( $value ) ) {
			return (string) ( $value['raw'] ?? $value['rendered'] ?? '' );
		}

		return (string) $value;
	}
}
