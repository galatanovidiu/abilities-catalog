<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Abilities\Tools;

use Automattic\AbilitiesCatalog\Contracts\Ability;
use Automattic\AbilitiesCatalog\Support\AdminIncludes;
use WP_Error;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * T1 read ability: `tools/list-importers`.
 *
 * Reports the importers registered with the site via the core
 * `register_importer()` mechanism. Wraps `get_importers()`, which returns the
 * `$wp_importers` global as a map of importer id to descriptor. Each descriptor
 * is shaped into a flat `{id, name, description}` record. An empty list is a
 * valid result on a site with no importer plugins active.
 *
 * @since 0.1.0
 */
final class ListImporters implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'tools/list-importers';
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): array
	{
		return array(
			'slug'        => 'tools',
			'label'       => __('Tools', 'abilities-catalog'),
			'description' => __('Abilities that read importer availability and export site content.', 'abilities-catalog'),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('List Importers', 'abilities-catalog'),
			'description'         => __('Returns the importers registered with the site, each with its id, name, and description.', 'abilities-catalog'),
			'category'            => 'tools',
			'input_schema'        => array(),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('items'),
				'properties'           => array(
					'items' => array(
						'type'        => 'array',
						'description' => __('The registered importers.', 'abilities-catalog'),
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
	 * Permission check: the current user may import content.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user has the `import` capability.
	 */
	public function hasPermission($input = null): bool
	{
		return current_user_can('import');
	}

	/**
	 * Executes the ability by reading the registered importers.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|WP_Error The shaped importer list.
	 */
	public function execute($input = null)
	{
		AdminIncludes::load('import');

		if (!function_exists('get_importers')) {
			return new WP_Error(
				'importers_unavailable',
				__('The importer registry is not available.', 'abilities-catalog'),
				array('status' => 500)
			);
		}

		$importers = get_importers();
		$importers = is_array($importers) ? $importers : array();
		$items     = array();

		foreach ($importers as $id => $importer) {
			$importer = is_array($importer) ? $importer : array();

			$items[] = array(
				'id'          => (string) $id,
				'name'        => (string) ($importer[0] ?? ''),
				'description' => (string) ($importer[1] ?? ''),
			);
		}

		return array(
			'items' => $items,
		);
	}
}
