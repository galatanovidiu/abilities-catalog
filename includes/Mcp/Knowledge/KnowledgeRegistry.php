<?php
/**
 * Merges the registered knowledge bundles and serves the index and concepts.
 *
 * @package AbilitiesCatalog
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Mcp\Knowledge;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The deep module behind the knowledge tool: it owns the bundle set and answers two
 * questions — "what is here?" ({@see rootIndex()}) and "show me this concept"
 * ({@see load()}).
 *
 * It seeds the shipped `core` bundle, runs the `abilities_catalog_mcp_knowledge`
 * filter so add-ons can append their own scanned bundles, and merges the result —
 * skipping (and logging under WP_DEBUG) any value that is not a {@see KnowledgeBundle},
 * any `WP_Error` from a failed scan, and any duplicate slug, so one bad bundle never
 * breaks the tool. The merge runs once per instance (memoized), not once per call.
 *
 * It takes plain arguments and returns plain data or a `WP_Error`; it knows nothing of
 * MCP or the adapter. {@see KnowledgeTool} is the thin shim that adapts it.
 *
 * @since 0.4.0
 */
final class KnowledgeRegistry {

	/**
	 * The reserved slug of the shipped bundle; a later bundle cannot claim it.
	 */
	private const CORE_SLUG = 'core';

	/**
	 * The known concept types, in index display order; others follow, sorted.
	 *
	 * @var array<string,string>
	 */
	private const TYPE_HEADINGS = array(
		'Skill'     => 'Skills',
		'Guideline' => 'Guidelines',
		'Reference' => 'Reference',
	);

	/**
	 * The merged bundles after the `abilities_catalog_mcp_knowledge` filter.
	 *
	 * Resolved once on first use and reused. Null until resolved.
	 *
	 * @var list<\GalatanOvidiu\AbilitiesCatalog\Mcp\Knowledge\KnowledgeBundle>|null
	 */
	private ?array $bundles = null;

	/**
	 * Builds the root index: live site facts plus every bundle's concepts by type.
	 *
	 * The no-`uri` result. A `# Site` block of read-only, REST-safe facts (identity,
	 * environment, structural inventory — no volatile counts, no PII), then one
	 * section per concept type (`# Skills`, `# Guidelines`, `# Reference`, then any
	 * other type) listing every registered bundle's first-level concepts as
	 * `* [title](uri) - description`.
	 *
	 * @return string The index markdown.
	 */
	public function rootIndex(): string {
		$sections = array( $this->siteFacts() );

		$by_type = array();
		foreach ( $this->bundles() as $bundle ) {
			foreach ( $bundle->children() as $concept ) {
				$by_type[ $concept->type() ][] = $concept;
			}
		}

		foreach ( self::orderTypes( array_keys( $by_type ) ) as $type ) {
			$lines = array( '# ' . self::heading( $type ) );
			foreach ( $by_type[ $type ] as $concept ) {
				$lines[] = '' === $concept->description()
					? sprintf( '* [%s](%s)', $concept->title(), $concept->uri() )
					: sprintf( '* [%s](%s) - %s', $concept->title(), $concept->uri(), $concept->description() );
			}

			$sections[] = implode( "\n", $lines );
		}

		return implode( "\n\n", $sections );
	}

	/**
	 * Resolves one concept by uri to its full payload, or a recovery `WP_Error`.
	 *
	 * The uri is split at its first `/` into a bundle slug and a concept id, then
	 * looked up in the merged index — a path is never built from the agent's input, so
	 * a `..`-bearing or null-byte uri simply misses every key and returns the
	 * unknown-uri error rather than reaching the filesystem.
	 *
	 * @param string $uri The concept uri, `<bundle-slug>/<id>`.
	 * @return array{uri:string,type:string,title:string,description:string,tags:list<string>,body:string}|\WP_Error
	 *         The concept payload, or a `WP_Error` when the uri is unknown or the body
	 *         cannot be read.
	 */
	public function load( string $uri ) {
		$uri   = trim( $uri );
		$slash = strpos( $uri, '/' );
		if ( false === $slash || 0 === $slash ) {
			return self::unknownUri( $uri );
		}

		$slug = substr( $uri, 0, $slash );
		$id   = substr( $uri, $slash + 1 );

		foreach ( $this->bundles() as $bundle ) {
			if ( $bundle->slug() !== $slug ) {
				continue;
			}

			$concept = $bundle->concept( $id );
			if ( null === $concept ) {
				break;
			}

			$body = $concept->body();
			if ( is_wp_error( $body ) ) {
				return $body;
			}

			return array(
				'uri'         => $concept->uri(),
				'type'        => $concept->type(),
				'title'       => $concept->title(),
				'description' => $concept->description(),
				'tags'        => $concept->tags(),
				'body'        => $body,
			);
		}

		return self::unknownUri( $uri );
	}

	/**
	 * Resolves the merged bundles, applying the extensibility filter once.
	 *
	 * @return list<\GalatanOvidiu\AbilitiesCatalog\Mcp\Knowledge\KnowledgeBundle> The registered bundles, deduplicated by slug.
	 */
	private function bundles(): array {
		if ( null !== $this->bundles ) {
			return $this->bundles;
		}

		$core = KnowledgeBundle::fromDirectory( ABILITIES_CATALOG_DIR . 'includes/knowledge', self::CORE_SLUG );
		$seed = array();
		if ( is_wp_error( $core ) ) {
			self::log( 'The core knowledge bundle failed to load: ' . $core->get_error_message() );
		} else {
			$seed[] = $core;
		}

		/**
		 * Filters the knowledge bundles the knowledge tool serves.
		 *
		 * Scan your own bundle directory with
		 * {@see KnowledgeBundle::fromDirectory()} and append the returned bundle,
		 * guarding the `WP_Error` it returns on a missing directory:
		 *
		 *     add_filter( 'abilities_catalog_mcp_knowledge', function ( array $bundles ) {
		 *         $bundle = KnowledgeBundle::fromDirectory( __DIR__ . '/knowledge', 'my-slug' );
		 *         if ( ! is_wp_error( $bundle ) ) {
		 *             $bundles[] = $bundle;
		 *         }
		 *         return $bundles;
		 *     } );
		 *
		 * Preserve the entries already present; the `core` slug is reserved. A
		 * non-`KnowledgeBundle` value, a `WP_Error`, and a duplicate slug are each
		 * skipped (logged under WP_DEBUG).
		 *
		 * @since 0.4.0
		 *
		 * @param list<\GalatanOvidiu\AbilitiesCatalog\Mcp\Knowledge\KnowledgeBundle> $bundles The registered bundles, seeded with `core`.
		 */
		$filtered = apply_filters( 'abilities_catalog_mcp_knowledge', $seed );
		if ( ! is_array( $filtered ) ) {
			$filtered = $seed;
		}

		$bundles = array();
		$seen    = array();
		foreach ( $filtered as $bundle ) {
			if ( ! $bundle instanceof KnowledgeBundle ) {
				self::log(
					is_wp_error( $bundle )
						? 'A knowledge bundle scan failed and was skipped: ' . $bundle->get_error_message()
						: 'Ignoring a non-KnowledgeBundle value on the abilities_catalog_mcp_knowledge filter.'
				);

				continue;
			}

			$slug = $bundle->slug();
			if ( isset( $seen[ $slug ] ) ) {
				self::log( sprintf( 'Duplicate knowledge bundle slug "%s" was skipped.', $slug ) );

				continue;
			}

			$seen[ $slug ] = true;
			$bundles[]     = $bundle;
		}

		$this->bundles = $bundles;

		return $bundles;
	}

	/**
	 * Builds the `# Site` facts block from read-only, REST-safe sources.
	 *
	 * @return string The site facts markdown.
	 */
	private function siteFacts(): string {
		$theme = wp_get_theme();

		$post_types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
			$post_types[] = $post_type->name;
		}

		$plugins = array();
		$active  = get_option( 'active_plugins' );
		if ( is_array( $active ) ) {
			foreach ( $active as $plugin ) {
				if ( ! is_string( $plugin ) ) {
					continue;
				}

				$slash     = strpos( $plugin, '/' );
				$plugins[] = false === $slash ? $plugin : substr( $plugin, 0, $slash );
			}
		}

		$lines = array(
			'# Site',
			sprintf( '- Title: %s', get_bloginfo( 'name' ) ),
			sprintf( '- Tagline: %s', get_bloginfo( 'description' ) ),
			sprintf( '- URL: %s', get_bloginfo( 'url' ) ),
			sprintf( '- Language: %s', get_bloginfo( 'language' ) ),
			sprintf( '- Timezone: %s', wp_timezone_string() ),
			sprintf( '- WordPress: %s', get_bloginfo( 'version' ) ),
			sprintf( '- Theme: %s (%s)', (string) $theme->get( 'Name' ), $theme->is_block_theme() ? 'block theme' : 'classic theme' ),
			sprintf( '- Multisite: %s', is_multisite() ? 'yes' : 'no' ),
			sprintf( '- Public post types: %s', array() === $post_types ? '(none)' : implode( ', ', $post_types ) ),
			sprintf( '- Active plugins: %s', array() === $plugins ? '(none)' : implode( ', ', $plugins ) ),
		);

		return implode( "\n", $lines );
	}

	/**
	 * Orders the present concept types: the known three first, then the rest sorted.
	 *
	 * @param list<string> $types The types present in the index.
	 * @return list<string> The ordered types.
	 */
	private static function orderTypes( array $types ): array {
		$ordered = array();
		foreach ( array_keys( self::TYPE_HEADINGS ) as $known ) {
			if ( ! in_array( $known, $types, true ) ) {
				continue;
			}

			$ordered[] = $known;
		}

		$others = array_values( array_diff( $types, array_keys( self::TYPE_HEADINGS ) ) );
		sort( $others );

		return array_merge( $ordered, $others );
	}

	/**
	 * The section heading for a concept type (an unknown type heads its own section).
	 *
	 * @param string $type The concept type.
	 * @return string The section heading.
	 */
	private static function heading( string $type ): string {
		return self::TYPE_HEADINGS[ $type ] ?? $type;
	}

	/**
	 * The unknown-uri recovery error, naming the bad uri and the no-uri index.
	 *
	 * @param string $uri The requested uri.
	 * @return \WP_Error The error, carrying a 404 status for the caller's fold.
	 */
	private static function unknownUri( string $uri ): WP_Error {
		return new WP_Error(
			'abilities_catalog_mcp_unknown_knowledge',
			sprintf(
				/* translators: %s: the requested knowledge uri. */
				__( 'No knowledge concept at "%s". Call the knowledge tool with no uri to list every available concept.', 'abilities-catalog' ),
				$uri
			),
			array( 'status' => 404 )
		);
	}

	/**
	 * Logs a registry diagnostic, but only under WP_DEBUG.
	 *
	 * @param string $message The diagnostic message.
	 * @return void
	 */
	private static function log( string $message ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-guarded diagnostic for an off-by-default feature that has no other channel.
		error_log( 'Abilities Catalog: ' . $message );
	}
}
