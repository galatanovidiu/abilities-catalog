<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Templates;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `templates/lookup-template`.
 *
 * Resolves which site-editor template would render a given slug, using core's
 * template-resolution functions ({@see get_template_hierarchy()} and
 * {@see get_block_templates()}) — no network, no REST. Given a slug such as
 * "single", "page", "404", or a specific "page-about", it returns the ordered
 * template hierarchy WordPress would try and the first template in that hierarchy
 * that actually exists for the active theme (its "theme//slug" id and title).
 * Use this to find the right template id before reading it with
 * `templates/get-template` or changing it with `templates/update-template`.
 * Read-only.
 *
 * @since 0.5.0
 */
final class LookupTemplate implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'templates/lookup-template';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Lookup Template', 'abilities-catalog' ),
			'description'         => __( 'Resolves which template renders a given slug. Returns the template hierarchy WordPress would try (most specific first) and the first one that exists for the active theme, with its "theme//slug" id and title. Use it to find a template id before reading or updating it.', 'abilities-catalog' ),
			'category'            => 'templates',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'slug'            => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'The template slug to resolve (e.g. "single", "page", "404", "page-about").', 'abilities-catalog' ),
					),
					'is_custom'       => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Whether the slug is a user-defined custom template (a "wp_template" with no matching theme file).', 'abilities-catalog' ),
					),
					'template_prefix' => array(
						'type'        => 'string',
						'default'     => '',
						'description' => __( 'Optional prefix for a more specific slug (e.g. "page" for the slug "page-about"). Affects the generated hierarchy.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'slug' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'hierarchy', 'resolved' ),
				'properties'           => array(
					'hierarchy'      => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'The ordered template slugs WordPress would try, most specific first.', 'abilities-catalog' ),
					),
					'resolved'       => array(
						'type'        => 'string',
						'description' => __( 'The slug of the first template in the hierarchy that exists for the active theme; empty if none exists.', 'abilities-catalog' ),
					),
					'resolved_id'    => array(
						'type'        => 'string',
						'description' => __( 'The resolved template id in "theme//slug" form; empty if none exists.', 'abilities-catalog' ),
					),
					'resolved_title' => array(
						'type'        => 'string',
						'description' => __( 'The resolved template title; empty if none exists.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Permission check: `edit_theme_options` (catalog capability for template data).
	 *
	 * Matches the `templates/get-template` sibling and the templates REST
	 * controller `permissions_check`; never weaker than reading a template.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read template resolution.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Executes the ability using core template-resolution functions.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The hierarchy and resolved template, or an error.
	 */
	public function execute( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$slug   = sanitize_title( (string) ( $input['slug'] ?? '' ) );
		$prefix = isset( $input['template_prefix'] ) ? sanitize_key( (string) $input['template_prefix'] ) : '';
		$custom = ! empty( $input['is_custom'] );

		if ( '' === $slug ) {
			return new \WP_Error(
				'invalid_slug',
				__( 'A non-empty template slug is required.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		if ( ! function_exists( 'get_template_hierarchy' ) || ! function_exists( 'get_block_templates' ) ) {
			return new \WP_Error(
				'block_templates_unavailable',
				__( 'Block template resolution is not available on this site.', 'abilities-catalog' ),
				array( 'status' => 501 )
			);
		}

		$hierarchy = get_template_hierarchy( $slug, $custom, $prefix );
		$hierarchy = array_values( array_filter( array_map( 'strval', is_array( $hierarchy ) ? $hierarchy : array() ) ) );

		// Map existing block templates by slug for the active theme.
		$existing = array();
		foreach ( get_block_templates( array(), 'wp_template' ) as $template ) {
			if ( ! isset( $template->slug ) ) {
				continue;
			}

			$existing[ (string) $template->slug ] = $template;
		}

		$resolved       = '';
		$resolved_id    = '';
		$resolved_title = '';
		foreach ( $hierarchy as $candidate ) {
			if ( isset( $existing[ $candidate ] ) ) {
				$template       = $existing[ $candidate ];
				$resolved       = $candidate;
				$resolved_id    = (string) ( $template->id ?? '' );
				$resolved_title = (string) ( $template->title ?? '' );
				break;
			}
		}

		return array(
			'hierarchy'      => $hierarchy,
			'resolved'       => $resolved,
			'resolved_id'    => $resolved_id,
			'resolved_title' => $resolved_title,
		);
	}
}
