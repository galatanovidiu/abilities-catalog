<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Abilities\Comments;

use Automattic\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * T1 write ability: `comments/create-comment`.
 *
 * Wraps `POST /wp/v2/comments` via `rest_do_request()` and returns the new
 * comment's id, status, and link. A reply is the same call with a `parent`.
 * The `permission_callback` encodes the catalog capabilities: the user must be
 * logged in and able to read the target post; setting any moderation field
 * (`status`, `author`, `author_email`, `author_ip`, `author_name`) additionally
 * requires `moderate_comments`. The REST route re-checks every capability
 * underneath (defense in depth) and sanitizes content.
 *
 * @since 0.2.0
 */
final class CreateComment implements Ability
{
	/**
	 * Input fields that require the `moderate_comments` capability.
	 *
	 * @var string[]
	 */
	private const MODERATION_FIELDS = array('status', 'author', 'author_email', 'author_ip', 'author_name');

	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'comments/create-comment';
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): array
	{
		return array(
			'slug'        => 'comments',
			'label'       => __('Comments', 'abilities-catalog'),
			'description' => __('Abilities that read comments.', 'abilities-catalog'),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('Create Comment', 'abilities-catalog'),
			'description'         => __('Creates a comment on a post. Set parent to reply to another comment. Setting a moderation field (status, author, author_email, author_name) requires the moderate_comments capability.', 'abilities-catalog'),
			'category'            => 'comments',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'post'         => array(
						'type'        => 'integer',
						'description' => __('The ID of the post to comment on.', 'abilities-catalog'),
					),
					'parent'       => array(
						'type'        => 'integer',
						'description' => __('The ID of the parent comment when replying. Defaults to 0 (top-level).', 'abilities-catalog'),
					),
					'content'      => array(
						'type'        => 'string',
						'description' => __('The comment content (HTML allowed; sanitized by WordPress).', 'abilities-catalog'),
					),
					'author'       => array(
						'type'        => 'integer',
						'description' => __('The author user ID. Requires the moderate_comments capability.', 'abilities-catalog'),
					),
					'author_name'  => array(
						'type'        => 'string',
						'description' => __('The author display name. Requires the moderate_comments capability.', 'abilities-catalog'),
					),
					'author_email' => array(
						'type'        => 'string',
						'description' => __('The author email address. Requires the moderate_comments capability.', 'abilities-catalog'),
					),
					'status'       => array(
						'type'        => 'string',
						'description' => __('The comment status (e.g. "approve", "hold"). Requires the moderate_comments capability.', 'abilities-catalog'),
					),
				),
				'required'             => array('post', 'content'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('id', 'status', 'link'),
				'properties'           => array(
					'id'     => array(
						'type'        => 'integer',
						'description' => __('The new comment ID.', 'abilities-catalog'),
					),
					'status' => array(
						'type'        => 'string',
						'description' => __('The resulting comment status.', 'abilities-catalog'),
					),
					'link'   => array(
						'type'        => 'string',
						'description' => __('The public permalink to the comment.', 'abilities-catalog'),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'hasPermission'),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'screen'       => 'edit-comments.php',
			),
		);
	}

	/**
	 * Permission check encoding the catalog capabilities for creating a comment.
	 *
	 * Requires a logged-in user who can read the target post. Setting any
	 * moderation field additionally requires `moderate_comments`. The REST route
	 * re-checks these underneath.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may create the requested comment.
	 */
	public function hasPermission($input): bool
	{
		$input = is_array($input) ? $input : array();

		if (!is_user_logged_in()) {
			return false;
		}

		$post = absint($input['post'] ?? 0);
		if (!$post || !current_user_can('read_post', $post)) {
			return false;
		}

		foreach (self::MODERATION_FIELDS as $field) {
			if (isset($input[$field]) && '' !== $input[$field]) {
				return current_user_can('moderate_comments');
			}
		}

		return true;
	}

	/**
	 * Executes the ability by dispatching the internal REST create request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The new comment's id, status, link, or the REST error.
	 */
	public function execute($input)
	{
		$input   = is_array($input) ? $input : array();
		$request = new WP_REST_Request('POST', '/wp/v2/comments');

		if (!empty($input['post'])) {
			$request->set_param('post', absint($input['post']));
		}
		if (!empty($input['parent'])) {
			$request->set_param('parent', absint($input['parent']));
		}
		// Content passes through to the REST route, which sanitizes it.
		if (isset($input['content']) && '' !== $input['content']) {
			$request->set_param('content', (string) $input['content']);
		}
		if (!empty($input['author'])) {
			$request->set_param('author', absint($input['author']));
		}
		if (isset($input['author_name']) && '' !== $input['author_name']) {
			$request->set_param('author_name', sanitize_text_field((string) $input['author_name']));
		}
		if (isset($input['author_email']) && '' !== $input['author_email']) {
			$request->set_param('author_email', sanitize_email((string) $input['author_email']));
		}
		if (isset($input['status']) && '' !== $input['status']) {
			$request->set_param('status', sanitize_key((string) $input['status']));
		}

		$response = rest_do_request($request);
		if ($response->is_error()) {
			return $response->as_error();
		}

		$data = rest_get_server()->response_to_data($response, false);

		return array(
			'id'     => (int) ($data['id'] ?? 0),
			'status' => (string) ($data['status'] ?? ''),
			'link'   => (string) ($data['link'] ?? ''),
		);
	}
}
