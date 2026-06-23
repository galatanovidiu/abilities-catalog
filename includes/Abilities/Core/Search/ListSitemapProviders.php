<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Search;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `search/list-sitemap-providers`.
 *
 * Wraps `wp_get_sitemap_providers()` and reports the registered XML sitemap
 * providers — the content groups WordPress includes in its sitemap (posts,
 * taxonomies, users by default) — together with each provider's object
 * subtypes (e.g. the posts provider lists the public post types it covers).
 *
 * The provider name is the registry array key. `WP_Sitemaps_Provider::$name`
 * and `$object_type` are protected with no public getter, so the name is taken
 * from the key rather than reflected; the subtype slugs come from the public,
 * query-free `get_object_subtypes()` (its array keys). Read-only and cheap.
 *
 * @since 0.6.0
 */
final class ListSitemapProviders implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'search/list-sitemap-providers';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'List Sitemap Providers', 'abilities-catalog' ),
			'description'         => __( 'Returns the registered XML sitemap providers (by default posts, taxonomies, and users) and, for each, its object subtypes — for the posts provider the public post types it covers, for taxonomies the public taxonomy slugs, and an empty list for users. Use this to see what WordPress includes in its sitemap before reading the sitemap URL with search/get-sitemap-url.', 'abilities-catalog' ),
			'category'            => 'search',
			'input_schema'        => array(),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'providers', 'total' ),
				'properties'           => array(
					'providers' => array(
						'type'        => 'array',
						'items'       => array(
							'type'                 => 'object',
							'required'             => array( 'name', 'subtypes' ),
							'properties'           => array(
								'name'     => array(
									'type'        => 'string',
									'description' => __( 'The provider name (the sitemap registry key), e.g. "posts", "taxonomies", or "users".', 'abilities-catalog' ),
								),
								'subtypes' => array(
									'type'        => 'array',
									'items'       => array( 'type' => 'string' ),
									'description' => __( 'The object subtype slugs the provider covers (e.g. "post", "page" for posts; "category", "post_tag" for taxonomies). Empty for providers without subtypes, such as users.', 'abilities-catalog' ),
								),
							),
							'additionalProperties' => false,
						),
						'description' => __( 'The registered sitemap providers.', 'abilities-catalog' ),
					),
					'total'     => array(
						'type'        => 'integer',
						'description' => __( 'The number of registered providers.', 'abilities-catalog' ),
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
	 * Permission check: `edit_posts` (catalog capability for sitemap reads).
	 *
	 * The sitemap provider registry is public, non-sensitive SEO config; this
	 * catalog ability gates it on `edit_posts` so it stays an authenticated
	 * tool, consistent with the other `search` abilities. The hard server-side
	 * guard.
	 *
	 * @param mixed $input The validated input data (none).
	 * @return bool True if the current user may list sitemap providers.
	 */
	public function hasPermission( $input = null ): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Executes the ability by reading the sitemap provider registry.
	 *
	 * @param mixed $input The validated input data (none).
	 * @return array<string,mixed> The shaped provider list.
	 */
	public function execute( $input = null ): array {
		$providers = wp_get_sitemap_providers();

		$rows = array();
		foreach ( $providers as $name => $provider ) {
			$rows[] = array(
				'name'     => (string) $name,
				'subtypes' => array_map( 'strval', array_keys( $provider->get_object_subtypes() ) ),
			);
		}

		return array(
			'providers' => $rows,
			'total'     => count( $rows ),
		);
	}
}
