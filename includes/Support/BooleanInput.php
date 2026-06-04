<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coerces an arbitrary input value into a boolean.
 *
 * Settings abilities accept boolean fields, but `execute()` may be called
 * directly (server-side, bypassing REST schema coercion), so the raw value can
 * be a real bool, the strings `'false'`/`'0'`, an int, or something unexpected.
 * `rest_sanitize_boolean()` already interprets the bool/string/int forms, but
 * its typed signature (`@phpstan-template T of bool|string|int`) cannot bind a
 * `mixed` argument. This helper narrows the value to that accepted union first,
 * preserving the string interpretation, and falls back to a plain cast for any
 * other type.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability.
 *
 * @since 0.3.0
 */
final class BooleanInput {

	/**
	 * Interprets a value as a boolean, honoring boolean-like strings and ints.
	 *
	 * @param mixed $value The raw input value.
	 * @return bool The interpreted boolean.
	 */
	public static function sanitize( $value ): bool {
		if ( is_bool( $value ) || is_int( $value ) || is_string( $value ) ) {
			return rest_sanitize_boolean( $value );
		}

		return (bool) $value;
	}
}
