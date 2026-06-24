<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A seam over core's blog-switch primitives.
 *
 * `BlogSwitchRunner` switches to a target blog around an ability callback. It talks
 * to this interface instead of `switch_to_blog()` / `restore_current_blog()` directly,
 * so the switch-balance logic is unit-testable with a counting fake (no real network).
 *
 * The production implementation is `CoreBlogSwitcher`, which wraps the core functions.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability.
 *
 * @since 0.4.0
 */
interface BlogSwitcher {

	/**
	 * Switches the active blog to the given site (blog) ID.
	 *
	 * @param int $blog_id The target site (blog) ID.
	 * @return void
	 */
	public function switchTo( int $blog_id ): void;

	/**
	 * Restores the previously active blog.
	 *
	 * @return void
	 */
	public function restore(): void;
}
