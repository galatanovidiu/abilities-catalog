<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Templates;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Block_Bindings_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-templates/list-block-binding-sources`.
 *
 * Reads the live block-bindings registry via
 * `WP_Block_Bindings_Registry::get_instance()->get_all_registered()` and shapes
 * the result. Returns the registered block-bindings sources (e.g.
 * `core/post-meta`, `core/pattern-overrides`) that an editor can bind block
 * attributes to, each flattened to its name, human label, and the block context
 * it needs. Discover a source name here, then read one with
 * `og-templates/get-block-binding-source`. The private value-resolver callback each
 * source carries is never exposed. Read-only.
 *
 * @since 0.6.0
 */
final class ListBlockBindingSources implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-templates/list-block-binding-sources';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'List Block Binding Sources', 'abilities-catalog' ),
			'description'         => __( 'Lists the block-binding sources registered on the site (e.g. "core/post-meta", "core/pattern-overrides") that an editor can bind block attributes to, each as a flat row: name, label, and the block context it needs. Use this to discover available source names, then read one with og-templates/get-block-binding-source. It does not return how a source resolves its value.', 'abilities-catalog' ),
			'category'            => 'templates',
			'input_schema'        => array(),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'sources', 'total' ),
				'properties'           => array(
					'sources' => array(
						'type'        => 'array',
						'items'       => array(
							'type'                 => 'object',
							'required'             => array( 'name' ),
							'properties'           => array(
								'name'         => array(
									'type'        => 'string',
									'description' => __( 'The block-binding source name (e.g. "core/post-meta"). Pass this to og-templates/get-block-binding-source.', 'abilities-catalog' ),
								),
								'label'        => array(
									'type'        => 'string',
									'description' => __( 'The human-readable source label shown in the editor.', 'abilities-catalog' ),
								),
								'uses_context' => array(
									'type'        => 'array',
									'items'       => array( 'type' => 'string' ),
									'description' => __( 'Block context keys the source needs (e.g. "postId", "postType"); empty when the source needs no context.', 'abilities-catalog' ),
								),
							),
							'additionalProperties' => false,
						),
						'description' => __( 'The registered block-binding sources as flat rows.', 'abilities-catalog' ),
					),
					'total'   => array(
						'type'        => 'integer',
						'description' => __( 'The number of registered block-binding sources returned.', 'abilities-catalog' ),
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
	 * Mirrors `og-templates/list-block-types`: block-binding sources are editor
	 * authoring infrastructure, so the same baseline guard applies. There is no
	 * dedicated core capability for reading the bindings registry.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read the block-binding sources registry.
	 */
	public function hasPermission( $input = null ): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Executes the ability by reading the live block-bindings registry.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed> The shaped collection of registered sources.
	 */
	public function execute( $input = null ): array {
		$sources = WP_Block_Bindings_Registry::get_instance()->get_all_registered();

		$rows = array();
		foreach ( $sources as $source ) {
			$rows[] = array(
				'name'         => (string) $source->name,
				'label'        => (string) $source->label,
				'uses_context' => array_values( (array) ( $source->uses_context ?? array() ) ),
			);
		}

		return array(
			'sources' => $rows,
			'total'   => count( $rows ),
		);
	}
}
