<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Support;

use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves a post for an editing ability, returning specific errors.
 *
 * The Abilities API runs an ability's `permission_callback` inside `execute()`
 * and, on any non-`true` return, replaces the result with a single generic
 * "does not have necessary permission" error — a `WP_Error` returned from the
 * permission callback is swallowed. So an ability cannot surface a 404 (bad id)
 * or a 403 (real authorization failure) as distinct errors from the permission
 * callback; both collapse into the same opaque message.
 *
 * Abilities that call core functions directly (no wrapped REST route to produce
 * those errors) use this helper inside `execute()` to surface the distinction:
 * a non-existent post returns `rest_post_invalid_id` (404), and a post the
 * current user may not edit returns `rest_cannot_edit` (403). The object-level
 * capability is still enforced server-side — it just moves from the permission
 * callback into `execute()`, where its error reaches the caller.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability.
 *
 * @since 0.6.0
 */
final class PostAccess {

	/**
	 * Resolves a post the current user may edit, or a specific error.
	 *
	 * @param int $id The post ID.
	 * @return \WP_Post|\WP_Error The post when it exists and the user may edit it;
	 *                            a 404 `WP_Error` when it does not exist; a 403
	 *                            `WP_Error` when the user may not edit it.
	 */
	public static function resolveEditable( int $id ) {
		$post = $id > 0 ? get_post( $id ) : null;

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'rest_post_invalid_id',
				__( 'Invalid post ID.', 'abilities-catalog' ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error(
				'rest_cannot_edit',
				__( 'Sorry, you are not allowed to edit this post.', 'abilities-catalog' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return $post;
	}
}
