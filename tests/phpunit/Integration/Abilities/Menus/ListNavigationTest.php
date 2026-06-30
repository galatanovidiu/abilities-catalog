<?php
/**
 * Integration tests for the og-menus/list-navigation ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Menus;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * og-menus/list-navigation wraps `GET /wp/v2/navigation` and projects each
 * `wp_navigation` post into a flat, closed summary row via MenuListShaper.
 * The serialized block body (`content`) is dropped from the list row.
 * edit_theme_options is the coarse capability guard.
 */
final class ListNavigationTest extends TestCase {

	/**
	 * The full set of keys a summary row may carry.
	 *
	 * @var string[]
	 */
	private const ROW_KEYS = array(
		'id',
		'title',
		'status',
		'slug',
		'link',
		'date',
		'modified',
		'edit_link',
	);

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-menus/list-navigation' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-menus/list-navigation', $ability->get_name() );
	}

	public function test_admin_lists_navigation_with_totals(): void {
		$this->actingAs( 'administrator' );
		$this->seedNavigation( 'Primary Navigation' );

		$result = wp_get_ability( 'og-menus/list-navigation' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertArrayHasKey( 'total_pages', $result );
		$this->assertSame( 1, $result['total'] );
		$this->assertCount( 1, $result['items'] );
	}

	public function test_rows_are_flat_and_closed_and_drop_content(): void {
		$this->actingAs( 'administrator' );
		$this->seedNavigation( 'Primary Navigation' );

		$result = wp_get_ability( 'og-menus/list-navigation' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['items'] );

		// items must be a plain list, not a keyed map.
		$this->assertSame( array_keys( $result['items'] ), range( 0, count( $result['items'] ) - 1 ) );

		foreach ( $result['items'] as $row ) {
			// Exactly the declared flat set, in order: no _links, no content body.
			$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
			$this->assertArrayNotHasKey( 'content', $row );
			$this->assertIsInt( $row['id'] );
			$this->assertIsString( $row['title'] );
			$this->assertIsString( $row['status'] );
		}
	}

	/**
	 * A subscriber is blocked by the ability's `require_permission`
	 * (`edit_theme_options`) floor. A bare `execute()` collapses that floor's
	 * denial to the generic `ability_invalid_permissions` code (the floor fires in
	 * the ability's permission phase, before dispatch), so the assertion is
	 * unchanged from the pre-adapter contract.
	 */
	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		// The catalog keeps a stricter edit_theme_options floor via require_permission
		// (the list route is more permissive). A guard denial is normalized to a
		// WP_Error, which WP_Ability::execute() collapses to ability_invalid_permissions
		// and flags with a _doing_it_wrong notice — expected here.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$result = wp_get_ability( 'og-menus/list-navigation' )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Creates a block-based navigation menu (`wp_navigation` post).
	 *
	 * @param string $title Navigation menu title.
	 * @return int The created navigation post ID.
	 */
	private function seedNavigation( string $title ): int {
		return (int) wp_insert_post(
			array(
				'post_type'    => 'wp_navigation',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => '<!-- wp:navigation-link {"label":"Home","url":"https://example.com"} /-->',
			)
		);
	}
}
