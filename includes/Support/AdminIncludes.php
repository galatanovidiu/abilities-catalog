<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Support;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Loads `wp-admin/includes/*` files on demand.
 *
 * Several net-new read abilities (update lists, content export, debug data) rely
 * on functions that live in admin-only includes and are not loaded during REST
 * or front-end requests. Ability `execute_callback`s call {@see self::load()}
 * before using those functions so each ability does not reinvent the require.
 *
 * @since 0.1.0
 */
final class AdminIncludes
{
	/**
	 * Requires one or more `wp-admin/includes/<file>.php` files once.
	 *
	 * @param string ...$files File names relative to `wp-admin/includes/`
	 *                         (with or without the `.php` extension).
	 * @return void
	 */
	public static function load(string ...$files): void
	{
		foreach ($files as $file) {
			$file = preg_replace('/\.php$/', '', $file);
			$path = ABSPATH . 'wp-admin/includes/' . $file . '.php';

			if (is_readable($path)) {
				require_once $path;
			}
		}
	}
}
