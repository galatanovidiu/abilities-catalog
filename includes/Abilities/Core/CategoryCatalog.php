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
 * Slugs are namespaced `og-core-*` and labels are prefixed `OG Core …` so this
 * group's categories never collide with core's or another plugin's.
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
			'og-core-comments'    => array(
				'slug'        => 'og-core-comments',
				'label'       => __( 'OG Core Comments', 'abilities-catalog' ),
				'description' => __( 'Abilities that read comments.', 'abilities-catalog' ),
			),
			'og-core-connectors'  => array(
				'slug'        => 'og-core-connectors',
				'label'       => __( 'OG Core Connectors', 'abilities-catalog' ),
				'description' => __( 'Abilities that read registered connectors (AI providers and other types, such as spam filtering), never exposing API keys.', 'abilities-catalog' ),
			),
			'og-core-content'     => array(
				'slug'        => 'og-core-content',
				'label'       => __( 'OG Core Content', 'abilities-catalog' ),
				'description' => __( 'Abilities that read posts, pages, and other content.', 'abilities-catalog' ),
			),
			'og-core-cron'        => array(
				'slug'        => 'og-core-cron',
				'label'       => __( 'OG Core Cron', 'abilities-catalog' ),
				'description' => __( 'Abilities that read WP-Cron scheduled events and recurrence schedules, and schedule or unschedule individual events.', 'abilities-catalog' ),
			),
			'og-core-dashboard'   => array(
				'slug'        => 'og-core-dashboard',
				'label'       => __( 'OG Core Dashboard', 'abilities-catalog' ),
				'description' => __( 'Composed read-only dashboard summaries (counts, recent activity, drafts).', 'abilities-catalog' ),
			),
			'og-core-fonts'       => array(
				'slug'        => 'og-core-fonts',
				'label'       => __( 'OG Core Fonts', 'abilities-catalog' ),
				'description' => __( 'Abilities that read installed font families and font collections.', 'abilities-catalog' ),
			),
			'og-core-media'       => array(
				'slug'        => 'og-core-media',
				'label'       => __( 'OG Core Media', 'abilities-catalog' ),
				'description' => __( 'Abilities that read and manage media library items, including a post\'s featured image.', 'abilities-catalog' ),
			),
			'og-core-menus'       => array(
				'slug'        => 'og-core-menus',
				'label'       => __( 'OG Core Menus', 'abilities-catalog' ),
				'description' => __( 'Abilities that read navigation (block) and classic menus.', 'abilities-catalog' ),
			),
			'og-core-network'     => array(
				'slug'        => 'og-core-network',
				'label'       => __( 'OG Core Network', 'abilities-catalog' ),
				'description' => __( 'Abilities that read and manage multisite network state: sites, networks, super admins, and network options.', 'abilities-catalog' ),
			),
			'og-core-plugins'     => array(
				'slug'        => 'og-core-plugins',
				'label'       => __( 'OG Core Plugins', 'abilities-catalog' ),
				'description' => __( 'Abilities that read installed plugins.', 'abilities-catalog' ),
			),
			'og-core-privacy'     => array(
				'slug'        => 'og-core-privacy',
				'label'       => __( 'OG Core Privacy', 'abilities-catalog' ),
				'description' => __( 'Abilities that read personal-data export and erasure requests.', 'abilities-catalog' ),
			),
			'og-core-search'      => array(
				'slug'        => 'og-core-search',
				'label'       => __( 'OG Core Search', 'abilities-catalog' ),
				'description' => __( 'Abilities that search across site content (posts, pages, terms) using WordPress\'s unified search, and read the site\'s XML sitemap configuration.', 'abilities-catalog' ),
			),
			'og-core-settings'    => array(
				'slug'        => 'og-core-settings',
				'label'       => __( 'OG Core Settings', 'abilities-catalog' ),
				'description' => __( 'Abilities that read site settings and manage URL rewrite (permalink) rules.', 'abilities-catalog' ),
			),
			'og-core-site-health' => array(
				'slug'        => 'og-core-site-health',
				'label'       => __( 'OG Core Site Health', 'abilities-catalog' ),
				'description' => __( 'Abilities that read Site Health status, tests, and debug information.', 'abilities-catalog' ),
			),
			'og-core-templates'   => array(
				'slug'        => 'og-core-templates',
				'label'       => __( 'OG Core Templates', 'abilities-catalog' ),
				'description' => __( 'Abilities that read and manage site-editor data: templates, template parts, patterns, global styles, and block binding sources.', 'abilities-catalog' ),
			),
			'og-core-terms'       => array(
				'slug'        => 'og-core-terms',
				'label'       => __( 'OG Core Terms', 'abilities-catalog' ),
				'description' => __( 'Abilities that read taxonomy terms (categories, tags, and custom taxonomies).', 'abilities-catalog' ),
			),
			'og-core-themes'      => array(
				'slug'        => 'og-core-themes',
				'label'       => __( 'OG Core Themes', 'abilities-catalog' ),
				'description' => __( 'Abilities that read installed themes and manage the active theme\'s customizer settings (theme mods).', 'abilities-catalog' ),
			),
			'og-core-tools'       => array(
				'slug'        => 'og-core-tools',
				'label'       => __( 'OG Core Tools', 'abilities-catalog' ),
				'description' => __( 'Abilities for maintenance tools: transients and the object cache, importer availability and content export, and sending a test email.', 'abilities-catalog' ),
			),
			'og-core-updates'     => array(
				'slug'        => 'og-core-updates',
				'label'       => __( 'OG Core Updates', 'abilities-catalog' ),
				'description' => __( 'Abilities that read available core, plugin, theme, and translation updates.', 'abilities-catalog' ),
			),
			'og-core-users'       => array(
				'slug'        => 'og-core-users',
				'label'       => __( 'OG Core Users', 'abilities-catalog' ),
				'description' => __( 'Abilities that read user accounts and profiles.', 'abilities-catalog' ),
			),
			'og-core-widgets'     => array(
				'slug'        => 'og-core-widgets',
				'label'       => __( 'OG Core Widgets', 'abilities-catalog' ),
				'description' => __( 'Abilities that read and manage widgets and sidebars (widget areas).', 'abilities-catalog' ),
			),
		);
	}
}
