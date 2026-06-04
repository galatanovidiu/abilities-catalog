<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Support;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * The shared upgrader-run path for T3 install/update/delete abilities.
 *
 * Wraps a callback in the common sequence: require a directly writable filesystem
 * ({@see FilesystemGuard}), acquire the serialized upgrader lock
 * ({@see UpgraderLock}), run the callback, then always release the lock. Abilities
 * supply the upgrader call as the callback and get back its result or the first
 * guard error.
 *
 * Maintenance-mode honesty: core upgraders toggle the `.maintenance` flag file
 * themselves during `run()`. On the live request path that flag is cleared on both
 * success and exception, so a normal run never leaves the site stuck. If the worker
 * process is killed mid-update, this runner cannot clear it — the only backstop is
 * core's 10-minute `.maintenance` auto-expiry. This runner does not and cannot
 * prevent that brief stuck window on a killed worker.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability.
 *
 * @since 0.4.0
 */
final class UpgradeRunner
{
	/**
	 * Runs a callback behind the filesystem guard and the upgrader lock.
	 *
	 * Returns the first guard error if the filesystem is not directly writable or
	 * the lock cannot be acquired; otherwise returns the callback's result. The lock
	 * is always released, even when the callback throws.
	 *
	 * @param string   $fsContext Target path passed to {@see FilesystemGuard::ensureDirect()}.
	 * @param callable $callback  The upgrader call to run while the lock is held.
	 * @return mixed|\WP_Error The callback result, or a guard error.
	 */
	public static function withLock(string $fsContext, callable $callback)
	{
		$fs = FilesystemGuard::ensureDirect($fsContext);
		if (is_wp_error($fs)) {
			return $fs;
		}

		$lock = UpgraderLock::acquire();
		if (is_wp_error($lock)) {
			return $lock;
		}

		try {
			return $callback();
		} finally {
			UpgraderLock::release();
		}
	}

	/**
	 * Returns a quiet upgrader skin for headless runs.
	 *
	 * `Automatic_Upgrader_Skin` captures upgrader feedback instead of echoing it,
	 * which is required in a REST/headless context where there is no admin screen to
	 * print to.
	 *
	 * @return \WP_Upgrader_Skin A non-echoing upgrader skin.
	 */
	public static function skin(): \WP_Upgrader_Skin
	{
		AdminIncludes::load('class-wp-upgrader', 'class-automatic-upgrader-skin');

		return new \Automatic_Upgrader_Skin();
	}
}
