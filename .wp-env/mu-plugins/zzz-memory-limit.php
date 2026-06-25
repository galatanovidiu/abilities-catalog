<?php
/**
 * Raise PHP memory for the full local plugin stack (WooCommerce + Yoast SEO +
 * abilities-catalog and its add-ons), which exceeds the wp-env base image's
 * 128M default during WP-CLI runs (wp-env's own startup lifecycle uses WP-CLI).
 *
 * wp-env 11.7 has no memory_limit option, and its mappings are locked under the
 * docroot so they cannot reach /usr/local/etc/php/conf.d. A must-use plugin loads
 * before the active plugins on both web and WP-CLI requests, and ini_set works
 * here because the limit is plain php.ini (no authoritative php_admin_value).
 *
 * ponytail: 512M covers the current stack (peak ~95M + WP-CLI framework); bump if
 * a heavier plugin set OOMs.
 */

@ini_set( 'memory_limit', '512M' );
