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
 * Other plugins extend the taxonomy through the `abilities_catalog_mcp_domain_map`
 * filter: it carries the exact-name placements (the {@see DOMAIN_INCLUDES} shape),
 * so a third party can drop one of its abilities into an existing domain or open a
 * new domain of its own. A new domain introduced this way also appears in
 * {@see domains()}, so the server builds a tool for it. The curated prefix rules are
 * not filterable — they are core's taxonomy, and exposing them would let a third
 * party claim a whole prefix and capture core abilities.
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
	 * The exact-name placements after the `abilities_catalog_mcp_domain_map` filter.
	 *
	 * Resolved once on first use and reused, so the filter runs a single time per
	 * instance rather than once per ability during a `list`. Null until resolved.
	 *
	 * @var array<string, list<string>>|null
	 */
	private ?array $includes = null;

	/**
	 * Returns the domain slugs, in tool order.
	 *
	 * This is the seam for registering one MCP tool per domain: the server iterates
	 * these slugs to build the tools, so the order here is the order an agent sees.
	 * The curated domains come first, in their defined order; any new domain a third
	 * party opens through the `abilities_catalog_mcp_domain_map` filter follows.
	 *
	 * @return list<string> The domain slugs.
	 */
	public function domains(): array {
		$domains = array_keys( self::DOMAIN_PREFIXES );

		foreach ( array_keys( $this->includes() ) as $domain ) {
			$domain = (string) $domain;
			if ( in_array( $domain, $domains, true ) ) {
				continue;
			}

			$domains[] = $domain;
		}

		return $domains;
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
		foreach ( $this->includes() as $domain => $names ) {
			if ( is_array( $names ) && in_array( $ability, $names, true ) ) {
				return (string) $domain;
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

	/**
	 * Returns the names that belong to no domain.
	 *
	 * Pure counterpart to {@see domainOf()}: the server feeds it the registered
	 * ability names so it can warn about any ability that would be exposed by no
	 * domain tool. It touches neither the registry nor the adapter, so the coverage
	 * rule is testable on its own.
	 *
	 * @param list<string> $ability_names The ability names to classify.
	 * @return list<string> The subset with no owning domain, in input order.
	 */
	public function unmapped( array $ability_names ): array {
		$orphans = array();

		foreach ( $ability_names as $name ) {
			if ( null !== $this->domainOf( $name ) ) {
				continue;
			}

			$orphans[] = $name;
		}

		return $orphans;
	}

	/**
	 * Warns about any ability name no domain owns (spec §12).
	 *
	 * The coverage guard the server runs at boot: an ability whose name reaches no
	 * domain would be exposed by no tool, so this fires a `_doing_it_wrong` notice
	 * naming the orphans rather than dropping them silently. It reasons only about the
	 * names it is given — the server supplies the registered ones — so the warning
	 * lives next to the taxonomy without the class reaching into the registry.
	 *
	 * @param list<string> $ability_names The registered ability names to check.
	 * @return void
	 */
	public function reportUnmapped( array $ability_names ): void {
		$orphans = $this->unmapped( $ability_names );

		if ( empty( $orphans ) ) {
			return;
		}

		_doing_it_wrong(
			__METHOD__,
			sprintf(
				/* translators: %s: comma-separated list of ability names. */
				esc_html__( 'These abilities are registered but mapped to no MCP domain, so no domain tool exposes them: %s. Assign them with the "abilities_catalog_mcp_domain_map" filter.', 'abilities-catalog' ),
				esc_html( implode( ', ', $orphans ) )
			),
			'0.2.0'
		);
	}

	/**
	 * Resolves the exact-name placements, applying the extensibility filter once.
	 *
	 * A misbehaving filter that returns a non-array is ignored in favor of the
	 * curated default, so the map never breaks the server.
	 *
	 * @return array<string, list<string>> Domain slug => exact ability names placed in it.
	 */
	private function includes(): array {
		if ( null !== $this->includes ) {
			return $this->includes;
		}

		/**
		 * Filters the exact-name ability-to-domain placements.
		 *
		 * Use it to drop a third-party ability into an existing domain or to open a
		 * new domain. Preserve the entries already present; replacing a domain's
		 * array drops the curated names it held.
		 *
		 * @since 0.2.0
		 *
		 * @param array<string, list<string>> $includes Domain slug => exact ability names placed in that domain.
		 */
		$filtered = apply_filters( 'abilities_catalog_mcp_domain_map', self::DOMAIN_INCLUDES );

		$this->includes = is_array( $filtered ) ? $filtered : self::DOMAIN_INCLUDES;

		return $this->includes;
	}
}
