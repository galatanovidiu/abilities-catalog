<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Privacy;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Shared query logic for the privacy request list abilities.
 *
 * Personal-data export and erase requests are `user_request` custom-post-type
 * posts. Export requests use the `post_name`/action `export_personal_data`;
 * erase requests use `remove_personal_data`. This trait queries them and maps
 * each post to a clean record of request metadata only. It never reads or
 * exposes any exported personal data (the export payload itself).
 *
 * This file is discovered by the Registry directory scan but skipped: it is a
 * trait, not an {@see \GalatanOvidiu\AbilitiesCatalog\Contracts\Ability}, so it is
 * never registered as an ability.
 *
 * @since 0.1.0
 */
trait QueriesUserRequests
{
	/**
	 * Queries `user_request` posts for one action and maps them to clean records.
	 *
	 * @param string $action_name The request action: `export_personal_data` or
	 *                            `remove_personal_data`.
	 * @param mixed  $input       The validated ability input (status, page, per_page).
	 * @return array{items:array<int,array<string,mixed>>,total:int}
	 */
	private function queryUserRequests(string $action_name, $input): array
	{
		$input    = is_array($input) ? $input : array();
		$page     = isset($input['page']) ? max(1, absint($input['page'])) : 1;
		$per_page = isset($input['per_page']) ? max(1, min(100, absint($input['per_page']))) : 20;
		$status   = isset($input['status']) ? sanitize_key($input['status']) : '';

		$post_status = '' !== $status ? $status : 'any';

		$query_args = array(
			'post_type'        => 'user_request',
			'post_status'      => $post_status,
			'post_name__in'    => array($action_name),
			'posts_per_page'   => $per_page,
			'paged'            => $page,
			'orderby'          => 'date',
			'order'            => 'DESC',
			'no_found_rows'    => false,
			'suppress_filters' => false,
		);

		$query = new \WP_Query($query_args);
		$items = array();

		foreach ($query->posts as $post) {
			$request = wp_get_user_request($post->ID);
			if (!$request) {
				continue;
			}

			$items[] = array(
				'id'          => (int) $request->ID,
				'email'       => (string) $request->email,
				'status'      => (string) $request->status,
				'created'     => (string) get_post_field('post_date', $post->ID),
				'action_name' => (string) $request->action_name,
			);
		}

		return array(
			'items' => $items,
			'total' => (int) $query->found_posts,
		);
	}
}
