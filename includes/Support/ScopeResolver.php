<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The single source of truth for an ability's policy scope.
 *
 * An ability declares its multisite policy scope in
 * `meta.abilities_catalog.scope`. There are four scopes:
 *
 * - `site` (the silent default): per-site CRUD. The decorator injects an optional
 *   `blog_id` and switches around the callbacks. On single-site this is a no-op.
 * - `network`: the ability owns its own targeting (e.g. `network/*`).
 * - `user`: network-global user identity / current user.
 * - `global`: operates on the install/network as a whole.
 *
 * Both the `PolicyDecorator` and the RegistryTest write-guard resolve scope through
 * this class, so there is exactly one source of truth (Decision 9).
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability.
 *
 * @since 0.4.0
 */
final class ScopeResolver {

	/**
	 * The recognized policy scopes.
	 *
	 * @var array<int,string>
	 */
	public const SCOPES = array( 'site', 'network', 'user', 'global' );

	/**
	 * The silent default scope when none is declared.
	 *
	 * @var string
	 */
	public const DEFAULT = 'site';

	/**
	 * Resolves an ability's effective policy scope.
	 *
	 * Reads the declared scope and falls back to the default for a missing,
	 * non-string, or unrecognized value, so callers always get a valid scope.
	 *
	 * @param array<string,mixed> $args The ability's registration args.
	 * @param string              $name The ability name (reserved for future use).
	 * @return string One of `site`, `network`, `user`, or `global`.
	 */
	public static function resolve( array $args, string $name ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- `$name` is part of the shared resolver contract (decorator + write-guard call site) and reserved for name-based policy.
		$scope = $args['meta']['abilities_catalog']['scope'] ?? self::DEFAULT;
		$scope = is_string( $scope ) ? $scope : self::DEFAULT;

		return in_array( $scope, self::SCOPES, true ) ? $scope : self::DEFAULT;
	}

	/**
	 * Returns the scope the author explicitly declared, or null when absent.
	 *
	 * The write-guard uses this to enforce "the author stated the scope" on writes,
	 * which `resolve()` cannot express because it defaults silently. A declared value
	 * that is non-string or unrecognized is treated as not declared (null).
	 *
	 * @param array<string,mixed> $args The ability's registration args.
	 * @param string              $name The ability name (reserved for future use).
	 * @return string|null The declared scope, or null when no valid `scope` line exists.
	 */
	public static function declaredScope( array $args, string $name ): ?string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- `$name` mirrors `resolve()` so both halves of the resolver share one signature; reserved for name-based policy.
		if ( ! isset( $args['meta']['abilities_catalog']['scope'] ) ) {
			return null;
		}

		$scope = $args['meta']['abilities_catalog']['scope'];
		if ( ! is_string( $scope ) || ! in_array( $scope, self::SCOPES, true ) ) {
			return null;
		}

		return $scope;
	}
}
