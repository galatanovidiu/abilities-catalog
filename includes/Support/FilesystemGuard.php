<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Support;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Ensures the filesystem is directly writable before an upgrader runs over WebMCP.
 *
 * Plugin/theme install, update, and delete go through core's `WP_Filesystem`
 * abstraction. On hosts that are not directly writable, core would otherwise emit a
 * credential request form (FTP/SSH prompt). That has no place in a headless WebMCP
 * call: there is no human form to fill in, and any path or credential detail must
 * never reach the in-browser AI agent. This guard requires the `'direct'` method and
 * initializes the filesystem up front (with no credential args) so core never
 * reaches the prompt. On any failure it returns a single fixed, generic error — no
 * path, no credential, no host detail.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability.
 *
 * @since 0.4.0
 */
final class FilesystemGuard
{
	/**
	 * Requires direct filesystem access and initializes `WP_Filesystem`.
	 *
	 * Returns `true` only when the filesystem method is `'direct'` and
	 * `WP_Filesystem()` initializes without credentials. Otherwise returns a generic
	 * `webmcp_fs_not_writable` error. The error message is a fixed string and never
	 * includes any credential or path detail.
	 *
	 * @param string $context Optional target path used to detect the filesystem method.
	 *                        Defaults to the plugins directory when empty.
	 * @return bool|\WP_Error True on success, or a generic error when not directly writable.
	 */
	public static function ensureDirect(string $context = '')
	{
		AdminIncludes::load('file');

		if ('' === $context) {
			$context = WP_PLUGIN_DIR;
		}

		if ('direct' !== get_filesystem_method(array(), $context, false)) {
			return self::error();
		}

		if (false === WP_Filesystem()) {
			return self::error();
		}

		return true;
	}

	/**
	 * Builds the single generic filesystem error.
	 *
	 * Centralizes the fixed message so every failure path returns the same safe
	 * string with no credential or path detail.
	 *
	 * @return \WP_Error The generic not-writable error with a 503 status.
	 */
	private static function error(): \WP_Error
	{
		return new \WP_Error(
			'webmcp_fs_not_writable',
			__('The filesystem is not directly writable, so this operation cannot run over WebMCP. It requires direct filesystem access (no credential prompt).', 'abilities-catalog'),
			array('status' => 503)
		);
	}
}
