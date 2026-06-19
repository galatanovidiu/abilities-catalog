<?php
/**
 * Integration tests for the domain router against real abilities.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Mcp;

use GalatanOvidiu\AbilitiesCatalog\Mcp\DomainMap;
use GalatanOvidiu\AbilitiesCatalog\Mcp\DomainRouter;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * Exercises list / describe / execute against the registered content abilities,
 * including the low-privilege execute denial that proves capability is the guard.
 */
final class DomainRouterTest extends TestCase {

	/**
	 * The router under test, bound to the real domain map.
	 *
	 * @var \GalatanOvidiu\AbilitiesCatalog\Mcp\DomainRouter
	 */
	private DomainRouter $router;

	/**
	 * Builds a fresh router for each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->router = new DomainRouter( new DomainMap() );
	}

	/**
	 * list returns content abilities, each carrying the three risk flags.
	 *
	 * @return void
	 */
	public function test_list_returns_content_abilities_with_flags(): void {
		$items = $this->router->list( 'content' );
		$names = array_column( $items, 'name' );

		$this->assertContains( 'content/get-post', $names );
		$this->assertContains( 'terms/create-category', $names );
		$this->assertContains( 'comments/get-comment', $names );
		$this->assertContains( 'search/search-content', $names );

		foreach ( $items as $item ) {
			$this->assertArrayHasKey( 'readonly', $item );
			$this->assertArrayHasKey( 'destructive', $item );
			$this->assertArrayHasKey( 'dangerous', $item );
		}

		$this->assertTrue( $this->itemNamed( $items, 'content/get-post' )['readonly'] );
	}

	/**
	 * list never leaks an ability from another domain.
	 *
	 * @return void
	 */
	public function test_list_excludes_other_domains(): void {
		$map   = new DomainMap();
		$names = array_column( $this->router->list( 'content' ), 'name' );

		$this->assertNotContains( 'media/list-image-sizes', $names );
		foreach ( $names as $name ) {
			$this->assertSame( 'content', $map->domainOf( $name ) );
		}
	}

	/**
	 * describe returns the schemas and annotations for an in-domain ability.
	 *
	 * @return void
	 */
	public function test_describe_returns_schema_and_annotations(): void {
		$description = $this->router->describe( 'content', 'content/get-post' );

		$this->assertIsArray( $description );
		$this->assertSame( 'content/get-post', $description['name'] );
		$this->assertArrayHasKey( 'input_schema', $description );
		$this->assertArrayHasKey( 'output_schema', $description );
		$this->assertTrue( $description['annotations']['readonly'] ?? false );
	}

	/**
	 * describe refuses an ability that belongs to a different domain.
	 *
	 * @return void
	 */
	public function test_describe_rejects_out_of_domain_ability(): void {
		$error = $this->router->describe( 'content', 'media/list-image-sizes' );

		$this->assertWPError( $error );
		$this->assertSame( 'abilities_catalog_mcp_unknown_ability', $error->get_error_code() );
	}

	/**
	 * describe refuses an empty ability name.
	 *
	 * @return void
	 */
	public function test_describe_rejects_empty_ability(): void {
		$error = $this->router->describe( 'content', '' );

		$this->assertWPError( $error );
		$this->assertSame( 'abilities_catalog_mcp_missing_ability', $error->get_error_code() );
	}

	/**
	 * An in-domain-but-unregistered name errors cleanly (no core notice).
	 *
	 * @return void
	 */
	public function test_describe_rejects_unregistered_name_without_notice(): void {
		$error = $this->router->describe( 'content', 'content/does-not-exist' );

		$this->assertWPError( $error );
		$this->assertSame( 'abilities_catalog_mcp_unknown_ability', $error->get_error_code() );
	}

	/**
	 * execute runs an ability for a user with the capability.
	 *
	 * @return void
	 */
	public function test_execute_runs_ability_for_admin(): void {
		$this->actingAs( 'administrator' );
		$post_id = self::factory()->post->create( array( 'post_title' => 'Hello' ) );

		$result = $this->router->execute( 'content', 'content/get-post', array( 'id' => $post_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $post_id, $result['id'] );
	}

	/**
	 * execute denies a user without the ability's capability (capability is the guard).
	 *
	 * @return void
	 */
	public function test_execute_denies_low_privilege_user(): void {
		$this->actingAs( 'subscriber' );

		$result = $this->router->execute( 'content', 'content/create-post', array( 'title' => 'Nope' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Returns the list item with the given ability name, failing if absent.
	 *
	 * @param array<int,array<string,mixed>> $items The list items.
	 * @param string                         $name  The ability name to find.
	 * @return array<string,mixed> The matching item.
	 */
	private function itemNamed( array $items, string $name ): array {
		foreach ( $items as $item ) {
			if ( $item['name'] === $name ) {
				return $item;
			}
		}

		$this->fail( sprintf( 'List did not contain "%s".', $name ) );
	}
}
