<?php
/**
 * Unit tests for the skills registry.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Unit\Mcp;

use GalatanOvidiu\AbilitiesCatalog\Mcp\Skills\CreateContent;
use GalatanOvidiu\AbilitiesCatalog\Mcp\SkillsRegistry;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * The registry merges built-in skills with the `abilities_catalog_mcp_skills` filter
 * and resolves bodies lazily, so these assert: the built-in is discoverable and
 * gettable, the filter adds/overrides skills, a callable body stays unbuilt until
 * `get`, and malformed input degrades to clean errors instead of breaking the tool.
 */
final class SkillsRegistryTest extends TestCase {

	/**
	 * The registry under test.
	 *
	 * @var \GalatanOvidiu\AbilitiesCatalog\Mcp\SkillsRegistry
	 */
	private SkillsRegistry $registry;

	/**
	 * Builds a fresh registry for each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->registry = new SkillsRegistry();
	}

	/**
	 * Drops any extensibility filter a test installed.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'abilities_catalog_mcp_skills' );
		parent::tear_down();
	}

	/**
	 * list surfaces the built-in create-content skill, without its body.
	 *
	 * @return void
	 */
	public function test_list_includes_the_builtin_skill_without_a_body(): void {
		$item = $this->itemWithId( $this->registry->list(), CreateContent::ID );

		$this->assertNotEmpty( $item['title'] );
		$this->assertNotEmpty( $item['when_to_use'] );
		$this->assertArrayNotHasKey( 'body', $item, 'list must stay lazy and not carry recipe bodies.' );
	}

	/**
	 * get returns the full recipe, body included, for a built-in skill.
	 *
	 * @return void
	 */
	public function test_get_returns_the_full_builtin_recipe(): void {
		$skill = $this->registry->get( CreateContent::ID );

		$this->assertIsArray( $skill );
		$this->assertSame( CreateContent::ID, $skill['id'] );
		$this->assertNotEmpty( $skill['title'] );
		$this->assertNotEmpty( $skill['when_to_use'] );
		$this->assertNotEmpty( $skill['body'] );
		$this->assertIsString( $skill['body'] );
	}

	/**
	 * get errors on an unknown id with a recoverable 404.
	 *
	 * @return void
	 */
	public function test_get_rejects_an_unknown_id(): void {
		$error = $this->registry->get( 'no-such-skill' );

		$this->assertWPError( $error );
		$this->assertSame( 'abilities_catalog_mcp_unknown_skill', $error->get_error_code() );
		$this->assertSame( 404, $error->get_error_data()['status'] );
	}

	/**
	 * get errors on an empty id with a 400.
	 *
	 * @return void
	 */
	public function test_get_rejects_an_empty_id(): void {
		$error = $this->registry->get( '' );

		$this->assertWPError( $error );
		$this->assertSame( 'abilities_catalog_mcp_missing_skill', $error->get_error_code() );
		$this->assertSame( 400, $error->get_error_data()['status'] );
	}

	/**
	 * The filter adds a third-party skill with a plain string body.
	 *
	 * @return void
	 */
	public function test_filter_adds_a_skill_with_a_string_body(): void {
		add_filter(
			'abilities_catalog_mcp_skills',
			static function ( array $skills ): array {
				$skills['acme/recipe'] = array(
					'title'       => 'Acme recipe',
					'when_to_use' => 'When doing the Acme thing',
					'body'        => 'Acme body text.',
				);

				return $skills;
			}
		);
		$registry = new SkillsRegistry();

		$this->assertSame( 'Acme recipe', $this->itemWithId( $registry->list(), 'acme/recipe' )['title'] );
		$this->assertSame( 'Acme body text.', $registry->get( 'acme/recipe' )['body'] );
		// The built-in still resolves alongside the added one.
		$this->assertSame( CreateContent::ID, $registry->get( CreateContent::ID )['id'] );
	}

	/**
	 * A callable body is not invoked by list, only by get (laziness).
	 *
	 * @return void
	 */
	public function test_callable_body_is_built_only_on_get(): void {
		$invocations = 0;
		add_filter(
			'abilities_catalog_mcp_skills',
			static function ( array $skills ) use ( &$invocations ): array {
				$skills['acme/lazy'] = array(
					'title'       => 'Lazy',
					'when_to_use' => 'When testing laziness',
					'body'        => static function () use ( &$invocations ): string {
						++$invocations;

						return 'Built on demand.';
					},
				);

				return $skills;
			}
		);
		$registry = new SkillsRegistry();

		$registry->list();
		$this->assertSame( 0, $invocations, 'list must not build a callable body.' );

		$skill = $registry->get( 'acme/lazy' );
		$this->assertSame( 'Built on demand.', $skill['body'] );
		$this->assertSame( 1, $invocations, 'get builds the callable body exactly once.' );
	}

	/**
	 * The filter can override a built-in skill's body.
	 *
	 * @return void
	 */
	public function test_filter_can_override_a_builtin_body(): void {
		add_filter(
			'abilities_catalog_mcp_skills',
			static function ( array $skills ): array {
				$skills[ CreateContent::ID ]['body'] = 'Overridden body.';

				return $skills;
			}
		);
		$registry = new SkillsRegistry();

		$this->assertSame( 'Overridden body.', $registry->get( CreateContent::ID )['body'] );
	}

	/**
	 * A filter that returns a non-array is ignored in favor of the built-ins.
	 *
	 * @return void
	 */
	public function test_non_array_filter_return_is_ignored(): void {
		add_filter( 'abilities_catalog_mcp_skills', '__return_false' );
		$registry = new SkillsRegistry();

		$this->assertSame( CreateContent::ID, $registry->get( CreateContent::ID )['id'] );
	}

	/**
	 * A malformed entry (missing keys) is invisible to list and unknown to get.
	 *
	 * @return void
	 */
	public function test_malformed_entry_is_skipped(): void {
		add_filter(
			'abilities_catalog_mcp_skills',
			static function ( array $skills ): array {
				$skills['acme/broken']  = 'not-an-array';
				$skills['acme/partial'] = array( 'title' => 'No body or when-to-use' );

				return $skills;
			}
		);
		$registry = new SkillsRegistry();

		$ids = array_column( $registry->list(), 'id' );
		$this->assertNotContains( 'acme/broken', $ids );
		$this->assertNotContains( 'acme/partial', $ids );

		$this->assertSame( 'abilities_catalog_mcp_unknown_skill', $registry->get( 'acme/broken' )->get_error_code() );
		$this->assertSame( 'abilities_catalog_mcp_unknown_skill', $registry->get( 'acme/partial' )->get_error_code() );
	}

	/**
	 * A present-but-unreadable body (wrong type) errors as a 500 on get.
	 *
	 * @return void
	 */
	public function test_get_rejects_a_body_that_is_not_string_or_callable(): void {
		add_filter(
			'abilities_catalog_mcp_skills',
			static function ( array $skills ): array {
				$skills['acme/badbody'] = array(
					'title'       => 'Bad body',
					'when_to_use' => 'Never',
					'body'        => 123,
				);

				return $skills;
			}
		);
		$registry = new SkillsRegistry();

		$error = $registry->get( 'acme/badbody' );
		$this->assertWPError( $error );
		$this->assertSame( 'abilities_catalog_mcp_invalid_skill', $error->get_error_code() );
		$this->assertSame( 500, $error->get_error_data()['status'] );
	}

	/**
	 * Returns the list item with the given id, failing if absent.
	 *
	 * @param array<int,array<string,mixed>> $items The list items.
	 * @param string                         $id    The skill id to find.
	 * @return array<string,mixed> The matching item.
	 */
	private function itemWithId( array $items, string $id ): array {
		foreach ( $items as $item ) {
			if ( $item['id'] === $id ) {
				return $item;
			}
		}

		$this->fail( sprintf( 'list did not contain the "%s" skill.', $id ) );
	}
}
