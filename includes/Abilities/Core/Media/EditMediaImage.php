<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Media;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Non-destructive write ability: `og-media/edit-media-image`.
 *
 * Wraps `POST /wp/v2/media/<id>/edit` via the Abilities REST Adapter to apply
 * image transforms (rotation, crop, or a `modifiers` array) to an existing image
 * attachment. The route creates a NEW attachment record and leaves the original
 * untouched, so this is non-destructive.
 *
 * The input schema is OVERRIDDEN with the catalog's hand-authored contract — it
 * is more agent-friendly than the raw route args (typed ranges, the `modifiers`
 * `oneOf` with its long human description). `id` stays required; the adapter
 * substitutes it as the route's path capture and forwards the rest as the body.
 * The route re-applies its own sanitize on dispatch, so no `input_callback` is
 * needed. The output is OVERRIDDEN to the catalog's flat `{ id, source_url }`
 * field set through {@see shapeOutput()}.
 *
 * Permission delegates to the route's own check (no `require_permission` floor):
 * `upload_files` AND object-level `edit_post` on the target attachment. Write
 * annotations (`readonly:false, destructive:false, idempotent:false`) are
 * declared explicitly, as the adapter requires for any write method.
 *
 * @since 0.3.0
 */
final class EditMediaImage implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-media/edit-media-image';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/media/(?P<id>[\d]+)/edit',
				'method'          => 'POST',
				'label'           => __( 'Edit Media Image', 'abilities-catalog' ),
				'description'     => __( 'Applies flip, rotation, and/or crop transforms to an existing image attachment, creating a new edited attachment. The original image is preserved.', 'abilities-catalog' ),
				'category'        => 'og-core-media',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'        => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'The image attachment ID to edit.', 'abilities-catalog' ),
						),
						'src'       => array(
							'type'        => 'string',
							'description' => __( 'URL to the image file being edited. May be the URL of the attachment\'s full-size, original, or any registered sub-size file.', 'abilities-catalog' ),
						),
						'rotation'  => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 359,
							'description' => __( 'Amount to rotate the image clockwise, in degrees (1-359).', 'abilities-catalog' ),
						),
						'x'         => array(
							'type'        => 'number',
							'minimum'     => 0,
							'maximum'     => 100,
							'description' => __( 'Crop start X position, as a percentage of the image width.', 'abilities-catalog' ),
						),
						'y'         => array(
							'type'        => 'number',
							'minimum'     => 0,
							'maximum'     => 100,
							'description' => __( 'Crop start Y position, as a percentage of the image height.', 'abilities-catalog' ),
						),
						'width'     => array(
							'type'        => 'number',
							'minimum'     => 0,
							'maximum'     => 100,
							'description' => __( 'Crop width, as a percentage of the image width.', 'abilities-catalog' ),
						),
						'height'    => array(
							'type'        => 'number',
							'minimum'     => 0,
							'maximum'     => 100,
							'description' => __( 'Crop height, as a percentage of the image height.', 'abilities-catalog' ),
						),
						'modifiers' => array(
							'type'        => 'array',
							'minItems'    => 1,
							'items'       => array(
								'type'                 => 'object',
								'required'             => array( 'type', 'args' ),
								'additionalProperties' => false,
								'oneOf'                => array(
									array(
										'title'      => __( 'Flip', 'abilities-catalog' ),
										'properties' => array(
											'type' => array(
												'type' => 'string',
												'enum' => array( 'flip' ),
												'description' => __( 'Flip type.', 'abilities-catalog' ),
											),
											'args' => array(
												'type'     => 'object',
												'required' => array( 'flip' ),
												'additionalProperties' => false,
												'properties' => array(
													'flip' => array(
														'type' => 'object',
														'required' => array( 'horizontal', 'vertical' ),
														'additionalProperties' => false,
														'properties' => array(
															'horizontal' => array(
																'type'        => 'boolean',
																'description' => __( 'Whether to flip in the horizontal direction.', 'abilities-catalog' ),
															),
															'vertical'   => array(
																'type'        => 'boolean',
																'description' => __( 'Whether to flip in the vertical direction.', 'abilities-catalog' ),
															),
														),
														'description' => __( 'Flip direction.', 'abilities-catalog' ),
													),
												),
												'description' => __( 'Flip arguments.', 'abilities-catalog' ),
											),
										),
									),
									array(
										'title'      => __( 'Rotation', 'abilities-catalog' ),
										'properties' => array(
											'type' => array(
												'type' => 'string',
												'enum' => array( 'rotate' ),
												'description' => __( 'Rotation type.', 'abilities-catalog' ),
											),
											'args' => array(
												'type'     => 'object',
												'required' => array( 'angle' ),
												'additionalProperties' => false,
												'properties' => array(
													'angle' => array(
														'type' => 'number',
														'description' => __( 'Angle to rotate clockwise in degrees.', 'abilities-catalog' ),
													),
												),
												'description' => __( 'Rotation arguments.', 'abilities-catalog' ),
											),
										),
									),
									array(
										'title'      => __( 'Crop', 'abilities-catalog' ),
										'properties' => array(
											'type' => array(
												'type' => 'string',
												'enum' => array( 'crop' ),
												'description' => __( 'Crop type.', 'abilities-catalog' ),
											),
											'args' => array(
												'type'     => 'object',
												'required' => array( 'left', 'top', 'width', 'height' ),
												'additionalProperties' => false,
												'properties' => array(
													'left' => array(
														'type' => 'number',
														'description' => __( 'Horizontal position from the left to begin the crop as a percentage of the image width.', 'abilities-catalog' ),
													),
													'top'  => array(
														'type' => 'number',
														'description' => __( 'Vertical position from the top to begin the crop as a percentage of the image height.', 'abilities-catalog' ),
													),
													'width' => array(
														'type' => 'number',
														'description' => __( 'Width of the crop as a percentage of the image width.', 'abilities-catalog' ),
													),
													'height' => array(
														'type' => 'number',
														'description' => __( 'Height of the crop as a percentage of the image height.', 'abilities-catalog' ),
													),
												),
												'description' => __( 'Crop arguments.', 'abilities-catalog' ),
											),
										),
									),
								),
							),
							'description' => __( 'Array of image edits applied in order. Each item is an object shaped {"type": "flip"|"rotate"|"crop", "args": {...for that type}} — "type" and "args" are properties INSIDE each array item, never top-level input fields. Example, rotate 90 degrees clockwise: {"modifiers":[{"type":"rotate","args":{"angle":90}}]}. For a plain clockwise rotation the top-level "rotation" field (1-359) is a simpler alternative. When given, modifiers take precedence over the top-level rotation/crop fields.', 'abilities-catalog' ),
						),
					),
					'required'             => array( 'id', 'src' ),
					'additionalProperties' => false,
				),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'source_url' ),
					'properties'           => array(
						'id'         => array(
							'type'        => 'integer',
							'description' => __( 'The new edited attachment ID.', 'abilities-catalog' ),
						),
						'source_url' => array(
							'type'        => 'string',
							'description' => __( 'The direct URL of the edited image file.', 'abilities-catalog' ),
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
					'screen'       => 'post.php?post={id}&action=edit',
				),
			)
		);
	}

	/**
	 * Flattens the REST edit response to the catalog's `{ id, source_url }` shape.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * new attachment's REST body. `id` and `source_url` copy across with a type cast
	 * and a safe default. `$input` and `$response` are part of the callback signature
	 * but unused here — the body carries everything this shape needs.
	 *
	 * @param mixed               $data     The REST attachment body (associative array).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The new attachment's id and source URL.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data = is_array( $data ) ? $data : array();

		return array(
			'id'         => (int) ( $data['id'] ?? 0 ),
			'source_url' => (string) ( $data['source_url'] ?? '' ),
		);
	}
}
