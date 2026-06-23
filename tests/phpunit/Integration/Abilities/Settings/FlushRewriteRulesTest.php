<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Settings;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Integration tests for the `settings/flush-rewrite-rules` ability.
 */
final class FlushRewriteRulesTest extends TestCase {

	/**
	 * The permalink structure stored before each test, restored in tearDown.
	 *
	 * @var string
	 */
	private string $original_permalink_structure = '';

	/**
	 * Captures the permalink structure so the test can restore it afterwards.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->original_permalink_structure = (string) get_option( 'permalink_structure' );
	}

	/**
	 * Restores the permalink structure changed by the tests.
	 */
	protected function tearDown(): void {
		$this->set_permalink_structure( $this->original_permalink_structure );

		parent::tearDown();
	}

	/**
	 * The ability registers on the catalog.
	 */
	public function test_ability_is_registered(): void {
		$this->assertTrue( wp_has_ability( 'settings/flush-rewrite-rules' ) );
	}

	/**
	 * A soft flush (hard=false) regenerates the stored rules without errors.
	 */
	public function test_soft_flush_regenerates_rules(): void {
		$this->actingAs( 'administrator' );
		$this->set_permalink_structure( '/%postname%/' );

		$ability = wp_get_ability( 'settings/flush-rewrite-rules' );
		$result  = $ability->execute( array( 'hard' => false ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['flushed'] );
		$this->assertFalse( $result['hard'] );
		$this->assertIsInt( $result['rules_count'] );
		$this->assertGreaterThan( 0, $result['rules_count'] );
	}

	/**
	 * The default path (hard=true) succeeds even when no config file is written.
	 */
	public function test_default_hard_flush_succeeds(): void {
		$this->actingAs( 'administrator' );
		$this->set_permalink_structure( '/%postname%/' );

		$ability = wp_get_ability( 'settings/flush-rewrite-rules' );
		$result  = $ability->execute( array() );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['flushed'] );
		$this->assertTrue( $result['hard'] );
		$this->assertIsInt( $result['rules_count'] );
	}

	/**
	 * The result exposes exactly the declared output keys.
	 */
	public function test_output_shape(): void {
		$this->actingAs( 'administrator' );
		$this->set_permalink_structure( '/%postname%/' );

		$ability = wp_get_ability( 'settings/flush-rewrite-rules' );
		$result  = $ability->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'flushed', 'hard', 'rules_count' ), array_keys( $result ) );
		$this->assertIsBool( $result['flushed'] );
		$this->assertIsBool( $result['hard'] );
		$this->assertIsInt( $result['rules_count'] );
	}

	/**
	 * A subscriber without manage_options is denied.
	 */
	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'settings/flush-rewrite-rules' );
		$result  = $ability->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * A logged-out caller is denied.
	 */
	public function test_logged_out_is_denied(): void {
		wp_set_current_user( 0 );

		$ability = wp_get_ability( 'settings/flush-rewrite-rules' );
		$result  = $ability->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
