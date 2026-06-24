<?php
/**
 * Unit tests for the policy ScopeResolver.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Unit\Support;

use GalatanOvidiu\AbilitiesCatalog\Support\ScopeResolver;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * Scope is purely meta-driven with one source of truth. `resolve()` defaults a
 * missing/invalid scope to `site`; `declaredScope()` returns null when the author
 * declared nothing, which is the write-guard's input.
 */
final class ScopeResolverTest extends TestCase {

	/**
	 * @dataProvider declaredScopes
	 *
	 * @param string $scope The declared scope value.
	 */
	public function test_declared_scope_is_resolved(string $scope): void {
		$args = array( 'meta' => array( 'abilities_catalog' => array( 'scope' => $scope ) ) );

		$this->assertSame($scope, ScopeResolver::resolve($args, 'x/y'));
	}

	public function test_missing_scope_resolves_to_site(): void {
		$this->assertSame('site', ScopeResolver::resolve(array(), 'x/y'));
		$this->assertSame('site', ScopeResolver::resolve(array( 'meta' => array() ), 'x/y'));
	}

	/**
	 * @dataProvider invalidScopes
	 *
	 * @param mixed $scope A non-string or unknown scope value.
	 */
	public function test_unknown_or_non_string_scope_resolves_to_site($scope): void {
		$args = array( 'meta' => array( 'abilities_catalog' => array( 'scope' => $scope ) ) );

		$this->assertSame('site', ScopeResolver::resolve($args, 'x/y'));
	}

	public function test_default_constant_is_site(): void {
		$this->assertSame('site', ScopeResolver::DEFAULT);
	}

	public function test_declared_scope_returns_the_explicit_value(): void {
		$args = array( 'meta' => array( 'abilities_catalog' => array( 'scope' => 'network' ) ) );

		$this->assertSame('network', ScopeResolver::declaredScope($args, 'x/y'));
	}

	public function test_declared_scope_is_null_when_absent(): void {
		$this->assertNull(ScopeResolver::declaredScope(array(), 'x/y'));
		$this->assertNull(ScopeResolver::declaredScope(array( 'meta' => array( 'abilities_catalog' => array() ) ), 'x/y'));
	}

	/**
	 * @dataProvider invalidScopes
	 *
	 * @param mixed $scope A non-string or unknown scope value.
	 */
	public function test_declared_scope_is_null_for_invalid_values($scope): void {
		$args = array( 'meta' => array( 'abilities_catalog' => array( 'scope' => $scope ) ) );

		$this->assertNull(ScopeResolver::declaredScope($args, 'x/y'));
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function declaredScopes(): array {
		return array(
			'site'    => array('site'),
			'network' => array('network'),
			'user'    => array('user'),
			'global'  => array('global'),
		);
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public static function invalidScopes(): array {
		return array(
			'unknown string' => array('blog'),
			'empty string'   => array(''),
			'integer'        => array(42),
			'boolean'        => array(true),
			'array'          => array(array('site')),
		);
	}
}
