<?php
/**
 * Word Works site setup. Run with WP-CLI from the WordPress root:
 *
 *   wp eval-file setup.php <command> [options]
 *
 * Commands
 *   media-plan <manifest.json> [live-base-url]
 *       For every image in the manifest that is NOT already in the Media Library,
 *       look for it on the live site (pages + their stylesheets) and print
 *       "file<TAB>url" lines, ready for `wp media import`.
 *   media-save <map.tsv>
 *       Store "file<TAB>attachment-id" lines in the wordworks_media_map option.
 *   media-report <manifest.json>
 *       Print which manifest images resolve to which attachment.
 *   pages <manifest.json> [--reset-customizations]
 *       Create or update every page from its pattern, assign templates, set the
 *       front page / posts page and store the wordworks_page_map option.
 *       Existing content is kept as a revision. With --reset-customizations,
 *       Site Editor customisations of this theme (templates, parts, global
 *       styles) are exported to a JSON backup and moved to the trash so the
 *       theme files take effect.
 *
 * @package wordworks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

// phpcs:disable WordPress.WP.AlternativeFunctions

$ww_args = isset( $args ) ? $args : array();
$ww_cmd  = array_shift( $ww_args );

/**
 * Print a line.
 *
 * @param string $msg Message.
 */
function ww_out( $msg ) {
	echo $msg . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Load the manifest.
 *
 * @param string $file Path.
 * @return array
 */
function ww_manifest( $file ) {
	$data = json_decode( (string) file_get_contents( $file ), true );
	if ( ! is_array( $data ) || empty( $data['pages'] ) ) {
		ww_out( "ERROR: cannot read manifest $file" );
		exit( 1 );
	}
	return $data;
}

/**
 * Normalise a file name for comparison: lower case, no accents, no size suffix.
 *
 * @param string $name File name or URL.
 * @return string
 */
function ww_norm( $name ) {
	$name = rawurldecode( wp_basename( (string) wp_parse_url( $name, PHP_URL_PATH ) ) );
	$name = strtolower( remove_accents( $name ) );
	$name = preg_replace( '/\.(png|jpe?g|webp|gif|svg|avif)$/', '', $name );
	$name = preg_replace( '/(-\d+x\d+|-scaled|-rotated|-e\d{10,})+$/', '', $name );
	return preg_replace( '/[^a-z0-9]+/', '-', $name );
}

/**
 * Fetch a URL body (cached per run).
 *
 * @param string $url URL.
 * @return string
 */
function ww_fetch( $url ) {
	static $cache = array();
	if ( isset( $cache[ $url ] ) ) {
		return $cache[ $url ];
	}
	$res           = wp_remote_get( $url, array( 'timeout' => 30, 'user-agent' => 'WordWorks-Theme-Setup/1.0' ) );
	$cache[ $url ] = ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ) ? wp_remote_retrieve_body( $res ) : '';
	return $cache[ $url ];
}

/**
 * Image URLs found on a live page, including its stylesheets (Elementor/background images).
 *
 * @param string $page_url Page URL.
 * @param string $base     Live site base URL.
 * @return string[]
 */
function ww_live_images( $page_url, $base ) {
	$html = ww_fetch( $page_url );
	if ( '' === $html ) {
		return array();
	}
	$port  = wp_parse_url( $base, PHP_URL_PORT );
	$host  = preg_quote( (string) wp_parse_url( $base, PHP_URL_HOST ) . ( $port ? ':' . $port : '' ), '#' );
	$found = array();
	$scan  = function ( $text ) use ( &$found, $host ) {
		$text = str_replace( array( '\\/', '&#038;', '&amp;' ), array( '/', '&', '&' ), $text );
		if ( preg_match_all( '#(?:https?:)?//(?:www\.)?' . $host . '/wp-content/uploads/[^"\'\s\)<>,]+?\.(?:png|jpe?g|webp|gif|svg|avif)#i', $text, $m ) ) {
			foreach ( $m[0] as $u ) {
				$found[ ( 0 === strpos( $u, '//' ) ? 'https:' : '' ) . $u ] = true;
			}
		}
	};
	$scan( $html );
	if ( preg_match_all( '#<link[^>]+href=["\']([^"\']+\.css[^"\']*)["\']#i', $html, $css ) ) {
		foreach ( $css[1] as $href ) {
			$href = html_entity_decode( $href );
			if ( false !== strpos( $href, '/wp-content/uploads/' ) || false !== strpos( $href, '/wp-content/themes/' ) ) {
				$scan( ww_fetch( $href ) );
			}
		}
	}
	return array_keys( $found );
}

/**
 * Live URLs per page key, discovered from the live home page's links (by link text),
 * falling back to /<slug>/.
 *
 * @param array  $manifest Manifest.
 * @param string $base     Live base URL.
 * @return array key => url
 */
function ww_live_urls( $manifest, $base ) {
	$base  = trailingslashit( $base );
	$home  = ww_fetch( $base );
	$links = array();
	if ( $home && preg_match_all( '#<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', $home, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $a ) {
			$text = strtolower( remove_accents( trim( wp_strip_all_tags( html_entity_decode( $a[2] ) ) ) ) );
			if ( $text && ! isset( $links[ $text ] ) && 0 === strpos( $a[1], rtrim( $base, '/' ) ) ) {
				$links[ $text ] = $a[1];
			}
		}
	}
	$out = array();
	foreach ( $manifest['pages'] as $p ) {
		$t = strtolower( remove_accents( $p['title'] ) );
		if ( $p['front_page'] ) {
			$out[ $p['key'] ] = $base;
		} elseif ( isset( $links[ $t ] ) ) {
			$out[ $p['key'] ] = $links[ $t ];
		} elseif ( 'faq' === $p['key'] && isset( $links['faqs'] ) ) {
			$out[ $p['key'] ] = $links['faqs'];
		} else {
			$out[ $p['key'] ] = $base . $p['slug'] . '/';
		}
	}
	return $out;
}

/**
 * Prefer the original upload over a resized copy when it exists.
 *
 * @param string $url Image URL.
 * @return string
 */
function ww_original( $url ) {
	$orig = preg_replace( '/(-\d+x\d+|-scaled)(\.[a-z]+)$/i', '$2', $url );
	if ( $orig !== $url ) {
		$head = wp_remote_head( $orig, array( 'timeout' => 20 ) );
		if ( ! is_wp_error( $head ) && 200 === wp_remote_retrieve_response_code( $head ) ) {
			return $orig;
		}
	}
	return $url;
}

switch ( $ww_cmd ) {
	case 'media-plan':
		$manifest = ww_manifest( $ww_args[0] );
		$base     = isset( $ww_args[1] ) ? $ww_args[1] : 'https://wordworks.es';
		$urls     = ww_live_urls( $manifest, $base );
		foreach ( $manifest['images'] as $img ) {
			if ( wordworks_attachment_id( $img['file'] ) ) {
				continue;
			}
			$want  = ww_norm( $img['file'] );
			$terms = $img['search'] ? array_map( 'ww_norm', $img['search'] ) : array();
			$pick  = '';
			$pages = array_merge( $img['pages'], array( 'home' ) );
			foreach ( $pages as $key ) {
				$cands = ww_live_images( $urls[ $key ], $base );
				foreach ( $cands as $u ) {
					if ( ww_norm( $u ) === $want ) {
						$pick = $u;
						break 2;
					}
				}
				foreach ( $terms as $t ) {
					foreach ( $cands as $u ) {
						if ( false !== strpos( ww_norm( $u ), $t ) ) {
							$pick = $u;
							break 3;
						}
					}
				}
			}
			if ( $pick ) {
				ww_out( $img['file'] . "\t" . ww_original( $pick ) );
			} else {
				fwrite( STDERR, 'NOT FOUND on live site: ' . $img['file'] . ' (' . implode( ' | ', $img['labels'] ) . ")\n" );
			}
		}
		break;

	case 'media-save':
		$map = get_option( 'wordworks_media_map', array() );
		$map = is_array( $map ) ? $map : array();
		foreach ( file( $ww_args[0], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
			list( $file, $id ) = array_pad( explode( "\t", $line ), 2, '' );
			if ( $file && (int) $id && get_post( (int) $id ) ) {
				$map[ $file ] = (int) $id;
				update_post_meta( (int) $id, '_wordworks_design_file', $file );
			}
		}
		update_option( 'wordworks_media_map', $map, false );
		ww_out( 'Media map: ' . count( $map ) . ' images.' );
		break;

	case 'media-report':
		$manifest = ww_manifest( $ww_args[0] );
		$missing  = 0;
		foreach ( $manifest['images'] as $img ) {
			$id = wordworks_attachment_id( $img['file'] );
			$missing += $id ? 0 : 1;
			ww_out( sprintf( '%-34s %s', $img['file'], $id ? '#' . $id . ' ' . wp_get_attachment_url( $id ) : 'MISSING (placeholder shown)' ) );
		}
		ww_out( $missing ? "$missing image(s) missing." : 'All images resolved.' );
		break;

	case 'pages':
		$manifest = ww_manifest( $ww_args[0] );
		$reset    = in_array( '--reset-customizations', $ww_args, true );
		$theme    = get_stylesheet();

		if ( 'wordworks' !== $theme ) {
			ww_out( "ERROR: active theme is '$theme'. Activate wordworks first." );
			exit( 1 );
		}

		// 1. Site Editor customisations of this theme override the theme files.
		$custom = get_posts(
			array(
				'post_type'      => array( 'wp_template', 'wp_template_part', 'wp_global_styles' ),
				'post_status'    => array( 'publish', 'draft', 'auto-draft' ),
				'posts_per_page' => -1,
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'taxonomy' => 'wp_theme',
						'field'    => 'name',
						'terms'    => $theme,
					),
				),
			)
		);
		if ( $custom ) {
			if ( $reset ) {
				$backup = WP_CONTENT_DIR . '/wordworks-site-editor-backup-' . gmdate( 'Ymd-His' ) . '.json';
				$dump   = array();
				foreach ( $custom as $c ) {
					$dump[] = array(
						'type'    => $c->post_type,
						'slug'    => $c->post_name,
						'title'   => $c->post_title,
						'content' => $c->post_content,
					);
					wp_trash_post( $c->ID );
				}
				file_put_contents( $backup, wp_json_encode( $dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
				ww_out( 'Moved ' . count( $custom ) . " Site Editor customisation(s) to the trash. Backup: $backup" );
			} else {
				ww_out( 'WARNING: ' . count( $custom ) . ' Site Editor customisation(s) override the theme files: ' . implode( ', ', wp_list_pluck( $custom, 'post_name' ) ) . '. Re-run with --reset-customizations to replace them.' );
			}
		}

		// 2. Find or create every page.
		$map      = array();
		$by_title = array();
		foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => array( 'publish', 'draft', 'private' ), 'posts_per_page' => -1 ) ) as $p ) {
			$by_title[ strtolower( remove_accents( trim( $p->post_title ) ) ) ][] = $p;
		}
		foreach ( $manifest['pages'] as $page ) {
			$found = null;
			if ( $page['front_page'] && get_option( 'page_on_front' ) ) {
				$found = get_post( (int) get_option( 'page_on_front' ) );
			}
			if ( ! $found && $page['posts_page'] && get_option( 'page_for_posts' ) ) {
				$found = get_post( (int) get_option( 'page_for_posts' ) );
			}
			if ( ! $found && '' !== $page['slug'] ) {
				$found = get_page_by_path( $page['slug'] );
			}
			$t = strtolower( remove_accents( $page['title'] ) );
			if ( ! $found && ! empty( $by_title[ $t ] ) ) {
				$found = $by_title[ $t ][0];
			}
			if ( ! $found && 'faq' === $page['key'] && ! empty( $by_title['faqs'] ) ) {
				$found = $by_title['faqs'][0];
			}
			if ( ! $found ) {
				$id = wp_insert_post(
					array(
						'post_type'   => 'page',
						'post_status' => 'publish',
						'post_title'  => $page['title'],
						'post_name'   => '' !== $page['slug'] ? $page['slug'] : 'inicio',
					),
					true
				);
				if ( is_wp_error( $id ) ) {
					ww_out( 'ERROR creating ' . $page['title'] . ': ' . $id->get_error_message() );
					continue;
				}
				$found = get_post( $id );
				ww_out( "Created page '{$page['title']}' (#$id)." );
			}
			$map[ $page['key'] ] = $found->ID;
		}
		update_option( 'wordworks_page_map', $map, false );

		// 3. Front page and posts page.
		foreach ( $manifest['pages'] as $page ) {
			if ( $page['front_page'] ) {
				update_option( 'show_on_front', 'page' );
				update_option( 'page_on_front', $map[ $page['key'] ] );
			}
			if ( $page['posts_page'] ) {
				update_option( 'page_for_posts', $map[ $page['key'] ] );
			}
		}

		// 4. Content from the patterns (rendered now, so links and images are resolved).
		$registry = WP_Block_Patterns_Registry::get_instance();
		foreach ( $manifest['pages'] as $page ) {
			$id = $map[ $page['key'] ];
			update_post_meta( $id, '_wp_page_template', $page['template'] ? $page['template'] : 'default' );
			if ( empty( $page['pattern'] ) ) {
				ww_out( "Page '{$page['title']}' (#$id): posts page, uses templates/home.html." );
				continue;
			}
			$file    = get_theme_file_path( 'patterns/' . str_replace( 'wordworks/', '', $page['pattern'] ) . '.php' );
			$content = '';
			if ( is_readable( $file ) ) {
				ob_start();
				include $file;
				$content = ob_get_clean();
			} elseif ( $registry->is_registered( $page['pattern'] ) ) {
				$content = $registry->get_registered( $page['pattern'] )['content'];
			}
			if ( '' === trim( $content ) ) {
				ww_out( "ERROR: empty pattern {$page['pattern']}" );
				continue;
			}
			$res = wp_update_post(
				array(
					'ID'           => $id,
					'post_content' => wp_slash( $content ),
					'post_status'  => 'publish',
				),
				true
			);
			ww_out( is_wp_error( $res ) ? "ERROR updating #$id: " . $res->get_error_message() : "Updated '{$page['title']}' (#$id) " . get_permalink( $id ) );
		}
		flush_rewrite_rules( false );
		break;

	default:
		ww_out( 'Usage: wp eval-file setup.php media-plan|media-save|media-report|pages ...' );
		exit( 1 );
}
