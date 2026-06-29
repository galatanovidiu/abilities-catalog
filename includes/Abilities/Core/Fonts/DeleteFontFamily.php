<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Fonts;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 destructive write ability: `og-fonts/delete-font-family`.
 *
 * Wraps `DELETE /wp/v2/font-families/<id>` via the Abilities REST Adapter,
 * permanently deleting an installed `wp_font_family` post and its font-face assets.
 * The caller never passes `force`; {@see injectForce()} pins `force=true` after
 * input validation so the route deletes instead of trashing. Permission delegates
 * to the route's own `delete_item_permissions_check`, which maps `delete_post` to
 * `edit_theme_options` for the `wp_font_family` post type — the same baseline the
 * catalog declared, so no `require_permission` floor is set and access does not
 * widen. The output is OVERRIDDEN to the catalog's flat field set through
 * {@see shapeOutput()}, read entirely from the `{ deleted, previous }` response
 * body (no pre-dispatch state capture needed).
 *
 * Destructive: registered, but exposed to the browser only when both the write
 * and destructive adapter settings are on. Capability remains the hard guard.
 *
 * @since 0.4.0
 */
final class DeleteFontFamily implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-fonts/delete-font-family';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/font-families/(?P<id>[\d]+)',
				'method'          => 'DELETE',
				'label'           => __( 'Delete Font Family', 'abilities-catalog' ),
				'description'     => __( 'Permanently deletes an installed font family and its font-face assets by ID. This cannot be undone and may break typography that references it.', 'abilities-catalog' ),
				'category'        => 'og-core-fonts',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'The font family post ID to permanently delete. Discover the ID with og-fonts/list-font-families.', 'abilities-catalog' ),
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'input_callback'  => array( $this, 'injectForce' ),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'deleted', 'id' ),
					'properties'           => array(
						'deleted'         => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the font family was permanently deleted.', 'abilities-catalog' ),
						),
						'id'              => array(
							'type'        => 'integer',
							'description' => __( 'The deleted font family post ID.', 'abilities-catalog' ),
						),
						'name'            => array(
							'type'        => 'string',
							'description' => __( 'The display name of the deleted font family.', 'abilities-catalog' ),
						),
						'slug'            => array(
							'type'        => 'string',
							'description' => __( 'The slug of the deleted font family.', 'abilities-catalog' ),
						),
						'font_face_count' => array(
							'type'        => 'integer',
							'description' => __( 'The number of font-face child posts (and their files) removed with the family.', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_callback' => array( $this, 'shapeOutput' ),
				'meta'            => array(
					'annotations'       => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
					'abilities_catalog' => array(
						'scope' => 'site',
					),
					'show_in_rest'      => true,
					'screen'            => 'font-library.php',
				),
			)
		);
	}

	/**
	 * Pins `force=true` so the route permanently deletes instead of trashing.
	 *
	 * Wired as the adapter's `input_callback`. The caller never passes `force`, so
	 * it is not in the input schema; the adapter validates input first, then runs
	 * this. `id` is kept so {@see Rest_Route_Ability::substitute_captures()} fills
	 * the path capture.
	 *
	 * @param array<string,mixed> $input The validated ability input.
	 * @return array<string,mixed> The input with `force` forced on.
	 */
	public function injectForce( array $input ): array {
		return $input + array( 'force' => true );
	}

	/**
	 * Flattens the DELETE response to the catalog's deleted/id/name/slug/count set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success. A
	 * forced delete returns `{ deleted: true, previous: <prepared item> }`; every
	 * field this shape needs is read from that body. `id` comes from the original
	 * `$input` (the `previous.id` REST may filter), and `font_face_count` is the
	 * size of `previous.font_faces`. `$response` is part of the callback signature
	 * but unused — the body carries everything.
	 *
	 * @param mixed               $data     The REST DELETE body (associative array).
	 * @param array<string,mixed> $input    The original ability input.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat deleted-family fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data     = is_array( $data ) ? $data : array();
		$previous = is_array( $data['previous'] ?? null ) ? $data['previous'] : array();
		$settings = is_array( $previous['font_family_settings'] ?? null ) ? $previous['font_family_settings'] : array();
		$faces    = is_array( $previous['font_faces'] ?? null ) ? $previous['font_faces'] : array();

		return array(
			'deleted'         => (bool) ( $data['deleted'] ?? false ),
			'id'              => (int) ( $input['id'] ?? 0 ),
			'name'            => (string) ( $settings['name'] ?? '' ),
			'slug'            => (string) ( $settings['slug'] ?? '' ),
			'font_face_count' => count( $faces ),
		);
	}
}
