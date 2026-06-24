<?php
/**
 * Unit tests for the multisite PolicyDecorator transform.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Unit\Support;

use GalatanOvidiu\AbilitiesCatalog\Support\BlogSwitcher;
use GalatanOvidiu\AbilitiesCatalog\Support\PolicyDecorator;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * The decorator is a pure args->args transform. These tests force the multisite branch
 * through the injected `is_multisite` seam and use a no-op fake `BlogSwitcher`, so they
 * run with no DB and no real network. They pin the load-bearing invariants: atomic
 * inject-or-don't-wrap (F2), the flag only on the wrapped path (F6), dispatcher
 * exclusion (F5), the non-empty `properties` array (F1), and the exactly-once hint.
 */
final class PolicyDecoratorTest extends TestCase {

	private const FLAG = '_abilities_catalog_decorated';

	private const HINT = ' On multisite, pass blog_id to target a specific site; discover site IDs with users/list-my-sites (or network/list-sites as a super admin). Omit blog_id to act on the current site.';

	/**
	 * A site-scoped object schema gets an optional `blog_id`, the hint once, the flag,
	 * and wrapped callbacks; `required` is untouched and `properties` stays a non-empty
	 * PHP array.
	 */
	public function test_site_object_schema_is_fully_decorated(): void {
		$args   = $this->siteAbility();
		$result = $this->decorator()->decorate($args, 'content/create-post');

		$this->assertArrayHasKey('blog_id', $result['input_schema']['properties']);
		$this->assertSame('integer', $result['input_schema']['properties']['blog_id']['type']);
		$this->assertSame(1, $result['input_schema']['properties']['blog_id']['minimum']);

		// F1: properties is a non-empty PHP array, not a stdClass, not [].
		$this->assertIsArray($result['input_schema']['properties']);
		$this->assertNotEmpty($result['input_schema']['properties']);

		// required is never touched.
		$this->assertSame(array( 'title' ), $result['input_schema']['required']);

		// Hint appended exactly once.
		$this->assertStringEndsWith(self::HINT, $result['description']);
		$this->assertSame(1, substr_count($result['description'], 'pass blog_id to target a specific site'));

		// F6: flag written on the wrapped path.
		$this->assertTrue($result['meta'][ self::FLAG ]);

		// Callbacks were wrapped (closures, not the originals).
		$this->assertNotSame($args['permission_callback'], $result['permission_callback']);
		$this->assertNotSame($args['execute_callback'], $result['execute_callback']);
	}

	public function test_hint_is_appended_exactly_once_across_two_passes(): void {
		$decorator = $this->decorator();
		$once      = $decorator->decorate($this->siteAbility(), 'content/create-post');

		// Re-running the same already-flagged args must not append a second hint.
		$twice = $decorator->decorate($once, 'content/create-post');

		$this->assertSame($once['description'], $twice['description']);
		$this->assertSame(1, substr_count($twice['description'], 'pass blog_id to target a specific site'));
	}

	/**
	 * @dataProvider nonSiteScopes
	 *
	 * @param string $scope A non-site scope.
	 */
	public function test_non_site_scope_is_left_unchanged(string $scope): void {
		$args              = $this->siteAbility();
		$args['meta']['abilities_catalog']['scope'] = $scope;

		$result = $this->decorator()->decorate($args, 'network/do-thing');

		$this->assertEquals($args, $result); // No blog_id, no hint, no flag.
		$this->assertArrayNotHasKey('blog_id', $result['input_schema']['properties']);
		$this->assertArrayNotHasKey(self::FLAG, $result['meta']);
		$this->assertSame($args['permission_callback'], $result['permission_callback']);
	}

	public function test_already_flagged_args_are_returned_unchanged(): void {
		$args                          = $this->siteAbility();
		$args['meta'][ self::FLAG ]    = true;

		$result = $this->decorator()->decorate($args, 'content/create-post');

		$this->assertEquals($args, $result);
		$this->assertArrayNotHasKey('blog_id', $result['input_schema']['properties']);
	}

	/**
	 * @dataProvider excludedNames
	 *
	 * @param string $name A dispatcher/meta ability name.
	 */
	public function test_dispatcher_abilities_are_excluded(string $name): void {
		$args   = $this->siteAbility();
		$result = $this->decorator()->decorate($args, $name);

		$this->assertEquals($args, $result);
		$this->assertArrayNotHasKey('blog_id', $result['input_schema']['properties']);
		$this->assertArrayNotHasKey(self::FLAG, $result['meta']);
	}

	/**
	 * No-input schema (absent or type !== object): blog_id NOT injected AND callbacks
	 * NOT wrapped (F2 atomicity).
	 *
	 * @dataProvider nonObjectSchemas
	 *
	 * @param mixed $schema The input schema to set (or null to omit).
	 */
	public function test_non_object_schema_is_not_injected_and_not_wrapped($schema): void {
		$args = $this->siteAbility();
		if (null === $schema) {
			unset($args['input_schema']);
		} else {
			$args['input_schema'] = $schema;
		}

		$result = $this->decorator()->decorate($args, 'content/no-input');

		$this->assertEquals($args, $result); // Byte-for-byte unchanged.
		$this->assertArrayNotHasKey(self::FLAG, $result['meta']);
		$this->assertSame($args['permission_callback'], $result['permission_callback']);
		$this->assertSame($args['execute_callback'], $result['execute_callback']);
	}

	public function test_preexisting_blog_id_property_is_not_clobbered_or_wrapped(): void {
		$args                                                  = $this->siteAbility();
		$args['input_schema']['properties']['blog_id']         = array(
			'type'        => 'integer',
			'description' => 'The ability owns this field.',
		);

		$result = $this->decorator()->decorate($args, 'network/add-user-to-site');

		// The ability's own blog_id schema is untouched and callbacks are NOT wrapped.
		$this->assertSame($args['input_schema']['properties']['blog_id'], $result['input_schema']['properties']['blog_id']);
		$this->assertArrayNotHasKey(self::FLAG, $result['meta']);
		$this->assertSame($args['permission_callback'], $result['permission_callback']);
		$this->assertSame($args['execute_callback'], $result['execute_callback']);
	}

	public function test_single_site_is_a_no_op(): void {
		$decorator = new PolicyDecorator(
			new NoopBlogSwitcher(),
			static function (): bool {
				return false; // single-site.
			}
		);

		$args   = $this->siteAbility();
		$result = $decorator->decorate($args, 'content/create-post');

		$this->assertEquals($args, $result);
		$this->assertArrayNotHasKey(self::FLAG, $result['meta']);
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function nonSiteScopes(): array {
		return array(
			'network' => array('network'),
			'user'    => array('user'),
			'global'  => array('global'),
		);
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function excludedNames(): array {
		return array(
			'mcp-adapter dispatcher'      => array('mcp-adapter/foo'),
			'abilities-catalog dispatcher' => array('abilities-catalog/foo'),
		);
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public static function nonObjectSchemas(): array {
		return array(
			'absent'       => array(null),
			'string type'  => array(array( 'type' => 'string' )),
			'no type'      => array(array( 'properties' => array() )),
			'not an array' => array('nonsense'),
		);
	}

	/**
	 * Builds a decorator forced onto the multisite branch with a no-op switcher.
	 */
	private function decorator(): PolicyDecorator {
		return new PolicyDecorator(
			new NoopBlogSwitcher(),
			static function (): bool {
				return true; // force multisite.
			}
		);
	}

	/**
	 * A representative site-scoped ability's registration args.
	 *
	 * @return array<string,mixed>
	 */
	private function siteAbility(): array {
		return array(
			'description'         => 'Creates a post.',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'title' => array( 'type' => 'string' ),
				),
				'required'   => array( 'title' ),
			),
			'permission_callback' => static function (): bool {
				return true;
			},
			'execute_callback'    => static function ($input) {
				return $input;
			},
			'meta'                => array(
				'annotations'       => array( 'readonly' => false ),
				'abilities_catalog' => array( 'scope' => 'site' ),
			),
		);
	}
}

/**
 * A `BlogSwitcher` that does nothing — the unit decorate tests never invoke callbacks.
 */
final class NoopBlogSwitcher implements BlogSwitcher {

	public function switchTo(int $blog_id): void {
	}

	public function restore(): void {
	}
}
