<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The production `BlogSwitcher`: a thin wrapper over core's switch primitives.
 *
 * Wraps `switch_to_blog()` / `restore_current_blog()` (available since WordPress 3.0)
 * so `BlogSwitchRunner` can switch around an ability callback while staying unit-testable
 * through the `BlogSwitcher` seam.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability.
 *
 * @since 0.4.0
 */
final class CoreBlogSwitcher implements BlogSwitcher {

	/**
	 * Switches the active blog via `switch_to_blog()`.
	 *
	 * @param int $blog_id The target site (blog) ID.
	 * @return void
	 */
	public function switchTo( int $blog_id ): void {
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- A DB-context switch is the deliberate purpose of this seam; `BlogSwitchRunner` validates the target and restores it in a `finally`.
		switch_to_blog( $blog_id );
	}

	/**
	 * Restores the previously active blog via `restore_current_blog()`.
	 *
	 * @return void
	 */
	public function restore(): void {
		restore_current_blog();
	}
}
