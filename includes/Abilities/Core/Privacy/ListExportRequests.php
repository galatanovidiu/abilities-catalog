<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Privacy;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 read ability: `privacy/list-export-requests`.
 *
 * Lists personal-data export requests. These are `user_request` custom-post-type
 * posts whose `post_name` (mapped to `action_name`) is `export_personal_data`.
 * The ability returns only the request records' metadata — id (and its
 * `request_id` alias), email, status, created date (site-local and UTC), and
 * action name. It never exposes any exported personal data.
 *
 * The capability mirrors the wp-admin Export Personal Data screen, which gates
 * on `export_others_personal_data` (wp-admin/export-personal-data.php:12).
 *
 * @since 0.1.0
 */
final class ListExportRequests implements Ability {

	use QueriesUserRequests;

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'privacy/list-export-requests';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'List Export Requests', 'abilities-catalog' ),
			'description'         => __( 'Discovery/read step for personal-data export requests: lists each request with its status and metadata so you can find the id (also returned as request_id) that the follow-on privacy abilities (confirm-request, cancel-request, generate-export) accept. Returns metadata only; does not expose exported data. Statuses reflect stored values; this read does not run wp-admin\'s expired-request cleanup, so a long-pending request may still show request-pending here.', 'abilities-catalog' ),
			'category'            => 'privacy',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'status'   => array(
						'type'        => 'string',
						'enum'        => array( 'request-pending', 'request-confirmed', 'request-completed', 'request-failed' ),
						'description' => __( 'Filter by request status.', 'abilities-catalog' ),
					),
					'page'     => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'default'     => 1,
						'description' => __( 'The page number to return.', 'abilities-catalog' ),
					),
					'per_page' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 20,
						'description' => __( 'The number of requests to return per page.', 'abilities-catalog' ),
					),
				),
				'required'             => array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'items', 'total' ),
				'properties'           => array(
					'items' => array(
						'type'        => 'array',
						'description' => __( 'The export request records.', 'abilities-catalog' ),
						'items'       => array(
							'type'                 => 'object',
							'properties'           => array(
								'id'          => array(
									'type'        => 'integer',
									'description' => __( 'The request post ID.', 'abilities-catalog' ),
								),
								'request_id'  => array(
									'type'        => 'integer',
									'description' => __( 'Alias of id; the identifier follow-on privacy abilities accept as request_id.', 'abilities-catalog' ),
								),
								'email'       => array(
									'type'        => 'string',
									'description' => __( 'The email address of the data subject.', 'abilities-catalog' ),
								),
								'status'      => array(
									'type'        => 'string',
									'enum'        => array( 'request-pending', 'request-confirmed', 'request-completed', 'request-failed' ),
									'description' => __( 'The request status.', 'abilities-catalog' ),
								),
								'created'     => array(
									'type'        => 'string',
									'description' => __( 'The request creation datetime, site-local timezone.', 'abilities-catalog' ),
								),
								'created_gmt' => array(
									'type'        => 'string',
									'description' => __( 'The request creation datetime, UTC.', 'abilities-catalog' ),
								),
								'action_name' => array(
									'type'        => 'string',
									'enum'        => array( 'export_personal_data' ),
									'description' => __( 'The request action name.', 'abilities-catalog' ),
								),
							),
							'additionalProperties' => false,
						),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'The total number of matching requests.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'       => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'abilities_catalog' => array(
					'scope' => 'global',
				),
				'show_in_rest'      => true,
			),
		);
	}

	/**
	 * Permission check: the cap required by the Export Personal Data screen.
	 *
	 * Mirrors wp-admin/export-personal-data.php:12, which gates on
	 * `export_others_personal_data`.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read export requests.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'export_others_personal_data' );
	}

	/**
	 * Executes the ability by querying `user_request` export posts.
	 *
	 * @param mixed $input The validated input data.
	 * @return array{items:array<int,array{id:int,request_id:int,email:string,status:string,created:string,created_gmt:string,action_name:string}>,total:int}
	 */
	public function execute( $input ) {
		return $this->queryUserRequests( 'export_personal_data', $input );
	}
}
