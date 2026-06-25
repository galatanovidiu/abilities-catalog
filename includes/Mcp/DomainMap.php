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
 * deliberately grouped (themes+menus+widgets form "appearance"; templates+fonts
 * form "design"; `og-privacy/*` and `og-cron/*` join "tools" while `og-settings/*`
 * privacy-policy settings stay in "settings"; multisite `og-network/*` is its own
 * domain), and a few abilities are placed by exact name
 * where no prefix fits (`og-search/search-content` joins "content"; core's own
 * read-only `core/*` info abilities join the domains whose data they mirror).
 *
 * The server exposes a *curated subset* of the registered abilities, not all of
 * them: an ability no domain owns is simply not exposed by any tool. That is
 * intentional, so this map never warns about an unmapped name.
 *
 * This class only knows names; it never touches the live ability registry. The
 * {@see DomainRouter} pairs a domain with the registry to list, describe, and run
 * the abilities a domain owns.
 *
 * Other plugins extend the taxonomy through two filters with distinct jobs. The
 * `abilities_catalog_mcp_domains` filter registers a whole add-on domain TOOL — its
 * description and the exact abilities it owns (the {@see addonDomains()} shape) — so
 * the server builds a fully-described tool from one place. The narrower
 * `abilities_catalog_mcp_domain_map` filter only places exact ability names into a
 * domain (the {@see DOMAIN_INCLUDES} shape), e.g. to drop a third-party ability into
 * an existing core domain. Either way a new domain appears in {@see domains()} so the
 * server builds a tool for it. The curated prefix rules are not filterable, and
 * neither filter can override a core domain — that taxonomy is core's, and exposing
 * it would let a third party claim a whole prefix and capture core abilities.
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
		'content'     => array( 'og-content', 'og-terms', 'og-comments' ),
		'media'       => array( 'og-media' ),
		'appearance'  => array( 'og-themes', 'og-menus', 'og-widgets' ),
		'design'      => array( 'og-templates', 'og-fonts' ),
		'plugins'     => array( 'og-plugins' ),
		'users'       => array( 'og-users' ),
		'settings'    => array( 'og-settings', 'og-connectors' ),
		'tools'       => array( 'og-tools', 'og-privacy', 'og-cron' ),
		'site-health' => array( 'og-site-health' ),
		'updates'     => array( 'og-updates' ),
		'dashboard'   => array( 'og-dashboard' ),
		'network'     => array( 'og-network' ),
	);

	/**
	 * Domain slug => exact ability names a prefix cannot capture.
	 *
	 * Checked before the prefix rules so an explicit placement always wins. Two
	 * kinds land here: full-text search, whose `og-search/` prefix is not a domain;
	 * and the read-only `core/*` info abilities WordPress core itself registers,
	 * each placed in the domain whose data it mirrors (site facts in "settings",
	 * the current user in "users", the runtime environment in "site-health"). Any
	 * other `core/*` ability is left unmapped, so the curated server does not
	 * expose it.
	 *
	 * @var array<string, list<string>>
	 */
	private const DOMAIN_INCLUDES = array(
		'content'     => array( 'og-search/search-content' ),
		'settings'    => array( 'core/get-site-info' ),
		'users'       => array( 'core/get-user-info' ),
		'site-health' => array( 'core/get-environment-info' ),
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
	 * The add-on domain tools after the `abilities_catalog_mcp_domains` filter.
	 *
	 * Resolved once on first use and reused, so the filter runs a single time per
	 * instance. Null until resolved.
	 *
	 * @var array<string, array{description: string, abilities: list<string>}>|null
	 */
	private ?array $addons = null;

	/**
	 * Returns the domain slugs, in tool order.
	 *
	 * This is the seam for registering one MCP tool per domain: the server iterates
	 * these slugs to build the tools, so the order here is the order an agent sees.
	 * The curated domains come first, in their defined order; any new domain a third
	 * party opens through the `abilities_catalog_mcp_domains` or
	 * `abilities_catalog_mcp_domain_map` filter follows.
	 *
	 * @return list<string> The domain slugs.
	 */
	public function domains(): array {
		$domains = array_keys( self::DOMAIN_PREFIXES );

		$added = array_merge( array_keys( $this->includes() ), array_keys( $this->addonDomains() ) );
		foreach ( $added as $domain ) {
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
	 * @param string $ability Full ability name, e.g. `og-content/get-post`.
	 * @return string|null The owning domain slug, or null when unmapped.
	 */
	public function domainOf( string $ability ): ?string {
		foreach ( $this->includes() as $domain => $names ) {
			if ( is_array( $names ) && in_array( $ability, $names, true ) ) {
				return (string) $domain;
			}
		}

		foreach ( $this->addonDomains() as $domain => $spec ) {
			if ( in_array( $ability, $spec['abilities'], true ) ) {
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
	 * The ability-name patterns one domain owns: its prefixes (as `prefix/*`) plus any
	 * exact-name placements.
	 *
	 * The {@see DomainRouter} appends these to an unknown-ability recovery error. The domain
	 * tool name is not the ability prefix — the `content` tool owns `og-content/`, `og-terms/`,
	 * `og-comments/` and the exact `og-search/search-content` — so an agent that guessed a name
	 * (e.g. `content/search-posts`) sees the real prefixes to retry with, without a round-trip
	 * to `list`. These are taxonomy facts about the domain, independent of the guessed name, so
	 * naming them is not a fuzzy "did you mean" suggestion.
	 *
	 * Returns an empty list for an add-on domain that owns only whole-tool exact names (those
	 * live in {@see addonDomains()}, not the prefix map), or for an unknown slug; the caller
	 * falls back to the plain "call list" hint.
	 *
	 * @param string $domain The domain slug.
	 * @return list<string> The owned name patterns, e.g. `['og-content/*', 'og-terms/*', 'og-comments/*', 'og-search/search-content']`.
	 */
	public function namePatternsOf( string $domain ): array {
		$patterns = array();

		foreach ( self::DOMAIN_PREFIXES[ $domain ] ?? array() as $prefix ) {
			$patterns[] = $prefix . '/*';
		}

		foreach ( $this->includes()[ $domain ] ?? array() as $name ) {
			$patterns[] = (string) $name;
		}

		return $patterns;
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

	/**
	 * The description an add-on supplied for one of its domains, or null.
	 *
	 * The server reads this to give an add-on domain tool the same kind of routing
	 * blurb a curated core domain has, instead of a generic fallback. A core domain
	 * is never here (the filter cannot override core), so the server keeps using its
	 * own curated blurb for those.
	 *
	 * @param string $domain The domain slug.
	 * @return string|null The add-on's description, or null when the domain is not an add-on domain.
	 */
	public function descriptionOf( string $domain ): ?string {
		$description = $this->addonDomains()[ $domain ]['description'] ?? '';

		return '' !== $description ? $description : null;
	}

	/**
	 * Resolves the add-on domain tools, applying the registration filter once.
	 *
	 * Each entry is a whole domain tool an add-on opened: its routing description and
	 * the exact ability names it owns. Malformed entries are dropped, and an entry
	 * that reuses a curated core domain slug is ignored, so the filter can never
	 * break the server or hijack core's taxonomy.
	 *
	 * @return array<string, array{description: string, abilities: list<string>}> Add-on domain slug => its tool descriptor.
	 */
	private function addonDomains(): array {
		if ( null !== $this->addons ) {
			return $this->addons;
		}

		/**
		 * Filters the add-on MCP domain tools.
		 *
		 * Register a whole domain tool from another plugin: a `description` (the
		 * routing blurb an agent reads) and the `abilities` (the exact ability names
		 * the tool groups). The new domain gets its own `list`/`describe`/`execute`
		 * tool. Preserve the entries already present; a non-array entry, a non-string
		 * description, or a slug that reuses a curated core domain is ignored.
		 *
		 * @since 0.3.0
		 *
		 * @param array<string, array{description: string, abilities: list<string>}> $domains Add-on domain slug => its tool descriptor.
		 */
		$filtered = apply_filters( 'abilities_catalog_mcp_domains', array() );

		$this->addons = array();

		if ( ! is_array( $filtered ) ) {
			return $this->addons;
		}

		foreach ( $filtered as $slug => $spec ) {
			$slug = (string) $slug;

			// A blank slug, or one that reuses a curated core domain, is refused.
			if ( '' === $slug || isset( self::DOMAIN_PREFIXES[ $slug ] ) || ! is_array( $spec ) ) {
				continue;
			}

			$description = $spec['description'] ?? '';
			$abilities   = $spec['abilities'] ?? array();

			if ( ! is_string( $description ) || ! is_array( $abilities ) ) {
				continue;
			}

			$names = array();
			foreach ( $abilities as $name ) {
				if ( ! is_string( $name ) || '' === $name ) {
					continue;
				}

				$names[] = $name;
			}

			$this->addons[ $slug ] = array(
				'description' => $description,
				'abilities'   => $names,
			);
		}

		return $this->addons;
	}
}
