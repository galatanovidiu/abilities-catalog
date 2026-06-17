<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Dashboard;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Composed T1 read ability: `dashboard/get-drafts`.
 *
 * Mirrors the wp-admin "Quick Draft" recent-drafts list. Returns the current
 * user's most recently modified draft posts. Built directly on `get_posts()`
 * rather than REST, since this is a net-new composed read scoped to the current
 * user's drafts.
 *
 * @since 0.1.0
 */
final class GetDrafts implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'dashboard/get-drafts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Drafts', 'abilities-catalog' ),
			'description'         => __( 'Returns the current user\'s most recently modified draft posts.', 'abilities-catalog' ),
			'category'            => 'dashboard',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'number' => array(
						'type'        => 'integer',
						'default'     => 5,
						'minimum'     => 1,
						'maximum'     => 20,
						'description' => __( 'Maximum number of drafts to return (1-20).', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'items' ),
				'properties'           => array(
					'items' => array(
						'type'        => 'array',
						'description' => __( 'The current user\'s recent drafts.', 'abilities-catalog' ),
						'items'       => array(
							'type'                 => 'object',
							'required'             => array( 'id', 'title', 'modified' ),
							'properties'           => array(
								'id'        => array(
									'type'        => 'integer',
									'description' => __( 'Draft post ID.', 'abilities-catalog' ),
								),
								'title'     => array(
									'type'        => 'string',
									'description' => __( 'Draft title.', 'abilities-catalog' ),
								),
								'modified'  => array(
									'type'        => 'string',
									'description' => __( 'Last modified date (site timezone).', 'abilities-catalog' ),
								),
								'edit_link' => array(
									'type'        => array( 'string', 'null' ),
									'description' => __( 'Raw wp-admin edit URL, or null if unavailable.', 'abilities-catalog' ),
								),
							),
							'additionalProperties' => false,
						),
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
	 * Permission check: the current user may edit posts.
	 *
	 * Encodes the catalog capability for `dashboard/get-drafts` (`edit_posts`).
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may edit posts.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Executes the ability by reading the current user's recent drafts.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed> The draft list.
	 */
	public function execute( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$number = isset( $input['number'] ) ? (int) $input['number'] : 5;
		$number = max( 1, min( 20, $number ) );

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.get_posts_get_posts -- 'suppress_filters' => false keeps the query cacheable, which the sniff documents as safe.
		$drafts = get_posts(
			array(
				'post_status'      => 'draft',
				'author'           => get_current_user_id(),
				'numberposts'      => $number,
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'suppress_filters' => false,
			)
		);

		$items = array();
		foreach ( $drafts as $draft ) {
			$edit_link = get_edit_post_link( $draft->ID, 'raw' );
			$items[]   = array(
				'id'        => (int) $draft->ID,
				'title'     => (string) get_the_title( $draft->ID ),
				'modified'  => (string) $draft->post_modified,
				'edit_link' => null !== $edit_link ? (string) $edit_link : null,
			);
		}

		return array(
			'items' => $items,
		);
	}
}
