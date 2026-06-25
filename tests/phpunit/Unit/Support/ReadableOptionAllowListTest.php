<?php
/**
 * Unit tests for the readable-option allow-list.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Unit\Support;

use GalatanOvidiu\AbilitiesCatalog\Support\ReadableOptionAllowList;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * The read allow-list is deny-by-default and excludes any secret-bearing option,
 * so og-settings/get-option can never leak a password, key, secret, or token.
 */
final class ReadableOptionAllowListTest extends TestCase {

	/**
	 * @dataProvider readableNames
	 */
	public function test_scalar_option_is_readable(string $name): void {
		$this->assertTrue(ReadableOptionAllowList::isAllowed($name));
	}

	/**
	 * @dataProvider secretNames
	 */
	public function test_secret_bearing_option_is_not_readable(string $name): void {
		$this->assertFalse(ReadableOptionAllowList::isAllowed($name));
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function readableNames(): array {
		return array(
			'blogname'   => array('blogname'),
			'siteurl'    => array('siteurl'),
			'admin_email' => array('admin_email'),
		);
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function secretNames(): array {
		return array(
			'mailserver_pass'  => array('mailserver_pass'),
			'mailserver_login' => array('mailserver_login'),
			'auth_key'         => array('auth_key'),
			'api_secret'       => array('some_api_secret'),
			'access_token'     => array('service_access_token'),
		);
	}
}
