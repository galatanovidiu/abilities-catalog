<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deny-by-default allow-list for the generic `og-settings/update-option` ability.
 *
 * Only the options named in {@see self::ALLOWED} may be written through the generic
 * option-update ability. Everything else is refused, explicitly including
 * `siteurl`, `home`, `active_plugins`, `template`, `stylesheet`, `user_roles`,
 * `db_version`, `default_role`, `users_can_register`, and `cron`, plus any
 * serialized value. Each allowed option keeps its own core-registered sanitize
 * callback when written via `update_option`, so the value is still cleaned by core.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability.
 *
 * @since 0.4.0
 */
final class OptionAllowList {

	/**
	 * The option names a generic option write may target.
	 *
	 * @var array<int,string>
	 */
	public const ALLOWED = array(
		'blogname',
		'blogdescription',
		'timezone_string',
		'gmt_offset',
		'date_format',
		'time_format',
		'start_of_week',
		'blog_public',
		'posts_per_page',
	);

	/**
	 * Returns whether an option name is on the allow-list.
	 *
	 * @param string $name The option name to check.
	 * @return bool True only when the name is explicitly allowed.
	 */
	public static function isAllowed( string $name ): bool {
		return in_array( $name, self::ALLOWED, true );
	}
}
