<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Themes;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-themes/get-active-theme`.
 *
 * Wraps the `GET /wp/v2/themes` collection via the Abilities REST Adapter. The
 * {@see shapeInput()} input callback pins `status` to `active`, so the route returns
 * only the active theme; the adapter wraps that list as a `{ items, ... }` envelope,
 * and {@see shapeOutput()} flattens `items[0]` into the catalog's closed field set.
 * When the route returns no item, the shape falls back to the non-REST
 * {@see wp_get_theme()} (replicated in {@see fromCore()}). The input and output
 * schemas are OVERRIDDEN to the catalog's narrow contract (no-input in, a single
 * flat object out).
 *
 * Permission delegates to the route's own check (no `require_permission` floor): with
 * `status=active`, the themes route's `get_items_permissions_check` allows any user
 * who can `switch_themes`/`manage_network_themes`, or — via
 * `check_read_active_theme_permission` — any user who can `edit_posts` (or edit a
 * REST-enabled post type), surfacing the specific `rest_cannot_view_active_theme` 401
 * instead of the Abilities API collapsing the denial into a generic permission
 * failure. This widens the previous catalog floor (`switch_themes` /
 * `edit_theme_options`) to the route's `edit_posts`-level read, matching the Tier 1
 * read precedent of delegating to the route.
 *
 * @since 0.1.0
 */
final class GetActiveTheme implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-themes/get-active-theme';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/themes',
				'method'          => 'GET',
				'label'           => __( 'Get Active Theme', 'abilities-catalog' ),
				'description'     => __( 'Returns the currently active theme, including its stylesheet, name, version, and author.', 'abilities-catalog' ),
				'category'        => 'og-core-themes',
				'input_schema'    => array(),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'stylesheet', 'name' ),
					'properties'           => array(
						'stylesheet'     => array(
							'type'        => 'string',
							'description' => __( 'The active theme directory name (stylesheet).', 'abilities-catalog' ),
						),
						'template'       => array(
							'type'        => 'string',
							'description' => __( 'The template directory name (the parent theme for a child theme).', 'abilities-catalog' ),
						),
						'name'           => array(
							'type'        => 'string',
							'description' => __( 'The theme display name.', 'abilities-catalog' ),
						),
						'version'        => array(
							'type'        => 'string',
							'description' => __( 'The theme version.', 'abilities-catalog' ),
						),
						'status'         => array(
							'type'        => 'string',
							'enum'        => array( 'active' ),
							'description' => __( 'The theme status; always "active" for this ability.', 'abilities-catalog' ),
						),
						'is_block_theme' => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the theme is a block theme.', 'abilities-catalog' ),
						),
						'author'         => array(
							'type'        => 'string',
							'description' => __( 'The theme author.', 'abilities-catalog' ),
						),
						'theme_uri'      => array(
							'type'        => 'string',
							'description' => __( 'The theme home page URL.', 'abilities-catalog' ),
						),
						'description'    => array(
							'type'        => 'string',
							'description' => __( 'The theme description.', 'abilities-catalog' ),
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
	 * Pins the themes-collection request to the active theme.
	 *
	 * Wired as the adapter's `input_callback`; runs once at dispatch, as the current
	 * user. It injects `status=active` so the route returns only the active theme.
	 *
	 * @param array<string,mixed> $params The ability input (always empty for this ability).
	 * @return array<string,mixed> The params with `status` pinned to `active`.
	 */
	public function shapeInput( array $params ): array {
		$params['status'] = 'active';

		return $params;
	}

	/**
	 * Flattens the active theme from the collection envelope to the catalog's shape.
	 *
	 * Wired as the adapter's `output_callback`; runs only on success, over the
	 * `{ items, total, total_pages }` envelope. It takes the first item and flattens
	 * the themes route's `{ rendered: ... }` fields; when `items` is empty it falls
	 * back to the non-REST {@see wp_get_theme()} via {@see fromCore()}. `$input` and
	 * `$response` are part of the callback signature but unused.
	 *
	 * @param mixed               $data     The collection envelope (associative array).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat active-theme fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data  = is_array( $data ) ? $data : array();
		$items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();
		$item  = isset( $items[0] ) && is_array( $items[0] ) ? $items[0] : null;

		if ( null === $item ) {
			return $this->fromCore();
		}

		return array(
			'stylesheet'     => (string) ( $item['stylesheet'] ?? '' ),
			'template'       => (string) ( $item['template'] ?? '' ),
			'name'           => $this->renderedField( $item['name'] ?? '' ),
			'version'        => (string) ( $item['version'] ?? '' ),
			'status'         => (string) ( $item['status'] ?? 'active' ),
			'is_block_theme' => (bool) ( $item['is_block_theme'] ?? false ),
			'author'         => $this->renderedField( $item['author'] ?? '' ),
			'theme_uri'      => $this->renderedField( $item['theme_uri'] ?? '' ),
			'description'    => $this->renderedField( $item['description'] ?? '' ),
		);
	}

	/**
	 * Builds the field set from core when REST does not return the active theme.
	 *
	 * @return array<string,mixed> Flat active-theme fields.
	 */
	private function fromCore(): array {
		$theme = wp_get_theme();

		return array(
			'stylesheet'     => (string) $theme->get_stylesheet(),
			'template'       => (string) $theme->get_template(),
			'name'           => (string) $theme->get( 'Name' ),
			'version'        => (string) $theme->get( 'Version' ),
			'status'         => 'active',
			'is_block_theme' => $theme->is_block_theme(),
			'author'         => (string) $theme->get( 'Author' ),
			'theme_uri'      => (string) $theme->get( 'ThemeURI' ),
			'description'    => (string) $theme->get( 'Description' ),
		);
	}

	/**
	 * Resolves a themes-route field that may be a `['rendered' => string]` object.
	 *
	 * @param mixed $field The raw field value from the REST item.
	 * @return string The rendered string value.
	 */
	private function renderedField( $field ): string {
		if ( is_array( $field ) ) {
			return (string) ( $field['rendered'] ?? '' );
		}

		return (string) $field;
	}
}
