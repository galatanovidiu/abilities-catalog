<?php
/**
 * Shared MCP resources and prompts for the catalog servers.
 *
 * @package AbilitiesCatalog
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Mcp;

use GalatanOvidiu\AbilitiesCatalog\Mcp\Knowledge\KnowledgeRegistry;
use WP\MCP\Domain\Prompts\McpPrompt;
use WP\MCP\Domain\Resources\McpResource;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the read-only resources and workflow prompt shared by both catalog servers.
 *
 * The resources are alternative discovery surfaces over the same live catalog and
 * knowledge registry the tools use; they do not create a second source of truth.
 * The prompt teaches a client how to select whichever bounded discovery tools the
 * connected server exposes before executing an ability.
 *
 * @since n.e.x.t
 */
final class DiscoveryContentFactory {

	/** URI for the live ability capability map. */
	public const CAPABILITIES_URI = 'abilities-catalog://capabilities';

	/** URI for the live knowledge index. */
	public const KNOWLEDGE_URI = 'abilities-catalog://knowledge';

	/** Name of the shared discovery workflow prompt. */
	public const WORKFLOW_PROMPT = 'find-wordpress-ability';

	/**
	 * Builds the two shared resources.
	 *
	 * @param callable $permission The server's coarse authentication floor.
	 * @return list<\WP\MCP\Domain\Resources\McpResource> The resources that projected successfully.
	 */
	public static function resources( callable $permission ): array {
		$index     = new AbilityIndex( new ExposurePolicy() );
		$knowledge = new KnowledgeRegistry();

		$specs = array(
			array(
				'uri'         => self::CAPABILITIES_URI,
				'name'        => 'ability-capability-map',
				'title'       => __( 'WordPress Ability Capability Map', 'abilities-catalog' ),
				'description' => __( 'Live, bounded overview of the WordPress ability categories available on this site, including total and enabled counts.', 'abilities-catalog' ),
				'mimeType'    => 'application/json',
				'annotations' => array(
					'audience' => array( 'assistant' ),
					'priority' => 0.9,
				),
				'handler'     => static function () use ( $index ): array {
					$text = wp_json_encode( $index->overview(), JSON_PRETTY_PRINT );

					return array(
						array(
							'uri'      => self::CAPABILITIES_URI,
							'mimeType' => 'application/json',
							'text'     => false === $text ? '{}' : $text,
						),
					);
				},
			),
			array(
				'uri'         => self::KNOWLEDGE_URI,
				'name'        => 'knowledge-index',
				'title'       => __( 'WordPress Site Knowledge Index', 'abilities-catalog' ),
				'description' => __( 'Live site facts and the index of every registered knowledge concept. Use the knowledge tool to load a concept body by URI.', 'abilities-catalog' ),
				'mimeType'    => 'text/markdown',
				'annotations' => array(
					'audience' => array( 'assistant' ),
					'priority' => 1.0,
				),
				'handler'     => static fn (): array => array(
					array(
						'uri'      => self::KNOWLEDGE_URI,
						'mimeType' => 'text/markdown',
						'text'     => $knowledge->rootIndex(),
					),
				),
			),
		);

		$resources = array();
		foreach ( $specs as $spec ) {
			$spec['permission'] = $permission;
			$resource           = McpResource::fromArray( $spec );
			if ( is_wp_error( $resource ) ) {
				self::log( sprintf( 'Failed to build the "%s" MCP resource: %s', $spec['uri'], $resource->get_error_message() ) );

				continue;
			}

			$resources[] = $resource;
		}

		return $resources;
	}

	/**
	 * Builds the shared find-and-run workflow prompt.
	 *
	 * @param callable $permission The server's coarse authentication floor.
	 * @return list<\WP\MCP\Domain\Prompts\McpPrompt> The prompts that projected successfully.
	 */
	public static function prompts( callable $permission ): array {
		$prompt = McpPrompt::fromArray(
			array(
				'name'        => self::WORKFLOW_PROMPT,
				'title'       => __( 'Find a WordPress Ability', 'abilities-catalog' ),
				'description' => __( 'Builds a safe discovery-first workflow for a WordPress task: inspect the available tools, find the matching ability, read its exact schema and safety annotations, then execute only when authorized.', 'abilities-catalog' ),
				'arguments'   => array(
					array(
						'name'        => 'task',
						'description' => __( 'The WordPress task to find an ability for.', 'abilities-catalog' ),
						'required'    => true,
					),
				),
				'handler'     => static function ( array $args ): array {
					$task = is_string( $args['task'] ?? null ) ? trim( $args['task'] ) : '';
					if ( '' === $task ) {
						$task = __( 'Inspect this WordPress site and choose the appropriate ability.', 'abilities-catalog' );
					}

					return array(
						'description' => __( 'Discovery-first WordPress ability workflow.', 'abilities-catalog' ),
						'messages'    => array(
							array(
								'role'    => 'user',
								'content' => array(
									'type' => 'text',
									'text' => sprintf(
										/* translators: %s: the WordPress task supplied to the prompt. */
										__( "Task: %s\n\nInspect this server's tools first. If it exposes overview/search-abilities/describe-ability, use that bounded workflow. Otherwise choose the matching domain tool, call action=list, then action=describe. Read the exact input schema and safety annotations before calling execute. Do not guess ability names or fields, and do not execute a disabled or unauthorized ability.", 'abilities-catalog' ),
										$task
									),
								),
							),
						),
					);
				},
				'permission'  => $permission,
			)
		);

		if ( is_wp_error( $prompt ) ) {
			self::log( 'Failed to build the shared MCP workflow prompt: ' . $prompt->get_error_message() );

			return array();
		}

		return array( $prompt );
	}

	/** Logs a component-construction failure under WP_DEBUG. */
	private static function log( string $message ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-guarded component construction diagnostic.
		error_log( 'Abilities Catalog: ' . $message );
	}
}
