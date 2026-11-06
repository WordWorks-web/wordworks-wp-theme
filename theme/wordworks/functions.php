<?php
/**
 * Word Works block theme.
 *
 * @package wordworks
 */

defined( 'ABSPATH' ) || exit;

define( 'WORDWORKS_VERSION', '1.0.0' );

require_once __DIR__ . '/inc/links-and-media.php';
require_once __DIR__ . '/inc/forms.php';

/**
 * Theme supports and editor styles.
 */
add_action(
	'after_setup_theme',
	function () {
		add_theme_support( 'wp-block-styles' );
		add_theme_support( 'editor-styles' );
		add_theme_support( 'post-thumbnails' );
		add_theme_support( 'responsive-embeds' );
		add_editor_style( 'assets/css/site.css' );
	}
);

/**
 * Front-end assets.
 */
add_action(
	'wp_enqueue_scripts',
	function () {
		$dir = get_template_directory();
		$uri = get_template_directory_uri();
		wp_enqueue_style( 'wordworks-site', $uri . '/assets/css/site.css', array(), (string) filemtime( $dir . '/assets/css/site.css' ) );
		wp_enqueue_script(
			'wordworks-motion',
			$uri . '/assets/js/motion.js',
			array(),
			(string) filemtime( $dir . '/assets/js/motion.js' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}
);

/**
 * Mark the document as "motion ready" before first paint, so scroll reveals
 * never flash. Skipped when the visitor prefers reduced motion; without JS
 * everything stays visible.
 */
add_action(
	'wp_head',
	function () {
		echo "<script>(function(d){try{if(!window.matchMedia('(prefers-reduced-motion: reduce)').matches&&'IntersectionObserver' in window){d.documentElement.classList.add('ww-motion');}}catch(e){}})(document);</script>\n";
	},
	1
);

/**
 * Pattern categories.
 */
add_action(
	'init',
	function () {
		register_block_pattern_category( 'wordworks-pages', array( 'label' => __( 'Word Works: páginas', 'wordworks' ) ) );
		register_block_pattern_category( 'wordworks', array( 'label' => __( 'Word Works: secciones', 'wordworks' ) ) );
	}
);

/**
 * Remove core's default block patterns so the inserter shows the site's own.
 */
add_action(
	'after_setup_theme',
	function () {
		remove_theme_support( 'core-block-patterns' );
	},
	20
);
