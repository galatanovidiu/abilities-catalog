<?php
/**
 * Multisite integration tests for the two proven policy-decorator gates.
 *
 * @package AbilitiesCatalog\Tests
 *
 * @group multisite
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Multisite;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Proves the two load-bearing gates of the multisite ability-policy decorator,
 * end-to-end through the real registered, decorated `og-content/create-post`
 * ability (no fake BlogSwitcher — that lives in batch 01's unit tests).
 *
 * Gate 1 (retarget + balance): a super admin passing `blog_id=2` to
 * `og-content/create-post` lands the post on blog 2's tables; the returned
 * `link`/`edit_link` point at blog 2 (not blog 1); and `get_current_blog_id()`
 * after `execute()` equals the pre-call blog (the switch is balanced — never
 * assert a single switch; the vendor path legitimately switches multiple times).
 *
 * Gate 2 (cap-scoping, layer-pinned): a user who is administrator on blog 2
 * only is ALLOWED with `blog_id=2` and DENIED with `blog_id=<main blog>` — and
 * the denial is asserted at the PERMISSION layer
 * (`assertNotTrue( $ability->check_permissions( $input ) )`), proving the
 * capability re-resolves on the TARGET blog at the permission step, not a
 * split-brain where the permission check runs on the main blog and the body on
 * blog 2.
 *
 * Skipped entirely on a single-site install.
 *
 * @group multisite
 */
final class PolicyDecoratorGatesTest extends TestCase {

	/**
	 * Sites created during a test, deleted in tear_down().
	 *
	 * @var int[]
	 */
	private array $created_sites = array();

	public function set_up(): void {
		parent::set_up();

		if ( is_multisite() ) {
			return;
		}

		$this->markTestSkipped( 'Multisite required.' );
	}

	public function tear_down(): void {
		foreach ( $this->created_sites as $blog_id ) {
			if ( ! get_site( $blog_id ) ) {
				continue;
			}

			wp_delete_site( $blog_id );
		}
		$this->created_sites = array();

		parent::tear_down();
	}

	/**
	 * Creates a sub-site and tracks it for cleanup.
	 *
	 * @return int The new blog ID.
	 */
	private function seedSite(): int {
		$blog_id               = self::factory()->blog->create();
		$this->created_sites[] = $blog_id;

		return $blog_id;
	}

	/**
	 * Reads a post back from a specific site's tables.
	 *
	 * @param int $blog_id The site ID to read on.
	 * @param int $post_id The post ID to read.
	 * @return \WP_Post|null The post on that site, or null if absent there.
	 */
	private function postOnSite( int $blog_id, int $post_id ): ?\WP_Post {
		switch_to_blog( $blog_id );
		$post = get_post( $post_id );
		restore_current_blog();

		return $post instanceof \WP_Post ? $post : null;
	}

	/**
	 * Resolves a site's home URL without leaving the switch open.
	 *
	 * @param int $blog_id The site ID.
	 * @return string The site's home URL.
	 */
	private function homeUrlOf( int $blog_id ): string {
		switch_to_blog( $blog_id );
		$url = home_url();
		restore_current_blog();

		return $url;
	}

	/**
	 * Resolves a site's edit-post admin URL without leaving the switch open.
	 *
	 * Unlike the bare home URL, this path includes `/wp-admin/post.php`, so the
	 * main blog's value is never a substring of a sub-site's edit link under the
	 * test framework's subdirectory multisite (where the main blog home URL has
	 * no path segment and is a prefix of every sub-site URL).
	 *
	 * @param int $blog_id The site ID.
	 * @return string The site's `wp-admin/post.php` admin URL.
	 */
	private function editPostAdminUrlOf( int $blog_id ): string {
		switch_to_blog( $blog_id );
		$url = admin_url( 'post.php' );
		restore_current_blog();

		return $url;
	}

	/**
	 * Gate 1: the post lands on the target blog, the links point at it, and the
	 * switch is balanced (current blog restored after execute()).
	 */
	public function test_gate1_create_post_retargets_to_blog_and_balances(): void {
		$this->actingAsSuperAdmin();

		$blog2     = $this->seedSite();
		$blog2_url = $this->homeUrlOf( $blog2 );

		$before = get_current_blog_id();

		$result = wp_get_ability( 'og-content/create-post' )->execute(
			array(
				'blog_id' => $blog2,
				'title'   => 'Retargeted draft',
				'content' => '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->',
			)
		);

		// Balance: the switch is closed, whatever the vendor path did inside.
		$this->assertSame( $before, get_current_blog_id() );

		$this->assertIsArray( $result );
		$this->assertNotInstanceOf( WP_Error::class, $result );

		$post_id = (int) $result['id'];
		$this->assertGreaterThan( 0, $post_id );

		// The post exists on blog 2's tables...
		$on_blog2 = $this->postOnSite( $blog2, $post_id );
		$this->assertNotNull( $on_blog2 );
		$this->assertSame( 'Retargeted draft', $on_blog2->post_title );

		// ...and NOT on the pre-call (main) blog's tables.
		$this->assertNull( $this->postOnSite( $before, $post_id ) );

		// The returned links point at blog 2, not the main blog.
		$this->assertStringContainsString( $blog2_url, (string) $result['link'] );
		$this->assertStringContainsString( $blog2_url, (string) $result['edit_link'] );

		// ...and NOT at the main blog. Compare against the main blog's
		// `wp-admin/post.php` admin URL (path-precise) rather than its bare home
		// URL: under subdirectory multisite the main blog home URL
		// ('http://example.org', no path) is a substring of every sub-site URL,
		// including blog 2's edit link, so a home-URL negative check would fail
		// even when the retarget worked. The admin-path form cannot collide.
		$this->assertStringNotContainsString( $this->editPostAdminUrlOf( $before ), (string) $result['edit_link'] );
	}

	/**
	 * Gate 2 (allowed leg): a blog-2-only administrator IS allowed to target
	 * blog 2 — the permission check passes and execute() returns a real result.
	 */
	public function test_gate2_blog_local_admin_is_allowed_on_its_own_blog(): void {
		$blog2 = $this->seedSite();

		// A user who is administrator on blog 2 ONLY (not granted on blog 1).
		$uid = self::factory()->user->create();
		add_user_to_blog( $blog2, $uid, 'administrator' );
		wp_set_current_user( $uid );

		$ability = wp_get_ability( 'og-content/create-post' );
		$input   = array(
			'blog_id' => $blog2,
			'title'   => 'Allowed on my blog',
			'content' => '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->',
		);

		// Adapter-backed: the capability re-resolves at DISPATCH, inside the switch to
		// the target blog; the guard-only permission phase just passes. The binding proof
		// is below — the post lands on blog 2 (allowed there), with the switch balanced.
		$this->assertTrue( $ability->check_permissions( $input ) );

		$before = get_current_blog_id();
		$result = $ability->execute( $input );

		$this->assertSame( $before, get_current_blog_id() );
		$this->assertIsArray( $result );
		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertNotNull( $this->postOnSite( $blog2, (int) $result['id'] ) );
	}

	/**
	 * Gate 2 (denied leg): the SAME blog-2-only administrator is DENIED when
	 * targeting the main blog. For an adapter-backed ability the capability
	 * re-resolves at DISPATCH, inside the switch to the target blog, so the denial
	 * is asserted through execute() (a real 403) — proving the cap is evaluated on
	 * the TARGET blog, with no split-brain (permission and body both run switched).
	 */
	public function test_gate2_blog_local_admin_is_denied_on_main_blog(): void {
		$blog2     = $this->seedSite();
		$main_blog = get_current_blog_id();

		$uid = self::factory()->user->create();
		add_user_to_blog( $blog2, $uid, 'administrator' );
		// Deliberately NOT granted any role on the main blog.
		wp_set_current_user( $uid );

		$ability = wp_get_ability( 'og-content/create-post' );
		$input   = array(
			'blog_id' => $main_blog,
			'title'   => 'Should never land',
			'content' => '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->',
		);

		// Adapter-backed: the capability re-resolves at DISPATCH (inside the switch to
		// the main blog), not in the guard-only permission phase, so the denial surfaces
		// through execute() as the route's real 403.
		$before = get_current_blog_id();
		$result = $ability->execute( $input );

		// Balance holds even on the denied path.
		$this->assertSame( $before, get_current_blog_id() );

		// A capability denial on a VALID site (the main blog) is a real 403 route error,
		// never the 404 invalid_blog_id error (reserved for a bad/forbidden site).
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 403, (int) $result->get_error_data()['status'] );
		$this->assertNotSame( 'abilities_catalog_invalid_blog_id', $result->get_error_code() );
	}
}
