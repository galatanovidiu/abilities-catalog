<?php
/**
 * Integration tests for the themes/delete-theme ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Themes;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the dangerous-tier delete: happy path with name capture, 404 for an
 * unknown stylesheet, and 409 for the active theme. Each happy-path test creates a
 * throwaway theme under the live theme root and asserts it is removed from disk, so
 * no pre-existing theme is touched.
 */
final class DeleteThemeTest extends TestCase {

	/**
	 * The stylesheet of the throwaway theme created for a test, if any.
	 *
	 * @var string
	 */
	private string $fixture_stylesheet = '';

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
	 * Removes the throwaway theme directory if the ability did not.
	 *
	 * @return void
	 */
	public function tear_down(): void {
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
		$ability = wp_get_ability( 'themes/delete-theme' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'themes/delete-theme', $ability->get_name() );
	}

	public function test_admin_deletes_theme_and_returns_name(): void {
		$this->actingAs( 'administrator' );

		$dir = $this->createTheme( 'catalog-throwaway', 'Catalog Throwaway' );

		$result = wp_get_ability( 'themes/delete-theme' )->execute(
			array( 'stylesheet' => 'catalog-throwaway' )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( 'catalog-throwaway', $result['stylesheet'] );
		$this->assertSame( 'Catalog Throwaway', $result['name'] );
		$this->assertDirectoryDoesNotExist( $dir );
	}

	public function test_unknown_stylesheet_returns_not_found(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'themes/delete-theme' )->execute(
			array( 'stylesheet' => 'this-theme-does-not-exist' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'abilities_catalog_theme_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_active_theme_is_refused_as_in_use(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'themes/delete-theme' )->execute(
			array( 'stylesheet' => get_stylesheet() )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'abilities_catalog_theme_in_use', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
	}

	public function test_empty_stylesheet_is_rejected_as_invalid_input(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'themes/delete-theme' )->execute(
			array( 'stylesheet' => '' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}
}
