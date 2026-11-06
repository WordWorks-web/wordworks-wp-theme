<?php
/**
 * Resolves internal links and Media Library images inside the theme's patterns.
 *
 * Patterns are generated with two kinds of placeholders:
 *  - __WWURL:<key>__  internal page link (key = page slug in the design, e.g. "contacto").
 *  - image blocks/covers using the reserved ids 900001+ and the url
 *    https://media.wordworks.invalid/<n>, described by the $images array
 *    passed to wordworks_render().
 *
 * The deploy script fills the options "wordworks_page_map" (key => page ID)
 * and "wordworks_media_map" (file name => attachment ID).
 *
 * @package wordworks
 */

defined( 'ABSPATH' ) || exit;

/**
 * Page URL for a design page key.
 *
 * @param string $key Page key (e.g. "contacto", "nuestra-historia", "home").
 * @return string
 */
function wordworks_url( $key ) {
	if ( 'home' === $key || '' === $key ) {
		return home_url( '/' );
	}
	static $primed = false;
	$map           = get_option( 'wordworks_page_map', array() );
	if ( ! $primed && is_array( $map ) && $map ) {
		_prime_post_caches( array_map( 'intval', array_values( $map ) ), false, false );
		$primed = true;
	}
	if ( is_array( $map ) && ! empty( $map[ $key ] ) ) {
		$link = get_permalink( (int) $map[ $key ] );
		if ( $link ) {
			return $link;
		}
	}
	$page = get_page_by_path( $key );
	if ( $page ) {
		return get_permalink( $page );
	}
	return home_url( '/' . $key . '/' );
}

/**
 * Attachment ID for a design image file name, or 0.
 *
 * @param string $file File name as named in the design (e.g. "quienes-somos-2.png").
 * @return int
 */
function wordworks_attachment_id( $file ) {
	static $cache = array();
	if ( isset( $cache[ $file ] ) ) {
		return $cache[ $file ];
	}
	$id  = 0;
	$map = get_option( 'wordworks_media_map', array() );
	if ( is_array( $map ) && ! empty( $map[ $file ] ) && get_post( (int) $map[ $file ] ) ) {
		$id = (int) $map[ $file ];
	}
	if ( ! $id ) {
		// Look the file up by name: "2024/05/quienes-somos-2.png", also accent-free and with
		// WordPress' "-1"/"-scaled" suffixes. Names without an extension match any image type.
		global $wpdb;
		$stem  = pathinfo( $file, PATHINFO_FILENAME );
		$ext   = pathinfo( $file, PATHINFO_EXTENSION );
		$stems = array_unique( array( $stem, remove_accents( $stem ), sanitize_file_name( $stem ) ) );
		foreach ( $stems as $s ) {
			$base = '%/' . $wpdb->esc_like( $s );
			$id   = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND ( meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s ) ORDER BY post_id DESC LIMIT 1",
					$base . ( $ext ? '.' . $wpdb->esc_like( $ext ) : '.%' ),
					$base . '-scaled.' . ( $ext ? $wpdb->esc_like( $ext ) : '%' ),
					$wpdb->esc_like( $s ) . ( $ext ? '.' . $wpdb->esc_like( $ext ) : '.%' )
				)
			);
			if ( $id ) {
				break;
			}
		}
	}
	$cache[ $file ] = $id;
	return $id;
}

/**
 * Replace link and image placeholders in generated pattern markup.
 *
 * @param string $markup Block markup.
 * @param array  $images Map of placeholder id => array( 'file' => ..., 'kind' => 'cover'|'image', 'fallback' => markup ).
 * @return string
 */
function wordworks_render( $markup, $images = array() ) {
	$markup = preg_replace_callback(
		'/__WWURL:([a-z0-9\-]*)__/',
		function ( $m ) {
			return esc_url( wordworks_url( $m[1] ) );
		},
		$markup
	);

	foreach ( $images as $ph => $img ) {
		$ph     = (int) $ph;
		$ph_url = 'https://media.wordworks.invalid/' . $ph;
		$id     = wordworks_attachment_id( $img['file'] );
		$url    = $id ? wp_get_attachment_image_url( $id, 'full' ) : '';

		if ( $id && $url ) {
			$markup = str_replace(
				array( '"id":' . $ph, 'wp-image-' . $ph, $ph_url ),
				array( '"id":' . $id, 'wp-image-' . $id, esc_url( $url ) ),
				$markup
			);
			continue;
		}

		if ( 'cover' === $img['kind'] ) {
			// Cover without image: drop url/id and the <img>, keep the section and its colour fallback.
			$markup = str_replace( array( '"url":"' . $ph_url . '",', '"id":' . $ph . ',' ), '', $markup );
			$markup = preg_replace( '/<img class="wp-block-cover__image-background wp-image-' . $ph . '"[^>]*\/>/', '', $markup );
		} else {
			$markup = preg_replace(
				'/<!-- wp:image \{[^\n]*?"id":' . $ph . '\b[^\n]*?\} -->.*?<!-- \/wp:image -->/s',
				isset( $img['fallback'] ) ? $img['fallback'] : '',
				$markup
			);
		}
	}
	return $markup;
}
