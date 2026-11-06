<?php
/**
 * Contact / "Trabaja con nosotras" forms: [wordworks_form type="contacto"] and [wordworks_form type="trabaja"].
 *
 * Markup follows the design exactly. Submissions are e-mailed to the address in
 * the "wordworks_form_to" option (default info@wordworks.es). Spam protection: a
 * honeypot field plus a minimum fill time, so it also works on cached pages.
 *
 * @package wordworks
 */

defined( 'ABSPATH' ) || exit;

/**
 * Field definitions per form type.
 *
 * @return array
 */
function wordworks_form_types() {
	return array(
		'contacto' => array(
			'class'   => 'form rounded',
			'subject' => 'Nuevo mensaje desde el formulario de contacto',
			'align'   => 'left',
			'fields'  => array(
				'nombre'   => array( 'Nombre completo*', 'text', 'Escribe tu nombre completo' ),
				'email'    => array( 'Correo electrónico*', 'email', 'Tu dirección de correo electrónico' ),
				'telefono' => array( 'Teléfono de contacto*', 'tel', 'Número de teléfono con prefijo' ),
				'mensaje'  => array( 'Mensaje*', 'textarea', '¿Cómo podemos ayudarte?' ),
			),
		),
		'trabaja'  => array(
			'class'   => 'form',
			'subject' => 'Nuevo mensaje desde "Trabaja con nosotras"',
			'align'   => 'center',
			'fields'  => array(
				'nombre'  => array( 'Nombre completo*', 'text', 'Tu nombre completo' ),
				'email'   => array( 'Correo electrónico*', 'email', 'Tu dirección de correo electrónico' ),
				'mensaje' => array( 'Mensaje*', 'textarea', 'Déjanos tu mensaje y te responderemos pronto.' ),
			),
		),
	);
}

add_shortcode(
	'wordworks_form',
	function ( $atts ) {
		$atts  = shortcode_atts( array( 'type' => 'contacto' ), $atts, 'wordworks_form' );
		$types = wordworks_form_types();
		$type  = isset( $types[ $atts['type'] ] ) ? $atts['type'] : 'contacto';
		$def   = $types[ $type ];
		$uid   = 'ww-' . $type;

		$status = isset( $_GET['ww_form'] ) ? sanitize_key( wp_unslash( $_GET['ww_form'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$out    = '';
		if ( 'ok' === $status ) {
			$out .= '<p class="form-msg" role="status">Gracias. Hemos recibido tu mensaje y te responderemos pronto.</p>';
		} elseif ( 'error' === $status ) {
			$out .= '<p class="form-msg is-error" role="alert">No hemos podido enviar tu mensaje. Inténtalo de nuevo o escríbenos a info@wordworks.es.</p>';
		}

		$out .= '<form class="' . esc_attr( $def['class'] ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$out .= '<input type="hidden" name="action" value="wordworks_form">';
		$out .= '<input type="hidden" name="ww_type" value="' . esc_attr( $type ) . '">';
		$out .= '<input type="hidden" name="ww_t" value="' . esc_attr( (string) time() ) . '">';
		$out .= '<div class="ww-hp" aria-hidden="true"><label for="' . esc_attr( $uid ) . '-web">Web</label><input id="' . esc_attr( $uid ) . '-web" type="text" name="ww_web" tabindex="-1" autocomplete="off"></div>';
		foreach ( $def['fields'] as $name => $f ) {
			list( $label, $input_type, $placeholder ) = $f;
			$id   = $uid . '-' . $name;
			$out .= '<div class="field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
			if ( 'textarea' === $input_type ) {
				$out .= '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" placeholder="' . esc_attr( $placeholder ) . '" required></textarea>';
			} else {
				$out .= '<input id="' . esc_attr( $id ) . '" type="' . esc_attr( $input_type ) . '" name="' . esc_attr( $name ) . '" placeholder="' . esc_attr( $placeholder ) . '" required>';
			}
			$out .= '</div>';
		}
		$out .= '<div class="form-actions" style="text-align:' . esc_attr( $def['align'] ) . '"><button type="submit" class="btn btn-sm">Enviar</button></div>';
		$out .= '</form>';
		return $out;
	}
);

/**
 * Handle submissions.
 */
function wordworks_form_submit() {
	$types = wordworks_form_types();
	$type  = isset( $_POST['ww_type'] ) ? sanitize_key( wp_unslash( $_POST['ww_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$back  = wp_get_referer() ? wp_get_referer() : home_url( '/' );
	$back  = remove_query_arg( 'ww_form', $back );

	$spam = ! empty( $_POST['ww_web'] ) // phpcs:ignore WordPress.Security.NonceVerification
		|| ( time() - (int) ( $_POST['ww_t'] ?? 0 ) ) < 3; // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! isset( $types[ $type ] ) || $spam ) {
		wp_safe_redirect( add_query_arg( 'ww_form', $spam ? 'ok' : 'error', $back ) );
		exit;
	}

	$lines = array();
	$reply = '';
	foreach ( $types[ $type ]['fields'] as $name => $f ) {
		$raw = isset( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$val = 'textarea' === $f[1] ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw );
		if ( '' === $val ) {
			wp_safe_redirect( add_query_arg( 'ww_form', 'error', $back ) );
			exit;
		}
		if ( 'email' === $name ) {
			$val = sanitize_email( $val );
			if ( ! is_email( $val ) ) {
				wp_safe_redirect( add_query_arg( 'ww_form', 'error', $back ) );
				exit;
			}
			$reply = $val;
		}
		$lines[] = rtrim( $f[0], '*' ) . ': ' . $val;
	}

	$to      = get_option( 'wordworks_form_to', 'info@wordworks.es' );
	$headers = $reply ? array( 'Reply-To: ' . $reply ) : array();
	$sent    = wp_mail( $to, $types[ $type ]['subject'], implode( "\n\n", $lines ) . "\n\n--\n" . $back, $headers );

	wp_safe_redirect( add_query_arg( 'ww_form', $sent ? 'ok' : 'error', $back ) );
	exit;
}
add_action( 'admin_post_nopriv_wordworks_form', 'wordworks_form_submit' );
add_action( 'admin_post_wordworks_form', 'wordworks_form_submit' );
