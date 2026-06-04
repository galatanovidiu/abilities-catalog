<?php
/**
 * Unit tests for the writable-option allow-list.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Unit\Support;

use GalatanOvidiu\AbilitiesCatalog\Support\OptionAllowList;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * The write allow-list is deny-by-default. Site-defining, security-sensitive, and
 * serialized options must never be writable through settings/update-option.
 */
final class OptionAllowListTest extends TestCase {

	/**
	 * @dataProvider allowedNames
	 */
	public function test_allowed_option_is_writable(string $name): void {
		$this->assertTrue(OptionAllowList::isAllowed($name));
	}

	/**
	 * @dataProvider deniedNames
	 */
	public function test_dangerous_option_is_not_writable(string $name): void {
		$this->assertFalse(OptionAllowList::isAllowed($name));
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function allowedNames(): array {
		return array(
			'blogname'        => array('blogname'),
			'blogdescription' => array('blogdescription'),
			'posts_per_page'  => array('posts_per_page'),
		);
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function deniedNames(): array {
		return array(
			'siteurl'        => array('siteurl'),
			'home'           => array('home'),
			'active_plugins' => array('active_plugins'),
			'template'       => array('template'),
			'stylesheet'     => array('stylesheet'),
			'user_roles'     => array('wp_user_roles'),
			'unknown'        => array('some_random_option'),
		);
	}
}
