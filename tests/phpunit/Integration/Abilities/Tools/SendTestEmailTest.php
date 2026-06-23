<?php
/**
 * Integration tests for the tools/send-test-email ability.
 *
 * Covers registration, the output-shape contract (sent/to), a happy-path send to the
 * admin email and to an explicit recipient (asserted against the WP mock mailer, never
 * a real send), the invalid-email rejection, and the manage_options capability gate for
 * subscriber and logged-out callers (with no mail captured). This is an all-optional
 * ability, so every execute() call passes an array.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Tools;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises tools/send-test-email registration, send semantics, and the gate.
 */
final class SendTestEmailTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		reset_phpmailer_instance();
		// The wp-env site host is "localhost", so wp_mail()'s default From
		// (wordpress@localhost) is rejected by PHPMailer and the send fails for a
		// reason unrelated to the ability. Force a valid From so the mock captures
		// the message, exactly as a real (FQDN) site would.
		add_filter( 'wp_mail_from', array( $this, 'validFrom' ) );
	}

	protected function tearDown(): void {
		remove_filter( 'wp_mail_from', array( $this, 'validFrom' ) );
		reset_phpmailer_instance();
		parent::tearDown();
	}

	public function validFrom(): string {
		return 'wordpress@example.org';
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'tools/send-test-email' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'tools/send-test-email', $ability->get_name() );
	}

	public function test_output_schema_requires_sent_and_to(): void {
		$schema = wp_get_ability( 'tools/send-test-email' )->get_output_schema();

		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( array( 'sent', 'to' ), $schema['required'] );
	}

	public function test_sends_to_admin_email_by_default(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'tools/send-test-email' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'sent', 'to' ), array_keys( $result ) );
		$this->assertTrue( $result['sent'] );
		$this->assertIsBool( $result['sent'] );
		$this->assertSame( get_option( 'admin_email' ), $result['to'] );

		// The mock mailer captured a message to the admin address (no real send).
		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertSame( get_option( 'admin_email' ), $mailer->get_recipient( 'to' )->address );
	}

	public function test_sends_to_explicit_recipient(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'tools/send-test-email' )->execute( array( 'to' => 'someone@example.com' ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['sent'] );
		$this->assertSame( 'someone@example.com', $result['to'] );

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertSame( 'someone@example.com', $mailer->get_recipient( 'to' )->address );
	}

	public function test_invalid_recipient_is_rejected(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'tools/send-test-email' )->execute( array( 'to' => 'not-an-email' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'abilities_catalog_invalid_email', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );

		// No mail was sent.
		$this->assertEmpty( tests_retrieve_phpmailer_instance()->mock_sent );
	}

	public function test_subscriber_is_denied_and_no_mail_sent(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'tools/send-test-email' )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertEmpty( tests_retrieve_phpmailer_instance()->mock_sent );
	}

	public function test_logged_out_user_is_denied_and_no_mail_sent(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'tools/send-test-email' )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertEmpty( tests_retrieve_phpmailer_instance()->mock_sent );
	}
}
