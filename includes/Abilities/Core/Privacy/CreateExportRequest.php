<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Privacy;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 non-destructive write ability: `og-privacy/create-export-request`.
 *
 * Creates a personal-data export request for an email address by wrapping the
 * core function `wp_create_user_request()`. The `send_confirmation_email` flag
 * maps 1:1 to the wp-admin confirmation-email checkbox in
 * `_wp_personal_data_handle_actions()` (wp-admin/includes/privacy-tools.php):
 * when true, the request is created as `request-pending` and
 * `wp_send_user_request($request_id)` emails the data subject the confirmation
 * link; when false (the default), the request is created already
 * `request-confirmed` with no email, so it is immediately actionable instead of
 * stranded without a confirmation key.
 *
 * This is net-new: there is no REST route for creating user requests, so the
 * ability wraps the core function directly. The core function validates the
 * email, the action name, and rejects duplicates, but does NOT check any
 * capability — the `permission_callback` is the hard authorization guard. It
 * mirrors the wp-admin Export Personal Data screen, which gates on
 * `export_others_personal_data` (wp-admin/export-personal-data.php:12).
 *
 * @since 0.3.0
 */
final class CreateExportRequest implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-privacy/create-export-request';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Create Export Request', 'abilities-catalog' ),
			'description'         => __( 'Creates a personal-data export request for an email address. When send_confirmation_email is true, creates a pending request and emails the data subject a confirmation link; when false (the default), creates an already-confirmed request with no email.', 'abilities-catalog' ),
			'category'            => 'og-core-privacy',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'email'                   => array(
						'type'        => 'string',
						'format'      => 'email',
						'description' => __( 'The email address of the data subject to export.', 'abilities-catalog' ),
					),
					'send_confirmation_email' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'When true, creates a pending request and emails the data subject a confirmation link. When false (the default), creates the request already confirmed, with no email.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'email' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'request_id', 'status', 'action_name' ),
				'properties'           => array(
					'request_id'  => array(
						'type'        => 'integer',
						'description' => __( 'The new request ID.', 'abilities-catalog' ),
					),
					'status'      => array(
						'type'        => 'string',
						'description' => __( 'The resulting request status.', 'abilities-catalog' ),
					),
					'action_name' => array(
						'type'        => 'string',
						'description' => __( 'The request action name (export_personal_data).', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'       => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'abilities_catalog' => array(
					'scope' => 'global',
				),
				'show_in_rest'      => true,
				'screen'            => 'export-personal-data.php',
			),
		);
	}

	/**
	 * Permission check: the cap required by the Export Personal Data screen.
	 *
	 * Mirrors wp-admin/export-personal-data.php:12, which gates on
	 * `export_others_personal_data`. The wrapped core function
	 * `wp_create_user_request()` performs no capability check, so this is the
	 * hard authorization guard.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may create an export request.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'export_others_personal_data' );
	}

	/**
	 * Executes the ability by creating an export request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array{request_id:int,status:string,action_name:string}|\WP_Error
	 */
	public function execute( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$email  = isset( $input['email'] ) ? sanitize_email( (string) $input['email'] ) : '';
		$send   = ! empty( $input['send_confirmation_email'] );
		$status = $send ? 'pending' : 'confirmed';

		if ( '' === $email ) {
			return new WP_Error(
				'invalid_email',
				__( 'A valid email address is required.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		// Mirror wp-admin: send=true creates a pending request and mails the
		// confirmation link; send=false creates an already-confirmed request so
		// it is actionable rather than stranded without a confirmation key.
		$request_id = wp_create_user_request( $email, 'export_personal_data', array(), $status );
		if ( is_wp_error( $request_id ) ) {
			return $request_id;
		}

		if ( $send ) {
			$sent = wp_send_user_request( (int) $request_id );
			if ( is_wp_error( $sent ) ) {
				// The request post already exists at this point. Preserve its ID
				// (and current status) on the error so the caller can recover and
				// does not trigger core's duplicate_request check on a naive retry.
				$created            = wp_get_user_request( (int) $request_id );
				$data               = (array) $sent->get_error_data();
				$data['request_id'] = (int) $request_id;
				$data['status']     = $created ? (string) $created->status : '';
				$sent->add_data( $data );

				return $sent;
			}
		}

		$request = wp_get_user_request( (int) $request_id );
		if ( ! $request ) {
			return new WP_Error(
				'request_lookup_failed',
				__( 'The export request was created but could not be loaded.', 'abilities-catalog' ),
				array(
					'status'     => 500,
					'request_id' => (int) $request_id,
				)
			);
		}

		return array(
			'request_id'  => (int) $request_id,
			'status'      => (string) $request->status,
			'action_name' => 'export_personal_data',
		);
	}
}
