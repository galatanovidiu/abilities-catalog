<?php
/**
 * Integration tests for the settings/update-discussion ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Settings;

use GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Settings\UpdateDiscussion;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * settings/update-discussion writes the Discussion Settings screen. The two
 * REST-registered status options go through POST /wp/v2/settings; all other
 * allow-listed keys go through update_option(). manage_options is the hard
 * capability guard; avatar_default is validated against the filtered set.
 */
final class UpdateDiscussionTest extends TestCase {

	public function test_admin_writes_discussion_settings(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'settings/update-discussion' )->execute(
			array(
				'default_comment_status' => 'closed',
				'comments_per_page'      => 25,
				'comment_moderation'     => true,
				'avatar_default'         => 'identicon',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'closed', $result['default_comment_status'] );
		$this->assertSame( 25, $result['comments_per_page'] );
		$this->assertTrue( $result['comment_moderation'] );
		$this->assertSame( 'identicon', $result['avatar_default'] );

		$this->assertSame( 'closed', get_option( 'default_comment_status' ) );
		$this->assertSame( 25, absint( get_option( 'comments_per_page' ) ) );
		$this->assertSame( 'identicon', get_option( 'avatar_default' ) );
	}

	public function test_output_shape_contains_all_fields(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'settings/update-discussion' )->execute( array() );

		$this->assertIsArray( $result );

		$expected = array(
			'default_comment_status',
			'default_ping_status',
			'comment_registration',
			'close_comments_for_old_posts',
			'close_comments_days_old',
			'comments_per_page',
			'default_comments_page',
			'comment_order',
			'comment_moderation',
			'comment_max_links',
			'moderation_notify',
			'comments_notify',
			'show_avatars',
			'avatar_rating',
			'avatar_default',
		);

		foreach ( $expected as $field ) {
			$this->assertArrayHasKey( $field, $result );
		}
	}

	public function test_execute_rejects_unknown_avatar_default(): void {
		// Call the ability method directly to reach the runtime guard.
		$ability = new UpdateDiscussion();
		$this->actingAs( 'administrator' );

		$before = get_option( 'avatar_default' );

		$result = $ability->execute(
			array( 'avatar_default' => 'not-a-real-avatar' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'webmcp_invalid_avatar_default', $result->get_error_code() );
		// The invalid value must not have been written.
		$this->assertSame( $before, get_option( 'avatar_default' ) );
	}

	public function test_avatar_default_filter_widens_allowed_set(): void {
		$ability = new UpdateDiscussion();
		$this->actingAs( 'administrator' );

		$add = static function ( array $defaults ): array {
			$defaults['custom_av'] = 'Custom Avatar';
			return $defaults;
		};
		add_filter( 'avatar_defaults', $add );

		$result = $ability->execute(
			array( 'avatar_default' => 'custom_av' )
		);

		remove_filter( 'avatar_defaults', $add );

		$this->assertIsArray( $result );
		$this->assertSame( 'custom_av', $result['avatar_default'] );
		$this->assertSame( 'custom_av', get_option( 'avatar_default' ) );
	}

	public function test_integer_inputs_declare_minimum_zero(): void {
		$schema = ( new UpdateDiscussion() )->args()['input_schema']['properties'];

		foreach ( array( 'close_comments_days_old', 'comments_per_page', 'comment_max_links' ) as $field ) {
			$this->assertSame( 0, $schema[ $field ]['minimum'], $field . ' must declare minimum 0' );
		}
	}

	/**
	 * Writing the same discussion settings twice is a no-op (update_option
	 * short-circuits on an unchanged value), so the ability is idempotent (B6/B15).
	 */
	public function test_idempotent_annotation_is_true(): void {
		$annotations = ( new UpdateDiscussion() )->args()['meta']['annotations'];

		$this->assertTrue( $annotations['idempotent'] );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'settings/update-discussion' )->execute(
			array( 'default_comment_status' => 'closed' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
