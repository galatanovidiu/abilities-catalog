<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deny-by-default READ allow-list for the generic `settings/get-option` ability.
 *
 * Only the options named in {@see self::ALLOWED} may be read through the generic
 * option-read ability. The list contains ONLY scalar, non-secret option names.
 * Secret-bearing options are deliberately excluded, including `mailserver_pass`,
 * `mailserver_login`, `mailserver_url`, `mailserver_port`, and anything matching
 * `*_pass`, `*_secret`, `*_key`, or `*_token`. Because it is deny-by-default, any
 * name not on the list cannot be read.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability.
 *
 * @since 0.4.0
 */
final class ReadableOptionAllowList {

	/**
	 * The option names a generic option read may target.
	 *
	 * @var array<int,string>
	 */
	public const ALLOWED = array(
		'blogname',
		'blogdescription',
		'siteurl',
		'home',
		'admin_email',
		'timezone_string',
		'gmt_offset',
		'date_format',
		'time_format',
		'start_of_week',
		'blog_public',
		'posts_per_page',
		'posts_per_rss',
		'default_category',
		'default_comment_status',
		'default_ping_status',
		'comment_moderation',
		'comment_registration',
		'default_role',
		'users_can_register',
		'show_on_front',
		'page_on_front',
		'page_for_posts',
		'permalink_structure',
		'template',
		'stylesheet',
		'WPLANG',
		'blog_charset',
		'default_post_format',
		'uploads_use_yearmonth_folders',
		'thumbnail_size_w',
		'thumbnail_size_h',
		'medium_size_w',
		'medium_size_h',
		'large_size_w',
		'large_size_h',
	);

	/**
	 * Returns whether an option name is on the read allow-list.
	 *
	 * @param string $name The option name to check.
	 * @return bool True only when the name is explicitly allowed.
	 */
	public static function isAllowed( string $name ): bool {
		return in_array( $name, self::ALLOWED, true );
	}
}
