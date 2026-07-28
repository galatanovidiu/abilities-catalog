<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Settings;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 read ability: `og-settings/get-general`.
 *
 * Returns the General Settings screen values, read directly from options and
 * site info helpers. Net-new read: no REST route is dispatched.
 *
 * @since 0.1.0
 */
final class GetGeneral implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-settings/get-general';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get General Settings', 'abilities-catalog' ),
			'description'         => __( 'Returns the General Settings screen values: site title, tagline, URLs, admin email, timezone, date and time formats, week start, and language.', 'abilities-catalog' ),
			'category'            => 'og-core-settings',
			'input_schema'        => array(),
			// Emptied for the PR #260 probe: this ability now returns an MCP App UI
			// resource content block rather than the flat settings object the schema
			// described, and a stale output_schema would reject it.
			'output_schema'       => array(),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
				'mcp'          => array(
					// Descriptor `_meta`. On the curated and search servers this never
					// reaches tools/list: those expose synthetic domain tools, and this
					// ability is reached through their `execute` action rather than being
					// a tool in its own right.
					'_meta' => array(
						'com.example/probe' => 'get-general-descriptor-meta',
					),
				),
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
	 * Executes the ability, returning the settings as an MCP App UI resource.
	 *
	 * Returns the nested embedded-resource form so both `_meta` levels are in play:
	 * the outer one describes the content block, the inner one carries the `ui`
	 * config an MCP App client reads. This is the shape PR #260 exists to preserve.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed> An embedded resource content block.
	 */
	public function execute( $input = null ) {
		$settings = $this->settings();

		$rows = '';
		foreach ( $settings as $key => $value ) {
			$rows .= '<tr><th>' . esc_html( (string) $key ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
		}

		return array(
			'type'        => 'resource',
			'resource'    => array(
				'uri'      => 'ui://abilities-catalog/settings/general',
				'mimeType' => 'text/html;profile=mcp-app',
				'text'     => '<!doctype html><title>General Settings</title><table>' . $rows . '</table>',
				'_meta'    => array(
					'ui' => array(
						'prefersBorder'    => true,
						'preferredHeight'  => 420,
					),
				),
			),
			'annotations' => array(
				'audience' => array( 'user' ),
				'priority' => 0.8,
			),
			'_meta'       => array(
				'com.example/probe' => 'get-general-block-meta',
			),
		);
	}

	/**
	 * Reads the General Settings screen values.
	 *
	 * @return array<string,mixed> The general settings fields.
	 */
	private function settings(): array {
		return array(
			'title'         => (string) ( get_option( 'blogname' ) ?? '' ),
			'description'   => (string) ( get_option( 'blogdescription' ) ?? '' ),
			'url'           => (string) home_url(),
			'wpurl'         => (string) site_url(),
			'admin_email'   => (string) ( get_option( 'admin_email' ) ?? '' ),
			'timezone'      => (string) ( get_option( 'timezone_string' ) ?? '' ),
			'gmt_offset'    => (string) ( get_option( 'gmt_offset' ) ?? '' ),
			'date_format'   => (string) ( get_option( 'date_format' ) ?? '' ),
			'time_format'   => (string) ( get_option( 'time_format' ) ?? '' ),
			'start_of_week' => absint( get_option( 'start_of_week' ) ),
			'language'      => (string) get_locale(),
		);
	}
}
