<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registration-time decorator that adds multisite `blog_id` targeting.
 *
 * On a multisite network, this lets an agent target a specific site by passing an
 * optional `blog_id` to any site-scoped ability. It is a pure args->args transform on
 * the `wp_register_ability_args` filter (the seam the catalog already uses), so it
 * reaches all three MCP servers, direct `wp_get_ability()->execute()` callers, core
 * REST, and WP-CLI with one `meta` line per ability.
 *
 * For a site-scoped ability on multisite it:
 * 1. injects an optional `blog_id` property into the input schema,
 * 2. appends a one-line multisite hint to the description,
 * 3. wraps the permission and execute callbacks so both run inside a balanced
 *    `switch_to_blog()` on the target.
 *
 * The decorator is the FINAL mutator of the schema core stores (it runs after the
 * Registry normalizes), so it must itself emit valid JSON-Schema. After injecting
 * `blog_id`, `properties` is a non-empty PHP array, which serializes as a JSON object.
 *
 * Inject-or-don't-wrap is atomic: if there is no object schema to inject `blog_id`
 * into, the callbacks are NOT wrapped, so a caller passing `blog_id` cannot hit
 * `additionalProperties:false` before the wrapper can strip it.
 *
 * On single-site, for a non-site scope, for an excluded dispatcher ability, or when
 * there is no injectable schema, the args are returned byte-for-byte unchanged — no
 * idempotency flag is written.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability.
 *
 * @since 0.4.0
 */
final class PolicyDecorator {

	/**
	 * Internal meta flag marking an ability whose callbacks were wrapped.
	 *
	 * Written ONLY on the fully-wrapped path, so any other path leaves `meta`
	 * byte-for-byte unchanged (F6).
	 *
	 * @var string
	 */
	private const WRAPPED_FLAG = '_abilities_catalog_decorated';

	/**
	 * Runs an ability callback inside a balanced blog switch.
	 *
	 * @var \GalatanOvidiu\AbilitiesCatalog\Support\BlogSwitchRunner
	 */
	private BlogSwitchRunner $runner;

	/**
	 * Predicate reporting whether this is a multisite install. Seam over
	 * core `is_multisite()`, so the wrap path can be forced in a test.
	 *
	 * @var callable():bool
	 */
	private $is_multisite;

	/**
	 * @param \GalatanOvidiu\AbilitiesCatalog\Support\BlogSwitcher|null $switcher Optional switch
	 *                                            primitive; defaults to `CoreBlogSwitcher`.
	 * @param callable():bool|null $is_multisite Optional multisite predicate; defaults to
	 *                                            core `is_multisite()`.
	 * @param callable(int):(\WP_Site|null)|null $site_lookup Optional site lookup forwarded to the
	 *                                            switch runner; defaults to core `get_site()`. A
	 *                                            seam so the wrap path is testable without a network.
	 * @param callable():int|null $current_network_id Optional current-network resolver forwarded to
	 *                                            the switch runner; defaults to core `get_current_network_id()`.
	 */
	public function __construct(
		?BlogSwitcher $switcher = null,
		?callable $is_multisite = null,
		?callable $site_lookup = null,
		?callable $current_network_id = null
	) {
		$this->runner       = new BlogSwitchRunner( $switcher ?? new CoreBlogSwitcher(), $site_lookup, $current_network_id );
		$this->is_multisite = $is_multisite ?? static function (): bool {
			return is_multisite();
		};
	}

	/**
	 * Registers the decorator on `wp_register_ability_args`.
	 *
	 * Priority 20 runs after the catalog's own `Server.php` filter (priority 10, which
	 * touches only `meta.mcp.public`), making the read order explicit.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_register_ability_args', array( $this, 'decorate' ), 20, 2 );
	}

	/**
	 * Decorates one ability's registration args. Pure transform: args -> args.
	 *
	 * Thin by design: an idempotency check, the dispatcher exclusion, the scope and
	 * multisite gate, then the multisite branch. Any early return leaves `meta`
	 * unchanged.
	 *
	 * @param array<string,mixed> $args The ability's registration args.
	 * @param string              $name The ability name.
	 * @return array<string,mixed> The (possibly decorated) args.
	 */
	public function decorate( array $args, string $name ): array {
		if ( ! empty( $args['meta'][ self::WRAPPED_FLAG ] ) ) {
			return $args; // Idempotent: already wrapped.
		}

		if ( $this->isExcluded( $name ) ) {
			return $args; // Dispatcher/meta abilities are never wrapped; no flag written.
		}

		if ( ScopeResolver::DEFAULT !== ScopeResolver::resolve( $args, $name ) || ! ( $this->is_multisite )() ) {
			return $args; // Non-site scope, or single-site: no flag written (F6).
		}

		return $this->decorateForMultisite( $args );
	}

	/**
	 * The site-scoped, multisite branch. Public so it is unit-tested directly.
	 *
	 * Inject-or-don't-wrap is atomic: when there is no schema to inject `blog_id` into,
	 * the callbacks are NOT wrapped and no flag is written (F2).
	 *
	 * @param array<string,mixed> $args The ability's registration args.
	 * @return array<string,mixed> The decorated args (or the input unchanged on a no-op).
	 */
	public function decorateForMultisite( array $args ): array {
		$injected = $this->injectBlogId( $args );
		if ( ! $injected['added'] ) {
			return $args; // No schema to inject into -> do NOT wrap (F2 atomicity).
		}

		$args                               = $this->appendMultisiteHint( $injected['args'] );
		$args                               = $this->wrapCallbacks( $args );
		$args['meta'][ self::WRAPPED_FLAG ] = true;

		return $args;
	}

	/**
	 * Reports whether an ability is a dispatcher/meta ability that must not be wrapped.
	 *
	 * Excludes both the mcp-adapter dispatcher abilities and the catalog's own
	 * dispatcher abilities (e.g. the `SearchServer` overview/search/describe/execute),
	 * which are routing surfaces, not site-scoped operations (F5).
	 *
	 * @param string $name The ability name.
	 * @return bool True when the ability must be excluded from decoration.
	 */
	private function isExcluded( string $name ): bool {
		return 0 === strpos( $name, 'mcp-adapter/' )
			|| 0 === strpos( $name, 'abilities-catalog/' );
	}

	/**
	 * Injects an optional `blog_id` property into an object input schema.
	 *
	 * No-ops (returns `added => false`) when the schema is absent, is not an object,
	 * or already owns a `blog_id` property. On success `properties` is a non-empty PHP
	 * array, which serializes as a JSON object (F1). `required` is never touched.
	 *
	 * @param array<string,mixed> $args The ability's registration args.
	 * @return array{args: array<string,mixed>, added: bool} The args and whether `blog_id` was added.
	 */
	private function injectBlogId( array $args ): array {
		$schema = $args['input_schema'] ?? null;
		if ( ! is_array( $schema ) || ( $schema['type'] ?? null ) !== 'object' ) {
			return array(
				'args'  => $args,
				'added' => false,
			);
		}

		$props = $schema['properties'] ?? array();
		$props = is_array( $props ) ? $props : (array) $props;

		if ( isset( $props['blog_id'] ) ) {
			// The ability owns a blog_id field already: leave its schema, do NOT wrap.
			return array(
				'args'  => $args,
				'added' => false,
			);
		}

		$props['blog_id'] = array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => __( 'Optional. On multisite, the site (blog) ID to target. Omit to act on the current site. Discover IDs with og-users/list-my-sites, or og-network/list-sites if you are a super admin.', 'abilities-catalog' ),
		);

		$schema['properties'] = $props; // Non-empty array -> serializes as object; we are the last mutator.
		$args['input_schema'] = $schema;

		return array(
			'args'  => $args,
			'added' => true,
		);
	}

	/**
	 * Appends the one-line multisite hint to the ability description.
	 *
	 * @param array<string,mixed> $args The ability's registration args.
	 * @return array<string,mixed> The args with the hint appended.
	 */
	private function appendMultisiteHint( array $args ): array {
		$description = (string) ( $args['description'] ?? '' );
		$hint        = __( ' On multisite, pass blog_id to target a specific site; discover site IDs with og-users/list-my-sites (or og-network/list-sites as a super admin). Omit blog_id to act on the current site.', 'abilities-catalog' );

		$args['description'] = trim( $description . $hint );

		return $args;
	}

	/**
	 * Wraps the permission and execute callbacks so each runs inside a balanced switch.
	 *
	 * @param array<string,mixed> $args The ability's registration args.
	 * @return array<string,mixed> The args with wrapped callbacks.
	 */
	private function wrapCallbacks( array $args ): array {
		$runner = $this->runner;
		$perm   = $args['permission_callback'] ?? null;
		$exec   = $args['execute_callback'] ?? null;

		if ( is_callable( $perm ) ) {
			$args['permission_callback'] = static function ( $input = null ) use ( $runner, $perm ) {
				return $runner->run( $input, $perm );
			};
		}

		if ( is_callable( $exec ) ) {
			$args['execute_callback'] = static function ( $input = null ) use ( $runner, $exec ) {
				return $runner->run( $input, $exec );
			};
		}

		return $args;
	}
}
