<?php
/**
 * The curated ability -> domain taxonomy.
 *
 * @package AbilitiesCatalog
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Mcp;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classifies each ability into one of the curated MCP domains.
 *
 * The MCP server exposes one tool per domain, not one per ability, so every
 * ability needs a home. This map is the single source of truth for that
 * assignment (spec §11). It is a *curated* taxonomy, not a naive first-segment
 * split: most abilities land by their name prefix, but the prefixes are
 * deliberately grouped (themes+menus form "appearance"; templates+fonts form
 * "design"; `privacy/*` requests join "tools" while `settings/*` privacy-policy
 * settings stay in "settings"), and a few abilities are placed by exact name
 * where no prefix fits (`search/search-content` belongs to "content").
 *
 * This class only knows names; it never touches the live ability registry. The
 * {@see DomainRouter} pairs a domain with the registry to list, describe, and run
 * the abilities a domain owns.
 *
 * @since 0.2.0
 */
final class DomainMap {

	/**
	 * Domain slug => the ability-name prefixes (first path segment) it folds in.
	 *
	 * Order is the tool order an agent sees.
	 *
	 * @var array<string, list<string>>
	 */
	private const DOMAIN_PREFIXES = array(
		'content'     => array( 'content', 'terms', 'comments' ),
		'media'       => array( 'media' ),
		'appearance'  => array( 'themes', 'menus' ),
		'design'      => array( 'templates', 'fonts' ),
		'plugins'     => array( 'plugins' ),
		'users'       => array( 'users' ),
		'settings'    => array( 'settings', 'connectors' ),
		'tools'       => array( 'tools', 'privacy' ),
		'site-health' => array( 'site-health' ),
		'updates'     => array( 'updates' ),
		'dashboard'   => array( 'dashboard' ),
	);

	/**
	 * Domain slug => exact ability names a prefix cannot capture.
	 *
	 * Checked before the prefix rules so an explicit placement always wins. The
	 * only current case is search, whose `search/` prefix is not a domain.
	 *
	 * @var array<string, list<string>>
	 */
	private const DOMAIN_INCLUDES = array(
		'content' => array( 'search/search-content' ),
	);

	/**
	 * Returns the curated domain slugs, in tool order.
	 *
	 * This is the seam for registering one MCP tool per domain: the server iterates
	 * these slugs to build the tools, so the order here is the order an agent sees.
	 *
	 * @return list<string> The domain slugs.
	 */
	public function domains(): array {
		return array_keys( self::DOMAIN_PREFIXES );
	}

	/**
	 * Returns the domain an ability belongs to, or null when it is unmapped.
	 *
	 * An exact-name placement wins over the prefix rules. An ability whose prefix
	 * matches no domain (and which is not explicitly placed) is unmapped — the
	 * caller decides what an unmapped ability means.
	 *
	 * @param string $ability Full ability name, e.g. `content/get-post`.
	 * @return string|null The owning domain slug, or null when unmapped.
	 */
	public function domainOf( string $ability ): ?string {
		foreach ( self::DOMAIN_INCLUDES as $domain => $names ) {
			if ( in_array( $ability, $names, true ) ) {
				return $domain;
			}
		}

		$slash = strpos( $ability, '/' );
		if ( false === $slash ) {
			return null;
		}

		$prefix = substr( $ability, 0, $slash );
		foreach ( self::DOMAIN_PREFIXES as $domain => $prefixes ) {
			if ( in_array( $prefix, $prefixes, true ) ) {
				return $domain;
			}
		}

		return null;
	}
}
