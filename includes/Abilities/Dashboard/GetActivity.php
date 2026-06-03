<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Abilities\Dashboard;

use Automattic\AbilitiesCatalog\Contracts\Ability;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Composed T1 read ability: `dashboard/get-activity`.
 *
 * Mirrors the wp-admin "Activity" dashboard widget. Returns the most recently
 * published posts and the most recent approved comments. Built directly on core
 * query functions (`get_posts()`, `get_comments()`) rather than REST, since this
 * is a net-new composed read with no single REST equivalent.
 *
 * @since 0.1.0
 */
final class GetActivity implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'dashboard/get-activity';
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): array
	{
		return array(
			'slug'        => 'dashboard',
			'label'       => __('Dashboard', 'abilities-catalog'),
			'description' => __('Composed read-only dashboard summaries (counts, recent activity, drafts).', 'abilities-catalog'),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('Get Activity', 'abilities-catalog'),
			'description'         => __('Returns recent dashboard activity: recently published posts and recent approved comments.', 'abilities-catalog'),
			'category'            => 'dashboard',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'number' => array(
						'type'        => 'integer',
						'default'     => 5,
						'minimum'     => 1,
						'maximum'     => 20,
						'description' => __('Maximum number of items to return for each list (1-20).', 'abilities-catalog'),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('published', 'comments'),
				'properties'           => array(
					'published' => array(
						'type'        => 'array',
						'description' => __('Recently published posts.', 'abilities-catalog'),
						'items'       => array(
							'type'                 => 'object',
							'additionalProperties' => true,
						),
					),
					'comments'  => array(
						'type'        => 'array',
						'description' => __('Recent approved comments.', 'abilities-catalog'),
						'items'       => array(
							'type'                 => 'object',
							'additionalProperties' => true,
						),
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
	 * Encodes the catalog capability for `dashboard/get-activity` (`edit_posts`).
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may edit posts.
	 */
	public function hasPermission($input): bool
	{
		return current_user_can('edit_posts');
	}

	/**
	 * Executes the ability by reading recent posts and comments.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed> The recent activity lists.
	 */
	public function execute($input)
	{
		$input  = is_array($input) ? $input : array();
		$number = isset($input['number']) ? (int) $input['number'] : 5;
		$number = max(1, min(20, $number));

		$recent_published = get_posts(
			array(
				'post_status' => 'publish',
				'numberposts' => $number,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);

		$published = array();
		foreach ($recent_published as $post) {
			$published[] = array(
				'id'    => (int) $post->ID,
				'title' => (string) get_the_title($post->ID),
				'date'  => (string) ($post->post_date ?? ''),
			);
		}

		$recent_comments = get_comments(
			array(
				'number' => $number,
				'status' => 'approve',
			)
		);

		$comments = array();
		foreach ($recent_comments as $comment) {
			$comments[] = array(
				'id'      => (int) $comment->comment_ID,
				'post'    => (int) $comment->comment_post_ID,
				'author'  => (string) ($comment->comment_author ?? ''),
				'date'    => (string) ($comment->comment_date ?? ''),
				'excerpt' => (string) wp_trim_words((string) ($comment->comment_content ?? '')),
			);
		}

		return array(
			'published' => $published,
			'comments'  => $comments,
		);
	}
}
