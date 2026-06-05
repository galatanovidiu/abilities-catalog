<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Settings;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\BooleanInput;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 non-destructive write ability: `settings/update-discussion`.
 *
 * Updates the Discussion Settings screen. The accepted fields mirror the matching
 * read ability {@see GetDiscussion}. The write path is split by where each option
 * is exposed:
 *
 * - `default_comment_status` and `default_ping_status` are in the core REST
 *   settings registry, so they are written via `POST /wp/v2/settings`.
 * - All other allow-listed keys are not REST-registered, so they are written
 *   directly with `update_option()` after the capability check and per-type
 *   sanitization.
 *
 * @since 0.3.0
 */
final class UpdateDiscussion implements Ability {

	/**
	 * Allow-listed string options written via `update_option()`.
	 *
	 * @var string[]
	 */
	private const STRING_OPTIONS = array( 'default_comments_page', 'comment_order', 'avatar_rating', 'avatar_default' );

	/**
	 * Allow-listed integer options written via `update_option()`.
	 *
	 * @var string[]
	 */
	private const INT_OPTIONS = array( 'close_comments_days_old', 'comments_per_page', 'comment_max_links' );

	/**
	 * Allow-listed boolean options written via `update_option()`.
	 *
	 * @var string[]
	 */
	private const BOOL_OPTIONS = array(
		'comment_registration',
		'close_comments_for_old_posts',
		'comment_moderation',
		'moderation_notify',
		'comments_notify',
		'show_avatars',
	);

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'settings/update-discussion';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Update Discussion Settings', 'abilities-catalog' ),
			'description'         => __( 'Updates Discussion Settings: comment and ping defaults, moderation rules, notification flags, and avatar settings.', 'abilities-catalog' ),
			'category'            => 'settings',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'default_comment_status'       => array(
						'type'        => 'string',
						'enum'        => array( 'open', 'closed' ),
						'description' => __( 'Default comment status for new posts ("open" or "closed").', 'abilities-catalog' ),
					),
					'default_ping_status'          => array(
						'type'        => 'string',
						'enum'        => array( 'open', 'closed' ),
						'description' => __( 'Default pingback/trackback status for new posts ("open" or "closed").', 'abilities-catalog' ),
					),
					'comment_registration'         => array(
						'type'        => 'boolean',
						'description' => __( 'Whether users must be registered and logged in to comment.', 'abilities-catalog' ),
					),
					'close_comments_for_old_posts' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether comments close automatically on old posts.', 'abilities-catalog' ),
					),
					'close_comments_days_old'      => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'Number of days after which comments close.', 'abilities-catalog' ),
					),
					'comments_per_page'            => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'Number of top-level comments shown per page.', 'abilities-catalog' ),
					),
					'default_comments_page'        => array(
						'type'        => 'string',
						'enum'        => array( 'newest', 'oldest' ),
						'description' => __( 'Which comments page is shown by default ("newest" or "oldest").', 'abilities-catalog' ),
					),
					'comment_order'                => array(
						'type'        => 'string',
						'enum'        => array( 'asc', 'desc' ),
						'description' => __( 'Order comments are displayed in ("asc" or "desc").', 'abilities-catalog' ),
					),
					'comment_moderation'           => array(
						'type'        => 'boolean',
						'description' => __( 'Whether comments must be manually approved.', 'abilities-catalog' ),
					),
					'comment_max_links'            => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'Number of links in a comment that triggers the moderation queue.', 'abilities-catalog' ),
					),
					'moderation_notify'            => array(
						'type'        => 'boolean',
						'description' => __( 'Whether to email the admin when a comment is held for moderation.', 'abilities-catalog' ),
					),
					'comments_notify'              => array(
						'type'        => 'boolean',
						'description' => __( 'Whether to email the admin when anyone posts a comment.', 'abilities-catalog' ),
					),
					'show_avatars'                 => array(
						'type'        => 'boolean',
						'description' => __( 'Whether avatars are displayed.', 'abilities-catalog' ),
					),
					'avatar_rating'                => array(
						'type'        => 'string',
						'enum'        => array( 'G', 'PG', 'R', 'X' ),
						'description' => __( 'Maximum avatar rating to display (e.g. "G").', 'abilities-catalog' ),
					),
					'avatar_default'               => array(
						'type'        => 'string',
						'description' => __( 'The default avatar style for users without a custom avatar.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'default_comment_status' ),
				'properties'           => array(
					'default_comment_status'       => array(
						'type'        => 'string',
						'description' => __( 'The resulting default comment status.', 'abilities-catalog' ),
					),
					'default_ping_status'          => array(
						'type'        => 'string',
						'description' => __( 'The resulting default ping status.', 'abilities-catalog' ),
					),
					'comment_registration'         => array(
						'type'        => 'boolean',
						'description' => __( 'The resulting comment registration flag.', 'abilities-catalog' ),
					),
					'close_comments_for_old_posts' => array(
						'type'        => 'boolean',
						'description' => __( 'The resulting auto-close flag.', 'abilities-catalog' ),
					),
					'close_comments_days_old'      => array(
						'type'        => 'integer',
						'description' => __( 'The resulting auto-close day count.', 'abilities-catalog' ),
					),
					'comments_per_page'            => array(
						'type'        => 'integer',
						'description' => __( 'The resulting comments per page.', 'abilities-catalog' ),
					),
					'default_comments_page'        => array(
						'type'        => 'string',
						'description' => __( 'The resulting default comments page.', 'abilities-catalog' ),
					),
					'comment_order'                => array(
						'type'        => 'string',
						'description' => __( 'The resulting comment order.', 'abilities-catalog' ),
					),
					'comment_moderation'           => array(
						'type'        => 'boolean',
						'description' => __( 'The resulting manual approval flag.', 'abilities-catalog' ),
					),
					'comment_max_links'            => array(
						'type'        => 'integer',
						'description' => __( 'The resulting comment max links.', 'abilities-catalog' ),
					),
					'moderation_notify'            => array(
						'type'        => 'boolean',
						'description' => __( 'The resulting moderation notify flag.', 'abilities-catalog' ),
					),
					'comments_notify'              => array(
						'type'        => 'boolean',
						'description' => __( 'The resulting comments notify flag.', 'abilities-catalog' ),
					),
					'show_avatars'                 => array(
						'type'        => 'boolean',
						'description' => __( 'The resulting show avatars flag.', 'abilities-catalog' ),
					),
					'avatar_rating'                => array(
						'type'        => 'string',
						'description' => __( 'The resulting avatar rating.', 'abilities-catalog' ),
					),
					'avatar_default'               => array(
						'type'        => 'string',
						'description' => __( 'The resulting default avatar style.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'screen'       => 'options-discussion.php',
			),
		);
	}

	/**
	 * Permission check: the current user may manage options.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user can manage options.
	 */
	public function hasPermission( $input = null ): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Executes the ability by writing the Discussion Settings.
	 *
	 * The two REST-registered status options go through `POST /wp/v2/settings`;
	 * all other allow-listed keys go through `update_option()`.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The resulting discussion settings, or a WP_Error.
	 */
	public function execute( $input = null ) {
		$input = is_array( $input ) ? $input : array();

		// Defense in depth: update_option() does not re-check the capability.
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'webmcp_forbidden',
				__( 'You are not allowed to update discussion settings.', 'abilities-catalog' ),
				array( 'status' => 403 )
			);
		}

		// Reject an unknown avatar_default before any write. Core offers only the
		// keys from the filtered avatar_defaults set; sanitize_option() has no
		// case for this option, so without this guard any string would be stored.
		if ( array_key_exists( 'avatar_default', $input ) ) {
			$avatar_default = sanitize_text_field( (string) $input['avatar_default'] );
			$allowed        = array_keys( $this->avatarDefaults() );

			if ( ! in_array( $avatar_default, $allowed, true ) ) {
				return new \WP_Error(
					'webmcp_invalid_avatar_default',
					__( 'The provided avatar_default is not a recognized default avatar.', 'abilities-catalog' ),
					array( 'status' => 400 )
				);
			}
		}

		$request  = new WP_REST_Request( 'POST', '/wp/v2/settings' );
		$has_rest = false;

		foreach ( array( 'default_comment_status', 'default_ping_status' ) as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			$request->set_param( $field, sanitize_text_field( (string) $input[ $field ] ) );
			$has_rest = true;
		}

		if ( $has_rest ) {
			$response = rest_do_request( $request );
			if ( $response->is_error() ) {
				return RestError::from( $response );
			}
		}

		foreach ( self::STRING_OPTIONS as $option ) {
			if ( ! array_key_exists( $option, $input ) ) {
				continue;
			}

			update_option( $option, sanitize_text_field( (string) $input[ $option ] ) );
		}

		foreach ( self::INT_OPTIONS as $option ) {
			if ( ! array_key_exists( $option, $input ) ) {
				continue;
			}

			update_option( $option, absint( $input[ $option ] ) );
		}

		foreach ( self::BOOL_OPTIONS as $option ) {
			if ( ! array_key_exists( $option, $input ) ) {
				continue;
			}

			update_option( $option, BooleanInput::sanitize( $input[ $option ] ) ? 1 : 0 );
		}

		return array(
			'default_comment_status'       => (string) ( get_option( 'default_comment_status' ) ?? '' ),
			'default_ping_status'          => (string) ( get_option( 'default_ping_status' ) ?? '' ),
			'comment_registration'         => (bool) get_option( 'comment_registration' ),
			'close_comments_for_old_posts' => (bool) get_option( 'close_comments_for_old_posts' ),
			'close_comments_days_old'      => absint( get_option( 'close_comments_days_old' ) ),
			'comments_per_page'            => absint( get_option( 'comments_per_page' ) ),
			'default_comments_page'        => (string) ( get_option( 'default_comments_page' ) ?? '' ),
			'comment_order'                => (string) ( get_option( 'comment_order' ) ?? '' ),
			'comment_moderation'           => (bool) get_option( 'comment_moderation' ),
			'comment_max_links'            => absint( get_option( 'comment_max_links' ) ),
			'moderation_notify'            => (bool) get_option( 'moderation_notify' ),
			'comments_notify'              => (bool) get_option( 'comments_notify' ),
			'show_avatars'                 => (bool) get_option( 'show_avatars' ),
			'avatar_rating'                => (string) ( get_option( 'avatar_rating' ) ?? '' ),
			'avatar_default'               => (string) get_option( 'avatar_default', 'mystery' ),
		);
	}

	/**
	 * Returns the current set of allowed default avatars.
	 *
	 * Mirrors the core Discussion screen: the base set is run through the
	 * `avatar_defaults` filter, so the allow-list stays in sync with any
	 * registered custom avatars.
	 *
	 * @return array<string,string> Map of avatar key to display name.
	 */
	private function avatarDefaults(): array {
		$avatar_defaults = array(
			'mystery'          => __( 'Mystery Person', 'abilities-catalog' ),
			'blank'            => __( 'Blank', 'abilities-catalog' ),
			'gravatar_default' => __( 'Gravatar Logo', 'abilities-catalog' ),
			'identicon'        => __( 'Identicon (Generated)', 'abilities-catalog' ),
			'wavatar'          => __( 'Wavatar (Generated)', 'abilities-catalog' ),
			'monsterid'        => __( 'MonsterID (Generated)', 'abilities-catalog' ),
			'retro'            => __( 'Retro (Generated)', 'abilities-catalog' ),
			'robohash'         => __( 'RoboHash (Generated)', 'abilities-catalog' ),
		);

		/** This filter is documented in wp-admin/options-discussion.php */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Reading WordPress core's own avatar_defaults filter, not defining a plugin hook.
		return (array) apply_filters( 'avatar_defaults', $avatar_defaults );
	}
}
