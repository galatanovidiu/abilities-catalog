<?php
/**
 * Unit tests for the skills-tool MCP shim.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Unit\Mcp;

use GalatanOvidiu\AbilitiesCatalog\Mcp\Skills\CreateContent;
use GalatanOvidiu\AbilitiesCatalog\Mcp\SkillsRegistry;
use GalatanOvidiu\AbilitiesCatalog\Mcp\SkillsTool;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * Verifies the shim's three jobs, mirroring the domain-tool shim: dispatch the
 * `action`, shape the `list` success result as an object, and fold the error code +
 * status into the `WP_Error` message (since the adapter surfaces only the message).
 */
final class SkillsToolTest extends TestCase {

	/**
	 * The shim under test, over the real registry.
	 *
	 * @var \GalatanOvidiu\AbilitiesCatalog\Mcp\SkillsTool
	 */
	private SkillsTool $tool;

	/**
	 * Builds a skills tool over the real registry for each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->tool = new SkillsTool( new SkillsRegistry() );
	}

	/**
	 * The list action wraps the skill index in an object.
	 *
	 * @return void
	 */
	public function test_list_action_wraps_skills_in_object(): void {
		$result = $this->tool->handle( array( 'action' => 'list' ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'skills', $result );
		$this->assertNotEmpty( $result['skills'] );
	}

	/**
	 * The get action returns the recipe object for a known id.
	 *
	 * @return void
	 */
	public function test_get_action_returns_the_recipe_object(): void {
		$result = $this->tool->handle(
			array(
				'action' => 'get',
				'id'     => CreateContent::ID,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( CreateContent::ID, $result['id'] );
		$this->assertNotEmpty( $result['body'] );
	}

	/**
	 * An unknown action folds into a 400 error.
	 *
	 * @return void
	 */
	public function test_unknown_action_is_a_folded_error(): void {
		$result = $this->tool->handle( array( 'action' => 'frobnicate' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'abilities_catalog_mcp_invalid_action', $result->get_error_code() );
		$this->assertStringContainsString( 'status: 400', $result->get_error_message() );
	}

	/**
	 * A missing action is treated as unknown.
	 *
	 * @return void
	 */
	public function test_missing_action_is_a_folded_error(): void {
		$result = $this->tool->handle( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'abilities_catalog_mcp_invalid_action', $result->get_error_code() );
	}

	/**
	 * An unknown id on get folds into a 404 error.
	 *
	 * @return void
	 */
	public function test_unknown_id_get_is_a_folded_error(): void {
		$result = $this->tool->handle(
			array(
				'action' => 'get',
				'id'     => 'no-such-skill',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'abilities_catalog_mcp_unknown_skill', $result->get_error_code() );
		$this->assertStringContainsString( 'status: 404', $result->get_error_message() );
	}

	/**
	 * A get with no id folds into a 400 error.
	 *
	 * @return void
	 */
	public function test_missing_id_get_is_a_folded_error(): void {
		$result = $this->tool->handle( array( 'action' => 'get' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'abilities_catalog_mcp_missing_skill', $result->get_error_code() );
		$this->assertStringContainsString( 'status: 400', $result->get_error_message() );
	}

	/**
	 * The input schema offers exactly the list and get actions.
	 *
	 * @return void
	 */
	public function test_input_schema_describes_list_and_get(): void {
		$schema = SkillsTool::inputSchema();

		$this->assertSame( array( 'list', 'get' ), $schema['properties']['action']['enum'] );
		$this->assertSame( array( 'action' ), $schema['required'] );
	}
}
