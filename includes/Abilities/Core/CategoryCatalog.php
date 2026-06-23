<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core;

use GalatanOvidiu\AbilitiesCatalog\Contracts\CategoryProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Category catalog for the Core ability group.
 *
 * One descriptor per core WordPress domain, keyed by slug. The {@see \GalatanOvidiu\AbilitiesCatalog\Registry}
 * discovers this provider alongside the abilities and registers each category on
 * `wp_abilities_api_categories_init`. Every Core ability references its category
 * through `args()['category']` (the slug), so a slug used by a Core ability MUST
 * exist here.
 *
 * Labels and descriptions call `__()`, so {@see categories()} must be invoked at
 * or after the relevant init hook (when translations are available), never at
 * file load.
 *
 * @since 0.4.0
 */
final class CategoryCatalog implements CategoryProvider {

	/**
	 * {@inheritDoc}
	 */
	public function categories(): array {
		return array(
			'comments'    => array(
				'slug'        => 'comments',
				'label'       => __( 'Comments', 'abilities-catalog' ),
				'description' => __( 'Abilities that read comments.', 'abilities-catalog' ),
			),
			'connectors'  => array(
				'slug'        => 'connectors',
				'label'       => __( 'Connectors', 'abilities-catalog' ),
				'description' => __( 'Abilities that read registered connectors (AI providers and other types, such as spam filtering), never exposing API keys.', 'abilities-catalog' ),
			),
			'content'     => array(
				'slug'        => 'content',
				'label'       => __( 'Content', 'abilities-catalog' ),
				'description' => __( 'Abilities that read posts, pages, and other content.', 'abilities-catalog' ),
			),
			'cron'        => array(
				'slug'        => 'cron',
				'label'       => __( 'Cron', 'abilities-catalog' ),
				'description' => __( 'Abilities that read WP-Cron scheduled events and recurrence schedules, and schedule or unschedule individual events.', 'abilities-catalog' ),
			),
			'dashboard'   => array(
				'slug'        => 'dashboard',
				'label'       => __( 'Dashboard', 'abilities-catalog' ),
				'description' => __( 'Composed read-only dashboard summaries (counts, recent activity, drafts).', 'abilities-catalog' ),
			),
			'fonts'       => array(
				'slug'        => 'fonts',
				'label'       => __( 'Fonts', 'abilities-catalog' ),
				'description' => __( 'Abilities that read installed font families and font collections.', 'abilities-catalog' ),
			),
			'media'       => array(
				'slug'        => 'media',
				'label'       => __( 'Media', 'abilities-catalog' ),
				'description' => __( 'Abilities that read and manage media library items, including a post\'s featured image.', 'abilities-catalog' ),
			),
			'menus'       => array(
				'slug'        => 'menus',
				'label'       => __( 'Menus', 'abilities-catalog' ),
				'description' => __( 'Abilities that read navigation (block) and classic menus.', 'abilities-catalog' ),
			),
			'plugins'     => array(
				'slug'        => 'plugins',
				'label'       => __( 'Plugins', 'abilities-catalog' ),
				'description' => __( 'Abilities that read installed plugins.', 'abilities-catalog' ),
			),
			'privacy'     => array(
				'slug'        => 'privacy',
				'label'       => __( 'Privacy', 'abilities-catalog' ),
				'description' => __( 'Abilities that read personal-data export and erasure requests.', 'abilities-catalog' ),
			),
			'search'      => array(
				'slug'        => 'search',
				'label'       => __( 'Search', 'abilities-catalog' ),
				'description' => __( 'Abilities that search across site content (posts, pages, terms) using WordPress\'s unified search, and read the site\'s XML sitemap configuration.', 'abilities-catalog' ),
			),
			'settings'    => array(
				'slug'        => 'settings',
				'label'       => __( 'Settings', 'abilities-catalog' ),
				'description' => __( 'Abilities that read site settings and manage URL rewrite (permalink) rules.', 'abilities-catalog' ),
			),
			'site-health' => array(
				'slug'        => 'site-health',
				'label'       => __( 'Site Health', 'abilities-catalog' ),
				'description' => __( 'Abilities that read Site Health status, tests, and debug information.', 'abilities-catalog' ),
			),
			'templates'   => array(
				'slug'        => 'templates',
				'label'       => __( 'Templates', 'abilities-catalog' ),
				'description' => __( 'Abilities that read and manage site-editor data: templates, template parts, patterns, global styles, and block binding sources.', 'abilities-catalog' ),
			),
			'terms'       => array(
				'slug'        => 'terms',
				'label'       => __( 'Terms', 'abilities-catalog' ),
				'description' => __( 'Abilities that read taxonomy terms (categories, tags, and custom taxonomies).', 'abilities-catalog' ),
			),
			'themes'      => array(
				'slug'        => 'themes',
				'label'       => __( 'Themes', 'abilities-catalog' ),
				'description' => __( 'Abilities that read installed themes and manage the active theme\'s customizer settings (theme mods).', 'abilities-catalog' ),
			),
			'tools'       => array(
				'slug'        => 'tools',
				'label'       => __( 'Tools', 'abilities-catalog' ),
				'description' => __( 'Abilities for maintenance tools: transients and the object cache, importer availability and content export, and sending a test email.', 'abilities-catalog' ),
			),
			'updates'     => array(
				'slug'        => 'updates',
				'label'       => __( 'Updates', 'abilities-catalog' ),
				'description' => __( 'Abilities that read available core, plugin, theme, and translation updates.', 'abilities-catalog' ),
			),
			'users'       => array(
				'slug'        => 'users',
				'label'       => __( 'Users', 'abilities-catalog' ),
				'description' => __( 'Abilities that read user accounts and profiles.', 'abilities-catalog' ),
			),
			'widgets'     => array(
				'slug'        => 'widgets',
				'label'       => __( 'Widgets', 'abilities-catalog' ),
				'description' => __( 'Abilities that read and manage widgets and sidebars (widget areas).', 'abilities-catalog' ),
			),
		);
	}
}
