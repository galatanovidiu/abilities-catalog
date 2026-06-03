<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Abilities\Settings;

use Automattic\AbilitiesCatalog\Contracts\Ability;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * T1 read ability: `settings/get-general`.
 *
 * Returns the General Settings screen values, read directly from options and
 * site info helpers. Net-new read: no REST route is dispatched.
 *
 * @since 0.1.0
 */
final class GetGeneral implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'settings/get-general';
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): array
	{
		return array(
			'slug'        => 'settings',
			'label'       => __('Settings', 'abilities-catalog'),
			'description' => __('Abilities that read site settings.', 'abilities-catalog'),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('Get General Settings', 'abilities-catalog'),
			'description'         => __('Returns the General Settings screen values: site title, tagline, URLs, admin email, timezone, date and time formats, week start, and language.', 'abilities-catalog'),
			'category'            => 'settings',
			'input_schema'        => array(),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('title'),
				'properties'           => array(
					'title'        => array(
						'type'        => 'string',
						'description' => __('The site title (blogname).', 'abilities-catalog'),
					),
					'description'  => array(
						'type'        => 'string',
						'description' => __('The site tagline (blogdescription).', 'abilities-catalog'),
					),
					'url'          => array(
						'type'        => 'string',
						'description' => __('The site home URL.', 'abilities-catalog'),
					),
					'wpurl'        => array(
						'type'        => 'string',
						'description' => __('The WordPress core install URL (site URL).', 'abilities-catalog'),
					),
					'admin_email'  => array(
						'type'        => 'string',
						'description' => __('The site administration email address.', 'abilities-catalog'),
					),
					'timezone'     => array(
						'type'        => 'string',
						'description' => __('The timezone string (e.g. "Europe/Berlin"); empty if a manual offset is used.', 'abilities-catalog'),
					),
					'gmt_offset'   => array(
						'type'        => 'string',
						'description' => __('The manual UTC offset in hours.', 'abilities-catalog'),
					),
					'date_format'  => array(
						'type'        => 'string',
						'description' => __('The PHP date format string.', 'abilities-catalog'),
					),
					'time_format'  => array(
						'type'        => 'string',
						'description' => __('The PHP time format string.', 'abilities-catalog'),
					),
					'start_of_week' => array(
						'type'        => 'integer',
						'description' => __('The day the week starts on (0 = Sunday).', 'abilities-catalog'),
					),
					'language'     => array(
						'type'        => 'string',
						'description' => __('The site locale (e.g. "en_US").', 'abilities-catalog'),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'hasPermission'),
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
	 * Permission check: the current user may manage options.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user can manage options.
	 */
	public function hasPermission($input = null): bool
	{
		return current_user_can('manage_options');
	}

	/**
	 * Executes the ability by reading general settings directly.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed> The general settings fields.
	 */
	public function execute($input = null)
	{
		return array(
			'title'         => (string) (get_option('blogname') ?? ''),
			'description'   => (string) (get_option('blogdescription') ?? ''),
			'url'           => (string) home_url(),
			'wpurl'         => (string) site_url(),
			'admin_email'   => (string) (get_option('admin_email') ?? ''),
			'timezone'      => (string) (get_option('timezone_string') ?? ''),
			'gmt_offset'    => (string) (get_option('gmt_offset') ?? ''),
			'date_format'   => (string) (get_option('date_format') ?? ''),
			'time_format'   => (string) (get_option('time_format') ?? ''),
			'start_of_week' => absint(get_option('start_of_week')),
			'language'      => (string) get_locale(),
		);
	}
}
