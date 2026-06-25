<?php
/**
 * Multisite per-storage-backend spot-checks for the blog_id retarget decorator.
 *
 * @package AbilitiesCatalog\Tests
 *
 * @group multisite
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Multisite;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Theme_JSON_Resolver;

/**
 * Spot-checks that the site-scoped decorator retargets each distinct storage
 * backend to the site named by blog_id, not the current (blog 1) site.
 *
 * One method per backend, each acting as super admin, each passing
 * `blog_id => $blog2` to a real registered, decorated ability, then reading the
 * result back under `switch_to_blog( $blog2 )` to prove it landed on blog 2 and
 * is absent on blog 1. Every switching call is also asserted balanced:
 * `get_current_blog_id()` equals the pre-call blog (the vendor path legitimately
 * switches multiple times, so the contract is balance, never "single switch").
 *
 * Backends covered: term tables (og-terms/create-term), CPT + theme_mods option
 * (og-templates/update-global-styles), an option array (og-widgets/create-widget,
 * which writes the widget_block option), a plain option (og-settings/update-option),
 * the per-site cron option (og-cron/schedule-event), and post-meta on a post created
 * on blog 2 (og-content/update-post-meta). media/upload is deliberately excluded
 * (per-blog upload-path isolation is flaky under wp-env FS_METHOD=direct).
 *
 * @group multisite
 */
final class RestRetargetBackendsTest extends TestCase {

	/**
	 * Sites created during a test, deleted in tear_down().
	 *
	 * @var int[]
	 */
	private array $created_sites = array();

	/**
	 * Cron hooks scheduled during a test, per blog, cleared in tear_down().
	 *
	 * @var array<int,string[]>
	 */
	private array $scheduled_hooks = array();

	public function set_up(): void {
		parent::set_up();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite required.' );
		}
	}

	public function tear_down(): void {
		// Clear any cron event a test scheduled, on the blog it was scheduled on.
		foreach ( $this->scheduled_hooks as $blog_id => $hooks ) {
			if ( ! get_site( $blog_id ) ) {
				continue;
			}
			switch_to_blog( $blog_id );
			foreach ( $hooks as $hook ) {
				wp_clear_scheduled_hook( $hook );
			}
			restore_current_blog();
		}
		$this->scheduled_hooks = array();

		foreach ( $this->created_sites as $blog_id ) {
			if ( get_site( $blog_id ) ) {
				wp_delete_site( $blog_id );
			}
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
	 * Records a cron hook for cleanup on a given blog.
	 *
	 * @param int    $blog_id The blog the hook was scheduled on.
	 * @param string $hook    The cron hook name.
	 */
	private function trackCronHook( int $blog_id, string $hook ): void {
		$this->scheduled_hooks[ $blog_id ][] = $hook;
	}

	/**
	 * TERM TABLES: og-terms/create-term lands the term in blog 2's term tables.
	 */
	public function test_create_term_lands_on_target_blog(): void {
		$this->actingAsSuperAdmin();

		$blog2  = $this->seedSite();
		$before = get_current_blog_id();

		$result = wp_get_ability( 'og-terms/create-term' )->execute(
			array(
				'blog_id'  => $blog2,
				'taxonomy' => 'category',
				'name'     => 'Retargeted News',
				'slug'     => 'retargeted-news',
			)
		);

		$this->assertSame( $before, get_current_blog_id(), 'Switch must be balanced after execute.' );

		$this->assertIsArray( $result );
		$term_id = (int) $result['id'];
		$this->assertGreaterThan( 0, $term_id );

		// The term exists in blog 2's term tables.
		switch_to_blog( $blog2 );
		$on_blog2 = get_term( $term_id, 'category' );
		restore_current_blog();
		$this->assertNotNull( $on_blog2 );
		$this->assertNotWPError( $on_blog2 );
		$this->assertSame( 'Retargeted News', $on_blog2->name );

		// It does NOT exist in blog 1's term tables (same id resolves to nothing/other).
		$on_blog1 = get_term( $term_id, 'category' );
		$this->assertTrue(
			null === $on_blog1 || is_wp_error( $on_blog1 ) || 'Retargeted News' !== $on_blog1->name,
			'The term must not be present on blog 1.'
		);
	}

	/**
	 * CPT + theme_mods OPTION: og-templates/update-global-styles writes the
	 * global-styles post (a wp_global_styles CPT) on blog 2.
	 */
	public function test_update_global_styles_lands_on_target_blog(): void {
		$this->actingAsSuperAdmin();

		$blog2  = $this->seedSite();
		$before = get_current_blog_id();

		// Resolve the global-styles post id on blog 2. The resolver caches the id
		// in a static keyed per-theme, so clear it under the switch to force a fresh
		// resolve (and create) against blog 2's posts table.
		switch_to_blog( $blog2 );
		WP_Theme_JSON_Resolver::clean_cached_data();
		$gs_id = (int) WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		restore_current_blog();

		$this->assertGreaterThan( 0, $gs_id );

		$result = wp_get_ability( 'og-templates/update-global-styles' )->execute(
			array(
				'blog_id'  => $blog2,
				'id'       => $gs_id,
				'title'    => 'Blog2 Styles',
				'settings' => array( 'color' => array( 'custom' => true ) ),
				'styles'   => array( 'color' => array( 'background' => '#112233' ) ),
			)
		);

		$this->assertSame( $before, get_current_blog_id(), 'Switch must be balanced after execute.' );

		$this->assertIsArray( $result );
		$this->assertSame( $gs_id, $result['id'] );

		// The global-styles CPT post exists on blog 2 with the written title.
		switch_to_blog( $blog2 );
		$post_on_blog2 = get_post( $gs_id );
		restore_current_blog();
		$this->assertNotNull( $post_on_blog2 );
		$this->assertSame( 'wp_global_styles', $post_on_blog2->post_type );
		$this->assertSame( 'Blog2 Styles', $post_on_blog2->post_title );

		// No such post id on blog 1's posts table (or, if reused, not our styles post).
		$post_on_blog1 = get_post( $gs_id );
		$this->assertTrue(
			null === $post_on_blog1 || 'Blog2 Styles' !== $post_on_blog1->post_title,
			'The global-styles change must not appear on blog 1.'
		);
	}

	/**
	 * OPTION ARRAY: og-widgets/create-widget writes the widget_block option array on
	 * blog 2.
	 */
	public function test_create_widget_lands_on_target_blog(): void {
		$this->actingAsSuperAdmin();

		$blog2  = $this->seedSite();
		$before = get_current_blog_id();

		$marker = 'W3 retarget widget ' . uniqid();

		$result = wp_get_ability( 'og-widgets/create-widget' )->execute(
			array(
				'blog_id'  => $blog2,
				'id_base'  => 'block',
				'sidebar'  => 'wp_inactive_widgets',
				'instance' => array( 'raw' => array( 'content' => '<!-- wp:paragraph --><p>' . $marker . '</p><!-- /wp:paragraph -->' ) ),
			)
		);

		$this->assertSame( $before, get_current_blog_id(), 'Switch must be balanced after execute.' );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['created'] );
		$this->assertNotSame( '', $result['id'] );

		// The widget_block option on blog 2 carries the instance content.
		switch_to_blog( $blog2 );
		$blog2_widgets = get_option( 'widget_block' );
		restore_current_blog();
		$this->assertIsArray( $blog2_widgets );
		$this->assertStringContainsString(
			$marker,
			(string) wp_json_encode( $blog2_widgets ),
			'The widget instance must be stored in blog 2 widget_block option.'
		);

		// Blog 1's widget_block option does not carry the instance.
		$blog1_widgets = get_option( 'widget_block' );
		$this->assertStringNotContainsString(
			$marker,
			(string) wp_json_encode( $blog1_widgets ),
			'The widget instance must not appear in blog 1 widget_block option.'
		);
	}

	/**
	 * OPTION: og-settings/update-option sets the value on blog 2 and leaves blog 1
	 * unchanged.
	 */
	public function test_update_option_lands_on_target_blog(): void {
		$this->actingAsSuperAdmin();

		$blog2  = $this->seedSite();
		$before = get_current_blog_id();

		$blog1_name_before = get_option( 'blogname' );

		$result = wp_get_ability( 'og-settings/update-option' )->execute(
			array(
				'blog_id' => $blog2,
				'name'    => 'blogname',
				'value'   => 'Blog 2 Renamed',
			)
		);

		$this->assertSame( $before, get_current_blog_id(), 'Switch must be balanced after execute.' );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['updated'] );

		// Blog 2's blogname is the new value.
		switch_to_blog( $blog2 );
		$blog2_name = get_option( 'blogname' );
		restore_current_blog();
		$this->assertSame( 'Blog 2 Renamed', $blog2_name );

		// Blog 1's blogname is unchanged.
		$this->assertSame( $blog1_name_before, get_option( 'blogname' ) );
		$this->assertNotSame( 'Blog 2 Renamed', get_option( 'blogname' ) );
	}

	/**
	 * PER-SITE CRON OPTION: og-cron/schedule-event lands the event in blog 2's cron
	 * option, not blog 1's.
	 */
	public function test_schedule_event_lands_on_target_blog(): void {
		$this->actingAsSuperAdmin();

		$blog2  = $this->seedSite();
		$before = get_current_blog_id();

		$hook      = 'abilities_catalog_retarget_evt';
		$timestamp = time() + HOUR_IN_SECONDS;
		$this->trackCronHook( $blog2, $hook );

		$result = wp_get_ability( 'og-cron/schedule-event' )->execute(
			array(
				'blog_id'   => $blog2,
				'hook'      => $hook,
				'timestamp' => $timestamp,
			)
		);

		$this->assertSame( $before, get_current_blog_id(), 'Switch must be balanced after execute.' );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['scheduled'] );

		// The event is in blog 2's per-site cron option.
		switch_to_blog( $blog2 );
		$next_on_blog2 = wp_next_scheduled( $hook );
		restore_current_blog();
		$this->assertSame( $timestamp, $next_on_blog2 );

		// Blog 1's cron option does not carry the event.
		$this->assertFalse( wp_next_scheduled( $hook ), 'The event must not be scheduled on blog 1.' );
	}

	/**
	 * POST-META: og-content/update-post-meta writes meta on a post created on blog 2,
	 * landing in blog 2's postmeta table.
	 */
	public function test_update_post_meta_lands_on_target_blog(): void {
		$this->actingAsSuperAdmin();

		$blog2  = $this->seedSite();
		$before = get_current_blog_id();

		// Create the post on blog 2 and register a show_in_rest meta key there (the
		// posts controller only exposes meta declared on the target site).
		switch_to_blog( $blog2 );
		register_post_meta(
			'post',
			'retarget_subtitle',
			array(
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'string',
			)
		);
		$post_id = self::factory()->post->create();
		restore_current_blog();

		$result = wp_get_ability( 'og-content/update-post-meta' )->execute(
			array(
				'blog_id' => $blog2,
				'id'      => $post_id,
				'meta'    => array( 'retarget_subtitle' => 'Set on blog 2' ),
			)
		);

		$this->assertSame( $before, get_current_blog_id(), 'Switch must be balanced after execute.' );

		$this->assertIsArray( $result );
		$this->assertSame( 'Set on blog 2', ( (array) $result['meta'] )['retarget_subtitle'] );

		// The meta landed in blog 2's postmeta.
		switch_to_blog( $blog2 );
		$meta_on_blog2 = get_post_meta( $post_id, 'retarget_subtitle', true );
		unregister_post_meta( 'post', 'retarget_subtitle' );
		restore_current_blog();
		$this->assertSame( 'Set on blog 2', $meta_on_blog2 );

		// Blog 1 has no such post id / meta (the post lives only on blog 2).
		$this->assertSame( '', get_post_meta( $post_id, 'retarget_subtitle', true ) );
	}
}
