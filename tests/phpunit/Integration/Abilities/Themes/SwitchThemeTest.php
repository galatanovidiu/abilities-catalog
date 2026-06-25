<?php
/**
 * Integration tests for the og-themes/switch-theme ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Themes;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the destructive switch: happy path with previous-stylesheet and name
 * capture, 404 for an unknown stylesheet, structured 422 for a broken theme (no
 * `wp_die()`), and empty-input rejection. Each test that creates a theme writes a
 * throwaway directory under the live theme root and restores the original active
 * theme in teardown, so no pre-existing theme is left active.
 */
final class SwitchThemeTest extends TestCase {

	/**
	 * The stylesheet of the throwaway theme created for a test, if any.
	 *
	 * @var string
	 */
	private string $fixture_stylesheet = '';

	/**
	 * The stylesheet that was active before a switch, restored in teardown.
	 *
	 * @var string
	 */
	private string $original_stylesheet = '';

	/**
	 * Creates a minimal installed theme under the live theme root.
	 *
	 * Writes a `style.css` with a Theme Name header via WP_Filesystem and refreshes the
	 * theme cache so `wp_get_theme()` resolves it as an installed, existing theme.
	 *
	 * @param string $stylesheet Theme directory name.
	 * @param string $name       Theme display name.
	 * @return string The absolute theme directory path.
	 */
	private function createTheme( string $stylesheet, string $name ): string {
		global $wp_filesystem;
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();

		$dir = get_theme_root() . '/' . $stylesheet;
		$wp_filesystem->mkdir( $dir );
		$wp_filesystem->put_contents(
			$dir . '/style.css',
			"/*\nTheme Name: {$name}\nVersion: 1.0\n*/\n"
		);
		$wp_filesystem->put_contents( $dir . '/index.php', "<?php\n" );

		$this->fixture_stylesheet = $stylesheet;
		wp_clean_themes_cache();

		return $dir;
	}

	/**
	 * Restores the original active theme and removes the throwaway directory.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		if ( '' !== $this->original_stylesheet && get_stylesheet() !== $this->original_stylesheet ) {
			switch_theme( $this->original_stylesheet );
		}
		$this->original_stylesheet = '';

		if ( '' !== $this->fixture_stylesheet ) {
			global $wp_filesystem;
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();

			$dir = get_theme_root() . '/' . $this->fixture_stylesheet;
			if ( $wp_filesystem->is_dir( $dir ) ) {
				$wp_filesystem->delete( $dir, true );
			}
			$this->fixture_stylesheet = '';
			wp_clean_themes_cache();
		}

		parent::tear_down();
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-themes/switch-theme' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-themes/switch-theme', $ability->get_name() );
	}

	public function test_admin_switches_theme_and_returns_previous_and_name(): void {
		$this->actingAs( 'administrator' );

		$this->original_stylesheet = get_stylesheet();
		$this->createTheme( 'catalog-switch-target', 'Catalog Switch Target' );

		$result = wp_get_ability( 'og-themes/switch-theme' )->execute(
			array( 'stylesheet' => 'catalog-switch-target' )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'catalog-switch-target', $result['active_theme'] );
		$this->assertSame( $this->original_stylesheet, $result['previous_stylesheet'] );
		$this->assertSame( 'Catalog Switch Target', $result['name'] );
		$this->assertSame( 'catalog-switch-target', get_stylesheet() );
	}

	public function test_unknown_stylesheet_returns_not_found(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-themes/switch-theme' )->execute(
			array( 'stylesheet' => 'this-theme-does-not-exist' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'abilities_catalog_theme_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_broken_theme_returns_structured_error(): void {
		$this->actingAs( 'administrator' );

		// A child theme whose declared parent (template) is missing is "broken":
		// it exists() but errors() reports a non-`theme_not_found` error.
		global $wp_filesystem;
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();

		$dir = get_theme_root() . '/catalog-broken-child';
		$wp_filesystem->mkdir( $dir );
		$wp_filesystem->put_contents(
			$dir . '/style.css',
			"/*\nTheme Name: Catalog Broken Child\nTemplate: catalog-missing-parent\nVersion: 1.0\n*/\n"
		);
		$this->fixture_stylesheet = 'catalog-broken-child';
		wp_clean_themes_cache();

		$result = wp_get_ability( 'og-themes/switch-theme' )->execute(
			array( 'stylesheet' => 'catalog-broken-child' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'abilities_catalog_theme_not_found', $result->get_error_code() );
		$this->assertIsArray( $result->get_error_data() );
		$this->assertSame( 422, $result->get_error_data()['status'] );
		// The site stays on its original theme.
		$this->assertNotSame( 'catalog-broken-child', get_stylesheet() );
	}

	public function test_empty_stylesheet_is_rejected_as_invalid_input(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-themes/switch-theme' )->execute(
			array( 'stylesheet' => '' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}
}
