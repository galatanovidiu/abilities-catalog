<?php
/**
 * Bootstrap the PHPUnit tests.
 *
 * Loads the WordPress test environment, then activates the Abilities Catalog
 * plugin so its abilities register on `wp_abilities_api_init`. The Abilities API
 * itself ships in WordPress 6.9 core, so no extra plugin is loaded here.
 *
 * @package AbilitiesCatalog\Tests
 *
 * phpcs:disable WordPressVIPMinimum.Files.IncludingFile.UsingVariable
 */

declare(strict_types=1);

define('TESTS_REPO_ROOT_DIR', dirname(__DIR__, 2));

// Load Composer dev dependencies (PHPUnit polyfills) when present.
if (file_exists(TESTS_REPO_ROOT_DIR . '/vendor/autoload.php')) {
	require_once TESTS_REPO_ROOT_DIR . '/vendor/autoload.php';
}

// Detect where to load the WordPress test environment from.
if (false !== getenv('WP_TESTS_DIR')) {
	$_test_root = getenv('WP_TESTS_DIR');
} elseif (false !== getenv('WP_DEVELOP_DIR')) {
	$_test_root = getenv('WP_DEVELOP_DIR') . '/tests/phpunit';
} elseif (false !== getenv('WP_PHPUNIT__DIR')) {
	$_test_root = getenv('WP_PHPUNIT__DIR');
} elseif (file_exists(TESTS_REPO_ROOT_DIR . '/../../../../../tests/phpunit/includes/functions.php')) {
	$_test_root = TESTS_REPO_ROOT_DIR . '/../../../../../tests/phpunit';
} else { // Fallback.
	$_test_root = '/tmp/wordpress-tests-lib';
}

// Give access to the tests_add_filter() function.
require_once $_test_root . '/includes/functions.php';

// Activate the plugin during the test environment boot.
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		// Use require (not require_once) so the plugin file always loads here.
		require TESTS_REPO_ROOT_DIR . '/abilities-catalog.php';
	}
);

// Start up the WP testing environment.
require $_test_root . '/includes/bootstrap.php';
