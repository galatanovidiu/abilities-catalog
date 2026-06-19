<?php
/**
 * The per-ability exposure gate for the MCP server.
 *
 * @package AbilitiesCatalog
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Mcp;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides which abilities the MCP server may execute (spec §16).
 *
 * Capability is the hard authorization guard; this is the second, coarser gate the
 * site owner controls. It is **deny-by-default**: only the ability names the owner
 * has enabled (stored in the {@see ABILITIES_CATALOG_MCP_EXPOSED_OPTION} option, driven
 * by the settings page) may be executed. An ability that is registered but not enabled
 * stays fully visible to `list` and `describe` — the agent can still learn it exists and
 * how to call it — but `execute` refuses it. The gate hides nothing; it only stops the run.
 *
 * The class is transport-agnostic: it knows the option and plain ability names, nothing
 * about MCP, the adapter, or the registry. {@see DomainRouter} consults {@see allows()}
 * before dispatching an `execute`; the settings page reads {@see enabledAbilities()} to
 * render the toggles and calls {@see save()} to persist them. The stored set is resolved
 * once per instance (cached in {@see enabled()}), so a `list` that checks every ability
 * reads the option a single time.
 *
 * @since 0.2.0
 */
final class ExposurePolicy {

	/**
	 * The enabled ability names, resolved from the option on first use and reused.
	 *
	 * Null until resolved, so the option is read once per instance rather than on
	 * every {@see allows()} call during a `list`.
	 *
	 * @var list<string>|null
	 */
	private ?array $enabled = null;

	/**
	 * Reports whether an ability may be executed through the server.
	 *
	 * The single question the gate answers. Deny-by-default: an ability the owner has
	 * not enabled returns false, and {@see DomainRouter::execute()} turns that into a
	 * recoverable "disabled" error rather than running it.
	 *
	 * @param string $ability Full ability name, e.g. `content/get-post`.
	 * @return bool True only when the ability is on the enabled set.
	 */
	public function allows( string $ability ): bool {
		return in_array( $ability, $this->enabled(), true );
	}

	/**
	 * Returns every ability name the owner has enabled, in stored order.
	 *
	 * The settings page reads this to mark which toggles are on.
	 *
	 * @return list<string> The enabled ability names.
	 */
	public function enabledAbilities(): array {
		return $this->enabled();
	}

	/**
	 * Resolves the enabled set from the option, sanitized to a list of strings.
	 *
	 * A malformed option (not an array, or holding non-string members) degrades to the
	 * safe default rather than exposing anything: deny-by-default means a broken option
	 * enables nothing.
	 *
	 * @return list<string> The enabled ability names.
	 */
	private function enabled(): array {
		if ( null !== $this->enabled ) {
			return $this->enabled;
		}

		$this->enabled = self::stored();

		return $this->enabled;
	}

	/**
	 * Reads the persisted enabled set without instance caching.
	 *
	 * The write path ({@see save()}) and the settings page's read both need the current
	 * stored value independent of any policy instance, so this static is the one place
	 * the option is decoded. Non-array or non-string members are dropped.
	 *
	 * @return list<string> The stored enabled ability names.
	 */
	public static function stored(): array {
		$option = get_option( ABILITIES_CATALOG_MCP_EXPOSED_OPTION, array() );

		if ( ! is_array( $option ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$option,
				static fn ( $name ): bool => is_string( $name )
			)
		);
	}

	/**
	 * Applies a set of `{ ability => enabled }` changes to the current enabled set.
	 *
	 * Pure set arithmetic the settings page's partial save relies on: a `true` adds the
	 * ability, a `false` removes it, and an ability the change set does not mention keeps
	 * its current state. Returns a deduplicated list; validity against the registry is the
	 * caller's job ({@see save()} sanitizes before persisting).
	 *
	 * @param list<string>      $current The currently enabled ability names.
	 * @param array<string,bool> $changes Ability name => desired enabled state.
	 * @return list<string> The resulting enabled ability names.
	 */
	public static function applyChanges( array $current, array $changes ): array {
		$set = array();
		foreach ( $current as $name ) {
			if ( ! is_string( $name ) ) {
				continue;
			}

			$set[ $name ] = true;
		}

		foreach ( $changes as $name => $on ) {
			$name = (string) $name;
			if ( $on ) {
				$set[ $name ] = true;
				continue;
			}

			unset( $set[ $name ] );
		}

		return array_map( 'strval', array_keys( $set ) );
	}

	/**
	 * Persists an enabled set after dropping any name that is not a registered ability.
	 *
	 * The settings page hands user-submitted names; this is the single write seam, so the
	 * option name lives in one place and a stale or forged name can never be stored. The
	 * caller supplies the known ability names (typically `array_keys( wp_get_abilities() )`)
	 * to keep this class free of the registry.
	 *
	 * @param list<string> $enabled The candidate enabled ability names.
	 * @param list<string> $known   The registered ability names to validate against.
	 * @return list<string> The set actually stored.
	 */
	public static function save( array $enabled, array $known ): array {
		$stored = self::sanitize( $enabled, $known );

		update_option( ABILITIES_CATALOG_MCP_EXPOSED_OPTION, $stored );

		return $stored;
	}

	/**
	 * Keeps only the names that are real registered abilities, deduplicated.
	 *
	 * Pure counterpart to {@see save()}: it never touches the option or the registry, so
	 * the validation rule is testable on its own. A non-string or unknown name is dropped.
	 *
	 * @param list<string> $enabled The candidate ability names.
	 * @param list<string> $known   The registered ability names to validate against.
	 * @return list<string> The valid, deduplicated subset, in input order.
	 */
	public static function sanitize( array $enabled, array $known ): array {
		$valid = array();
		foreach ( $enabled as $name ) {
			if ( ! is_string( $name ) ) {
				continue;
			}

			if ( ! in_array( $name, $known, true ) ) {
				continue;
			}

			$valid[ $name ] = true;
		}

		return array_map( 'strval', array_keys( $valid ) );
	}
}
