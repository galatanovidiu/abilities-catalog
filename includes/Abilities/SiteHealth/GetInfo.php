<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\SiteHealth;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\AdminIncludes;
use WP_Debug_Data;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 read ability: `site-health/get-info`.
 *
 * Net-new. Returns the Site Health "Info" debug data from
 * {@see WP_Debug_Data::debug_data()}, with private values redacted. Core marks
 * sensitive entries with `'private' => true` — both at the section level and at the
 * individual field level. This ability drops any private section and any private
 * field, returning only the label and value of the remaining non-private fields.
 *
 * @since 0.1.0
 */
final class GetInfo implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'site-health/get-info';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Site Health Info', 'abilities-catalog' ),
			'description'         => __( 'Returns the Site Health debug information, with private fields and sections redacted.', 'abilities-catalog' ),
			'category'            => 'site-health',
			'input_schema'        => array(),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'info' ),
				'properties'           => array(
					'info' => array(
						'type'                 => 'object',
						'description'          => __( 'The redacted debug data, keyed by section. Each section has a label and a list of non-private fields.', 'abilities-catalog' ),
						'additionalProperties' => true,
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Permission check: the capability that gates the Site Health screen.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may view Site Health checks.
	 */
	public function hasPermission( $input = null ): bool {
		return current_user_can( 'view_site_health_checks' );
	}

	/**
	 * Executes the ability by collecting and redacting the debug data.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The redacted info structure, or an error.
	 */
	public function execute( $input = null ) {
		// `WP_Debug_Data::debug_data()` calls admin-only helpers (get_plugins,
		// get_plugin_updates, get_theme_updates, get_core_updates, get_home_path,
		// WP_Site_Health) that are not loaded during a REST request, so load them first.
		AdminIncludes::load( 'class-wp-debug-data', 'plugin', 'update', 'theme', 'class-wp-site-health', 'file', 'misc' );

		if ( ! class_exists( 'WP_Debug_Data' ) ) {
			return new WP_Error(
				'site_health_unavailable',
				__( 'Site Health debug data is not available on this installation.', 'abilities-catalog' )
			);
		}

		// Some versions refresh update transients before building the data.
		// This is best-effort and must not abort the ability if it fails.
		if ( method_exists( 'WP_Debug_Data', 'check_for_updates' ) ) {
			try {
				WP_Debug_Data::check_for_updates();
			} catch ( \Throwable $e ) {
				// Ignore: update checks are not required to read debug data.
			}
		}

		try {
			$debug_data = WP_Debug_Data::debug_data();
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'site_health_info_failed',
				__( 'Could not collect Site Health debug data.', 'abilities-catalog' )
			);
		}

		return array(
			'info' => $this->redact( is_array( $debug_data ) ? $debug_data : array() ),
		);
	}

	/**
	 * Builds a redacted copy of the debug data.
	 *
	 * Drops any section flagged private, then within each remaining section drops any
	 * field flagged private. Only the label and value of non-private fields survive.
	 *
	 * @param array<string,mixed> $debug_data The raw debug data sections.
	 * @return array<string,mixed> The redacted sections.
	 */
	private function redact( array $debug_data ): array {
		$redacted = array();

		foreach ( $debug_data as $section_id => $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}

			if ( ! empty( $section['private'] ) ) {
				continue;
			}

			$fields = array();
			foreach ( ( $section['fields'] ?? array() ) as $field_name => $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}

				if ( ! empty( $field['private'] ) ) {
					continue;
				}

				$fields[ $field_name ] = array(
					'label' => (string) ( $field['label'] ?? $field_name ),
					'value' => $field['value'] ?? null,
				);
			}

			$redacted[ $section_id ] = array(
				'label'  => (string) ( $section['label'] ?? $section_id ),
				'fields' => $fields,
			);
		}

		return $redacted;
	}
}
