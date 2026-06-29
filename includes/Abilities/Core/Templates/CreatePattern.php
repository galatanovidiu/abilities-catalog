<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Templates;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 non-destructive write ability: `og-templates/create-pattern`.
 *
 * Wraps `POST /wp/v2/blocks` via the Abilities REST Adapter. A user pattern is a
 * `wp_block` post (a reusable block / synced pattern). The capability floor is the
 * route's own: `WP_REST_Blocks_Controller::create_item_permissions_check()` requires
 * the `wp_block` post type's `create_posts` capability, which the post type maps to
 * `publish_posts` (see the `capabilities` array in the `wp_block` registration in
 * `wp-includes/post.php`). So creating ANY pattern — even a draft — requires
 * `publish_posts`. No `require_permission` floor is added: the route is the authority.
 *
 * The input schema is OVERRIDDEN to the catalog's `title`/`content`/`status` set
 * (`additionalProperties:false`) so raw REST fields (author, meta, etc.) stay hidden.
 * The Abilities API does not apply the nested `status` default, so {@see injectStatus()}
 * is wired as the adapter's `input_callback` to inject `status:'publish'` when omitted.
 * The output is OVERRIDDEN to the catalog's flat field set via {@see shapeOutput()}.
 *
 * Write annotations (`readonly:false, destructive:false, idempotent:false`) on
 * `meta.annotations` so the run controller routes the call as POST.
 *
 * @since 0.2.0
 */
final class CreatePattern implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-templates/create-pattern';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/blocks',
				'method'          => 'POST',
				'label'           => __( 'Create Pattern', 'abilities-catalog' ),
				'description'     => __( 'Creates a new user pattern (reusable block, post type "wp_block"). Publishes by default; set status to "draft" to keep it unpublished. Returns edit_link (the Site Editor URL) — surface it so a human can open and finish the pattern. Requires the publish capability.', 'abilities-catalog' ),
				'category'        => 'og-core-templates',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'title'   => array(
							'type'        => 'string',
							'description' => __( 'The pattern title.', 'abilities-catalog' ),
						),
						'content' => array(
							'type'        => 'string',
							'description' => __( 'The pattern block markup (serialized blocks; sanitized by WordPress).', 'abilities-catalog' ),
						),
						'status'  => array(
							'type'        => 'string',
							'enum'        => array( 'draft', 'publish' ),
							'default'     => 'publish',
							'description' => __( 'The pattern status. Defaults to "publish".', 'abilities-catalog' ),
						),
					),
					'required'             => array( 'title', 'content' ),
					'additionalProperties' => false,
				),
				'input_callback'  => array( $this, 'injectStatus' ),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'status', 'link' ),
					'properties'           => array(
						'id'        => array(
							'type'        => 'integer',
							'description' => __( 'The new pattern (wp_block) post ID.', 'abilities-catalog' ),
						),
						'title'     => array(
							'type'        => 'string',
							'description' => __( 'The resulting pattern title.', 'abilities-catalog' ),
						),
						'status'    => array(
							'type'        => 'string',
							'description' => __( 'The resulting pattern status.', 'abilities-catalog' ),
						),
						'link'      => array(
							'type'        => 'string',
							'description' => __( 'The pattern permalink.', 'abilities-catalog' ),
						),
						'edit_link' => array(
							'type'        => 'string',
							'description' => __( 'The Site Editor URL where a human can open and edit the new pattern.', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_callback' => array( $this, 'shapeOutput' ),
				'meta'            => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
					'screen'       => 'site-editor.php',
				),
			)
		);
	}

	/**
	 * Injects the declared "publish" default when status is omitted or empty.
	 *
	 * Wired as the adapter's `input_callback`, so it runs once per `execute()`, after
	 * the ability validates input against its schema and before dispatch. The Abilities
	 * API only applies top-level schema defaults, not nested property ones, so without
	 * this core would silently fall back to "draft" on the REST route.
	 *
	 * @param array<string,mixed> $params The request params after schema validation.
	 * @return array<string,mixed> The params with `status` set.
	 */
	public function injectStatus( array $params ): array {
		$params['status'] = isset( $params['status'] ) && '' !== $params['status']
			? sanitize_key( (string) $params['status'] )
			: 'publish';

		return $params;
	}

	/**
	 * Flattens the REST block body to the catalog's create-pattern field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST `wp_block` body. The blocks controller exposes `title.raw` (it unsets
	 * `title.rendered`), so read raw first to avoid an always-empty title. The
	 * `edit_link` is built from the new post ID with `admin_url()` — a non-REST helper
	 * that runs fine post-dispatch as the current user. `$input` and `$response` are
	 * part of the callback signature but unused here.
	 *
	 * @param mixed               $data     The REST block body (associative array).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat pattern fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data = is_array( $data ) ? $data : array();

		$title = $data['title'] ?? '';
		if ( is_array( $title ) ) {
			$title = $title['raw'] ?? ( $title['rendered'] ?? '' );
		}

		$id        = (int) ( $data['id'] ?? 0 );
		$edit_link = $id > 0
			? admin_url( 'site-editor.php?postType=wp_block&postId=' . rawurlencode( (string) $id ) . '&canvas=edit' )
			: '';

		return array(
			'id'        => $id,
			'title'     => (string) $title,
			'status'    => (string) ( $data['status'] ?? '' ),
			'link'      => (string) ( $data['link'] ?? '' ),
			'edit_link' => $edit_link,
		);
	}
}
