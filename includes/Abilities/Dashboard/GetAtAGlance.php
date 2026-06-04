<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Dashboard;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Composed T1 read ability: `dashboard/get-at-a-glance`.
 *
 * Mirrors the wp-admin "At a Glance" dashboard widget. Returns published post
 * and page counts, approved and pending comment counts, the active theme name,
 * and the WordPress version. Built directly on core counting functions
 * (`wp_count_posts()`, `wp_count_comments()`) rather than REST, since this is a
 * net-new composed read with no single REST equivalent.
 *
 * @since 0.1.0
 */
final class GetAtAGlance implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'dashboard/get-at-a-glance';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('Get At a Glance', 'abilities-catalog'),
			'description'         => __('Returns the dashboard "At a Glance" summary: post and page counts, comment counts, active theme, and WordPress version.', 'abilities-catalog'),
			'category'            => 'dashboard',
			'input_schema'        => array(),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('posts', 'pages'),
				'properties'           => array(
					'posts'             => array(
						'type'        => 'integer',
						'description' => __('Number of published posts.', 'abilities-catalog'),
					),
					'pages'             => array(
						'type'        => 'integer',
						'description' => __('Number of published pages.', 'abilities-catalog'),
					),
					'comments_approved' => array(
						'type'        => 'integer',
						'description' => __('Number of approved comments.', 'abilities-catalog'),
					),
					'comments_pending'  => array(
						'type'        => 'integer',
						'description' => __('Number of comments awaiting moderation.', 'abilities-catalog'),
					),
					'theme'             => array(
						'type'        => 'string',
						'description' => __('Name of the active theme.', 'abilities-catalog'),
					),
					'wp_version'        => array(
						'type'        => 'string',
						'description' => __('The WordPress version.', 'abilities-catalog'),
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
	 * Permission check: the current user may edit posts.
	 *
	 * Encodes the catalog capability for `dashboard/get-at-a-glance` (`edit_posts`).
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may edit posts.
	 */
	public function hasPermission($input = null): bool
	{
		return current_user_can('edit_posts');
	}

	/**
	 * Executes the ability by reading core post and comment counts.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed> The dashboard summary fields.
	 */
	public function execute($input = null)
	{
		$posts    = wp_count_posts('post');
		$pages    = wp_count_posts('page');
		$comments = wp_count_comments();

		return array(
			'posts'             => (int) ($posts->publish ?? 0),
			'pages'             => (int) ($pages->publish ?? 0),
			'comments_approved' => (int) ($comments->approved ?? 0),
			'comments_pending'  => (int) ($comments->moderated ?? 0),
			'theme'             => (string) wp_get_theme()->get('Name'),
			'wp_version'        => (string) get_bloginfo('version'),
		);
	}
}
