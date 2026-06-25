<?php
/**
 * Integration tests for og-templates/update-global-styles output and contract.
 *
 * Covers a happy-path update returning the shaped output (id plus the stored
 * title/settings/styles, settings/styles cast to objects), the wrong-capability
 * denial (a subscriber lacks edit_post on the global-styles post), the custom-CSS
 * gate (a styles.css write is rejected without edit_css), the missing/invalid id
 * error (not collapsed to a permission failure), and the output-shape guarantee
 * that empty settings/styles serialize as `{}` objects.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Templates;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;
use WP_Theme_JSON_Resolver;

/**
 * Exercises og-templates/update-global-styles.
 */
final class UpdateGlobalStylesTest extends TestCase {

	/**
	 * Resolves the active theme's user global-styles post id, creating it if needed.
	 *
	 * The resolver caches the id in a static. The per-test DB rollback removes the
	 * underlying post but not the cache, so a stale id would point at a deleted
	 * post (and fail edit_post). Clearing the cache first forces a fresh resolve
	 * (and create) against the current transaction.
	 *
	 * @return int The global-styles post id.
	 */
	private function globalStylesId(): int {
		WP_Theme_JSON_Resolver::clean_cached_data();

		return (int) WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
	}

	public function test_update_returns_shaped_output_with_title_and_sections(): void {
		$this->actingAs( 'administrator' );

		$id = $this->globalStylesId();
		$this->assertGreaterThan( 0, $id );

		$result = wp_get_ability( 'og-templates/update-global-styles' )->execute(
			array(
				'id'       => $id,
				'title'    => 'Catalog Styles',
				'settings' => array( 'color' => array( 'custom' => true ) ),
				'styles'   => array(
					'color' => array( 'background' => '#112233' ),
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $id, $result['id'] );
		$this->assertSame( 'Catalog Styles', $result['title'] );
		// settings/styles are cast to objects so they serialize as `{}` when empty.
		$this->assertIsObject( $result['settings'] );
		$this->assertIsObject( $result['styles'] );
		// The stored styles reflect the wholesale replacement we sent.
		$this->assertSame( '#112233', $result['styles']->color['background'] );
	}

	public function test_output_shape_only_declared_keys(): void {
		$this->actingAs( 'administrator' );

		$id = $this->globalStylesId();

		$result = wp_get_ability( 'og-templates/update-global-styles' )->execute(
			array(
				'id'    => $id,
				'title' => 'Shape',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			array( 'id', 'title', 'settings', 'styles' ),
			array_keys( $result )
		);
	}

	public function test_empty_sections_serialize_as_objects(): void {
		$this->actingAs( 'administrator' );

		$id = $this->globalStylesId();

		// Clearing both sections leaves empty objects, which must encode as `{}`.
		$result = wp_get_ability( 'og-templates/update-global-styles' )->execute(
			array(
				'id'       => $id,
				'settings' => array(),
				'styles'   => array(),
			)
		);

		$this->assertIsArray( $result );
		$this->assertStringStartsWith( '{', (string) wp_json_encode( $result['settings'] ) );
		$this->assertStringStartsWith( '{', (string) wp_json_encode( $result['styles'] ) );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$id      = $this->globalStylesId();
		$ability = wp_get_ability( 'og-templates/update-global-styles' );

		// A subscriber lacks edit_post on the global-styles post.
		$this->assertFalse(
			$ability->check_permissions( array( 'id' => $id ) )
		);

		$result = $ability->execute( array( 'id' => $id ) );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_custom_css_without_edit_css_is_denied(): void {
		$this->actingAs( 'administrator' );

		$id      = $this->globalStylesId();
		$ability = wp_get_ability( 'og-templates/update-global-styles' );

		// Strip unfiltered_html (the meta cap edit_css maps to) for this request,
		// so the user keeps edit_post on the post but loses the edit_css gate.
		$deny_css = static function ( array $allcaps ): array {
			unset( $allcaps['unfiltered_html'] );
			return $allcaps;
		};
		add_filter( 'user_has_cap', $deny_css );

		try {
			// A styles.css key now requires edit_css, which the user no longer has.
			$this->assertFalse(
				$ability->check_permissions(
					array(
						'id'     => $id,
						'styles' => array( 'css' => 'body{color:red}' ),
					)
				)
			);

			// The gate fires on key presence, even for an explicit empty css.
			$this->assertFalse(
				$ability->check_permissions(
					array(
						'id'     => $id,
						'styles' => array( 'css' => '' ),
					)
				)
			);

			// Without the css key the same user may still update.
			$this->assertTrue(
				$ability->check_permissions( array( 'id' => $id ) )
			);
		} finally {
			remove_filter( 'user_has_cap', $deny_css );
		}
	}

	public function test_invalid_id_surfaces_route_error_not_generic(): void {
		$this->actingAs( 'administrator' );

		// With the coarse edit_theme_options guard, a non-existent id reaches the route,
		// which returns its specific error instead of the generic ability_invalid_permissions
		// the object-level pre-check produced (resolving the B2 permission-contract decision).
		$result = wp_get_ability( 'og-templates/update-global-styles' )->execute(
			array( 'id' => 999999 )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
