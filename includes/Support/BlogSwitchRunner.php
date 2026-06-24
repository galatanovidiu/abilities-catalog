<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs an ability callback inside a balanced blog switch.
 *
 * On multisite, the `PolicyDecorator` wraps a site-scoped ability's permission and
 * execute callbacks so a caller can target a specific site (blog) by passing an
 * optional `blog_id`. This value object holds the switch primitive and owns the
 * balance discipline:
 *
 * - It validates the target site BEFORE switching, returning a recovery-oriented
 *   `WP_Error` (never `false`) so the vendor permission path surfaces the real reason.
 * - It strips `blog_id` from the input before calling the body, because the decorated
 *   schema is `additionalProperties:false` and the original body must never see it.
 * - It restores the previous blog in a `finally`, so the switch is balanced on a value
 *   return, a `WP_Error` return, AND a thrown exception.
 *
 * Site lookup is a constructor-injectable seam (defaulting to core `get_site()` /
 * `get_current_network_id()`). `validateTarget()` reads the looked-up site
 * structurally (its `archived` / `deleted` / `spam` / `network_id` fields), so it never
 * needs a real `WP_Site` — which lets `run()` and `validateTarget()` be unit-tested with
 * no DB, no real network, and no multisite-only core class loaded.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability.
 *
 * @since 0.4.0
 */
final class BlogSwitchRunner {

	/**
	 * The switch primitive (core wrapper in production, counting fake in tests).
	 *
	 * @var \GalatanOvidiu\AbilitiesCatalog\Support\BlogSwitcher
	 */
	private BlogSwitcher $switcher;

	/**
	 * Resolves a site (blog) ID to a `WP_Site` or null. Seam over core `get_site()`.
	 *
	 * @var callable(int):(\WP_Site|null)
	 */
	private $site_lookup;

	/**
	 * Resolves the current network ID. Seam over core `get_current_network_id()`.
	 *
	 * @var callable():int
	 */
	private $current_network_id;

	/**
	 * @param \GalatanOvidiu\AbilitiesCatalog\Support\BlogSwitcher $switcher The switch primitive.
	 * @param callable(int):(\WP_Site|null)|null $site_lookup        Optional site lookup;
	 *                                                       defaults to core `get_site()`.
	 * @param callable():int|null        $current_network_id Optional current-network resolver;
	 *                                                       defaults to core
	 *                                                       `get_current_network_id()`.
	 */
	public function __construct(
		BlogSwitcher $switcher,
		?callable $site_lookup = null,
		?callable $current_network_id = null
	) {
		$this->switcher           = $switcher;
		$this->site_lookup        = $site_lookup ?? static function ( int $blog_id ) {
			return get_site( $blog_id );
		};
		$this->current_network_id = $current_network_id ?? static function (): int {
			return get_current_network_id();
		};
	}

	/**
	 * Runs `$callback` inside a balanced blog switch.
	 *
	 * With no `blog_id` in the input the callback runs unchanged (the undecorated
	 * behavior). With a `blog_id`, the target is validated and stripped, the switch
	 * happens, and the previous blog is restored in a `finally` no matter how the
	 * callback returns.
	 *
	 * @param mixed    $input    The validated ability input (array; carries `blog_id`).
	 * @param callable $callback The original permission or execute callback.
	 * @return mixed The callback's result, or a `WP_Error` for an invalid `blog_id`.
	 */
	public function run( $input, callable $callback ) {
		$input = is_array( $input ) ? $input : array();

		if ( ! array_key_exists( 'blog_id', $input ) ) {
			return $callback( $input ); // No target: behave as the undecorated ability.
		}

		$blog_id = (int) $input['blog_id'];
		unset( $input['blog_id'] ); // Strip: schema is additionalProperties:false; the body must not see it.

		$error = $this->validateTarget( $blog_id );
		if ( $error instanceof \WP_Error ) {
			return $error; // WP_Error (not false) so the vendor perm path surfaces the real reason.
		}

		$this->switcher->switchTo( $blog_id );
		try {
			return $callback( $input );
		} finally {
			$this->switcher->restore(); // Balanced on return, WP_Error, and exception.
		}
	}

	/**
	 * Rejects an out-of-range, missing, archived, deleted, spam, or cross-network site.
	 *
	 * @param int $blog_id The candidate site (blog) ID.
	 * @return \WP_Error|null A recovery-oriented error, or null when the target is valid.
	 */
	private function validateTarget( int $blog_id ): ?\WP_Error {
		if ( $blog_id <= 0 ) {
			return $this->invalid();
		}

		$site = ( $this->site_lookup )( $blog_id );
		if ( ! is_object( $site ) ) {
			return $this->invalid(); // No such site (core `get_site()` returns null).
		}

		if ( (int) $site->archived || (int) $site->deleted || (int) $site->spam ) {
			return $this->invalid( __( 'That site is not available (archived, deleted, or spam). Discover sites you can act on with users/list-my-sites.', 'abilities-catalog' ) );
		}

		if ( (int) $site->network_id !== ( $this->current_network_id )() ) {
			return $this->invalid( __( 'That site belongs to a different network. Discover sites you can act on with users/list-my-sites.', 'abilities-catalog' ) );
		}

		return null;
	}

	/**
	 * Builds the recovery-oriented invalid-`blog_id` error.
	 *
	 * The message names the discovery tool so an agent can recover (Decision 3).
	 *
	 * @param string|null $message Optional override message.
	 * @return \WP_Error A 404 error coded `abilities_catalog_invalid_blog_id`.
	 */
	private function invalid( ?string $message = null ): \WP_Error {
		return new \WP_Error(
			'abilities_catalog_invalid_blog_id',
			$message ?? __( 'Invalid blog_id: no such site on this network. Discover valid site IDs with users/list-my-sites.', 'abilities-catalog' ),
			array( 'status' => 404 )
		);
	}
}
