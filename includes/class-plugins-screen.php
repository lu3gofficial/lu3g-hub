<?php
/**
 * Pagina Plugin di WordPress senza i plugin LU3G (1.4.0).
 *
 * I plugin LU3G si gestiscono da LU3G Hub (installa, attiva, aggiorna, aggiornamenti automatici, impostazioni):
 * vederli due volte confondeva. Nella pagina Plugin (anche quella di rete) resta solo LU3G Hub, con un avviso che dice
 * quanti plugin LU3G sono nascosti e un link per mostrarli comunque (serve, per esempio, per eliminarne uno).
 * - Sono "plugin LU3G" i plugin installati che corrispondono a una voce del catalogo e quelli con
 *   Update URI github.com/lu3gofficial/… (anche se non sono più nel catalogo).
 * - Si nasconde solo l'elenco della pagina Plugin: aggiornamenti, aggiornamenti automatici e attivazioni continuano
 *   a funzionare come prima. Disattivando LU3G Hub i plugin LU3G ricompaiono subito.
 * - Il filtro "lu3g_hub_hide_plugins" (bool) permette di spegnere questa funzione.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LU3G_Hub_Plugins_Screen {

	const SHOW = 'lu3g_hub_show';

	public static function init() {
		add_filter( 'all_plugins', array( __CLASS__, 'filter' ) );
		add_action( 'pre_current_active_plugins', array( __CLASS__, 'notice' ) );
	}

	private static function enabled() {
		return (bool) apply_filters( 'lu3g_hub_hide_plugins', true );
	}

	/** Siamo nell'elenco della pagina Plugin e l'utente non ha chiesto di vederli? */
	private static function hiding() {
		global $pagenow;
		return self::enabled() && 'plugins.php' === $pagenow && empty( $_GET[ self::SHOW ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo visualizzazione
	}

	/** File dei plugin LU3G installati, tranne LU3G Hub. */
	public static function files( array $plugins ) {
		$hub   = plugin_basename( LU3G_HUB_FILE );
		$files = array();
		foreach ( LU3G_Hub_Catalog::stored()['items'] as $item ) {
			$f = LU3G_Hub_Catalog::installed_file( $item );
			if ( '' !== $f ) {
				$files[ $f ] = true;
			}
		}
		foreach ( $plugins as $file => $data ) {
			if ( ! empty( $data['UpdateURI'] ) && preg_match( '#github\.com/lu3gofficial/#i', (string) $data['UpdateURI'] ) ) {
				$files[ $file ] = true;
			}
		}
		unset( $files[ $hub ] );
		return array_keys( $files );
	}

	public static function filter( $plugins ) {
		if ( ! is_array( $plugins ) || ! self::hiding() ) {
			return $plugins;
		}
		foreach ( self::files( $plugins ) as $file ) {
			unset( $plugins[ $file ] );
		}
		return $plugins;
	}

	/** Riga sopra l'elenco: quanti sono nascosti, link a LU3G Hub e "Mostrali anche qui" / "Nascondili". */
	public static function notice() {
		if ( ! self::enabled() ) {
			return;
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$count = count( self::files( get_plugins() ) );
		if ( ! $count ) {
			return;
		}
		$hub  = LU3G_Hub_Admin::page_url();
		$base = is_network_admin() ? network_admin_url( 'plugins.php' ) : admin_url( 'plugins.php' );
		if ( self::hiding() ) {
			/* translators: %d: numero di plugin */
			$text = sprintf( _n( '%d plugin LU3G si gestisce da LU3G Hub e qui è nascosto.', '%d plugin LU3G si gestiscono da LU3G Hub e qui sono nascosti.', $count, 'lu3g-hub' ), $count );
			$link = '<a href="' . esc_url( add_query_arg( self::SHOW, '1', $base ) ) . '">' . esc_html__( 'Mostrali anche qui', 'lu3g-hub' ) . '</a>';
		} else {
			$text = __( 'Stai vedendo anche i plugin LU3G, che di solito si gestiscono da LU3G Hub.', 'lu3g-hub' );
			$link = '<a href="' . esc_url( $base ) . '">' . esc_html__( 'Nascondili', 'lu3g-hub' ) . '</a>';
		}
		echo '<div class="notice notice-info inline lu3g-hub-plugins-note"><p>' . esc_html( $text ) . ' <a href="' . esc_url( $hub ) . '"><strong>' . esc_html__( 'Apri LU3G Hub', 'lu3g-hub' ) . '</strong></a> · ' . $link . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $link costruito con esc_url/esc_html
	}

	/**
	 * Link "Impostazioni" e simili che il plugin aggiunge nella pagina Plugin, da mostrare sulla scheda di LU3G Hub
	 * (visto che lì il plugin non compare più). Esclusi attiva/disattiva/elimina e "Controlla aggiornamenti".
	 *
	 * @return array<int,array{href:string,text:string}>
	 */
	public static function settings_links( $file ) {
		if ( '' === $file || ! is_plugin_active( $file ) && ! is_plugin_active_for_network( $file ) ) {
			return array();
		}
		$plugins = function_exists( 'get_plugins' ) ? get_plugins() : array();
		$data    = $plugins[ $file ] ?? array();
		$hook    = is_network_admin() ? 'network_admin_plugin_action_links_' : 'plugin_action_links_';
		$links   = (array) apply_filters( $hook . $file, array(), $file, $data, 'all' );
		$out     = array();
		foreach ( $links as $html ) {
			if ( ! is_string( $html ) || ! preg_match( '#<a\s[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is', $html, $m ) ) {
				continue;
			}
			$text = trim( wp_strip_all_tags( $m[3] ) );
			if ( '' === $text || preg_match( '/^(attiva|disattiva|elimina|controlla|activate|deactivate|delete|check)/i', $text ) ) {
				continue;
			}
			$out[] = array(
				'href' => html_entity_decode( $m[2], ENT_QUOTES ),
				'text' => $text,
			);
		}
		return $out;
	}
}
