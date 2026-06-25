<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Templates;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Block_Bindings_Registry;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-templates/get-block-binding-source`.
 *
 * Reads one registered block-bindings source by name from the live
 * `WP_Block_Bindings_Registry` singleton and projects it to a flat shape
 * (`name`, `label`, `uses_context`). A block-bindings source (e.g.
 * `core/post-meta`, `core/pattern-overrides`) is a target an editor can bind a
 * block attribute to. Discover available names with
 * `og-templates/list-block-binding-sources`. An unknown name returns a specific
 * `abilities_catalog_binding_source_not_found` 404, not a permission denial.
 *
 * The source's private `get_value_callback` is never surfaced — only the public
 * `name`, `label`, and `uses_context` properties are projected.
 *
 * @since 0.5.0
 */
final class GetBlockBindingSource implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-templates/get-block-binding-source';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Block Binding Source', 'abilities-catalog' ),
			'description'         => __( 'Returns one registered block-bindings source by name, including its label and the block context keys it uses (uses_context). A block-bindings source (e.g. "core/post-meta", "core/pattern-overrides") is a target an editor can bind a block attribute to. Discover names with og-templates/list-block-binding-sources. An unknown name returns a 404, not a permission error.', 'abilities-catalog' ),
			'category'            => 'templates',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'name' ),
				'properties'           => array(
					'name' => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'The block-bindings source name, e.g. "core/post-meta". Discover names with og-templates/list-block-binding-sources.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'name' ),
				'properties'           => array(
					'name'         => array(
						'type'        => 'string',
						'description' => __( 'The source name (e.g. "core/post-meta").', 'abilities-catalog' ),
					),
					'label'        => array(
						'type'        => 'string',
						'description' => __( 'The human-readable label of the source.', 'abilities-catalog' ),
					),
					'uses_context' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'The block context keys the source needs (e.g. "postId", "pattern/overrides"). Empty if the source declares none.', 'abilities-catalog' ),
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
	 * Permission check: `edit_posts` (catalog capability for reading editor infrastructure).
	 *
	 * Mirrors `og-templates/list-block-types` — reading the block-bindings registry is
	 * editor infrastructure, gated on the baseline content-editing capability.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read block-bindings sources.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Executes the ability by reading one source from the registry singleton.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The projected source, or a 404 error.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();
		$name  = (string) ( $input['name'] ?? '' );

		$source = WP_Block_Bindings_Registry::get_instance()->get_registered( $name );

		if ( null === $source ) {
			return new WP_Error(
				'abilities_catalog_binding_source_not_found',
				__( 'No block-binding source is registered under that name.', 'abilities-catalog' ),
				array( 'status' => 404 )
			);
		}

		return array(
			'name'         => (string) $source->name,
			'label'        => (string) $source->label,
			'uses_context' => array_values( (array) ( $source->uses_context ?? array() ) ),
		);
	}
}
