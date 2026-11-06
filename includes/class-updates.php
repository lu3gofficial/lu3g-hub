<?php
/**
 * Aggiornamenti automatici dei plugin LU3G.
 *
 * - Usa gli aggiornamenti automatici di WordPress (opzione "auto_update_plugins", la stessa dei link
 *   "Attiva aggiornamenti automatici" della pagina Plugin): accesi da qui, risultano accesi anche lì e viceversa.
 *   WordPress li esegue col suo cron, due volte al giorno, e manda la sua email di riepilogo.
 * - Perché WordPress possa aggiornare serve la voce del plugin nell'elenco degli aggiornamenti disponibili.
 *   Ogni plugin LU3G la mette già col suo sistema di aggiornamento; qui la si aggiunge dal catalogo di GitHub
 *   quando manca (per esempio una copia vecchia di Login Brand 1.6.0, il cui sistema non funziona più dopo il
 *   trasferimento a lu3gofficial). Quella del plugin, se c'è, ha la precedenza.
 * - "Anche per i plugin installati da qui": i plugin installati da LU3G Hub ricevono subito gli aggiornamenti automatici.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LU3G_Hub_Updates {

	const AUTO_NEW = 'lu3g_hub_auto_new';   // aggiornamenti automatici accesi sui plugin installati da qui

	public static function init() {
		add_filter( 'site_transient_update_plugins', array( __CLASS__, 'inject' ) );
	}

	/** Gli aggiornamenti automatici dei plugin sono possibili su questo sito? (costanti e filtri di WordPress) */
	public static function available() {
		return function_exists( 'wp_is_auto_update_enabled_for_type' ) && wp_is_auto_update_enabled_for_type( 'plugin' );
	}

	/** L'utente può cambiarli? Su un multisite valgono per tutta la rete: solo il super amministratore. */
	public static function can_manage() {
		return self::available() && current_user_can( 'update_plugins' );
	}

	public static function is_on( $file ) {
		return '' !== $file && in_array( $file, (array) get_site_option( 'auto_update_plugins', array() ), true );
	}

	/**
	 * Accende o spegne gli aggiornamenti automatici di uno o più plugin.
	 *
	 * @param string[] $files File dei plugin (cartella/file.php).
	 */
	public static function set( $files, $on ) {
		$list = (array) get_site_option( 'auto_update_plugins', array() );
		$list = $on ? array_merge( $list, $files ) : array_diff( $list, $files );
		update_site_option( 'auto_update_plugins', array_values( array_unique( $list ) ) );
	}

	public static function auto_new() {
		return (bool) get_site_option( self::AUTO_NEW, false );
	}

	/**
	 * Aggiunge all'elenco degli aggiornamenti i plugin LU3G con una versione nuova nel catalogo, se manca la voce.
	 * Lavora solo sul catalogo salvato: nessuna richiesta a GitHub.
	 */
	public static function inject( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$data = LU3G_Hub_Catalog::stored();
		if ( empty( $data['items'] ) ) {
			return $transient;
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		// LU3G Hub stesso: il suo sistema di aggiornamento aggiunge la voce solo quando c'è una versione nuova.
		$self = plugin_basename( LU3G_HUB_FILE );
		if ( ! isset( $transient->response[ $self ] ) && ! isset( $transient->no_update[ $self ] ) ) {
			if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
				$transient->no_update = array();
			}
			$transient->no_update[ $self ] = (object) array(
				'id'          => 'https://github.com/' . LU3G_Hub_Updater::REPO,
				'slug'        => dirname( $self ),
				'plugin'      => $self,
				'new_version' => LU3G_HUB_VERSION,
				'url'         => 'https://github.com/' . LU3G_Hub_Updater::REPO,
				'package'     => '',
			);
		}

		foreach ( $data['items'] as $item ) {
			$file = LU3G_Hub_Catalog::installed_file( $item );
			if ( '' === $file || isset( $transient->response[ $file ] ) || dirname( $file ) !== $item['slug'] ) {
				continue;
			}
			$current = isset( $plugins[ $file ]['Version'] ) ? $plugins[ $file ]['Version'] : '';
			if ( '' === $current ) {
				continue;
			}
			if ( ! version_compare( $item['version'], $current, '>' ) ) {
				// Già aggiornato: la voce "nessun aggiornamento" dice a WordPress che il plugin si aggiorna da qui,
				// così nella pagina Plugin compare il link degli aggiornamenti automatici invece di un trattino.
				if ( ! isset( $transient->no_update[ $file ] ) ) {
					if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
						$transient->no_update = array();
					}
					$transient->no_update[ $file ] = (object) array(
						'id'          => $item['url'],
						'slug'        => $item['slug'],
						'plugin'      => $file,
						'new_version' => $current,
						'url'         => $item['url'],
						'package'     => '',
					);
				}
				continue;
			}
			$transient->response[ $file ] = (object) array(
				'id'          => $item['url'],
				'slug'        => $item['slug'],
				'plugin'      => $file,
				'new_version' => $item['version'],
				'url'         => $item['url'],
				'package'     => $item['package'],
			);
			if ( isset( $transient->no_update[ $file ] ) ) {
				unset( $transient->no_update[ $file ] );
			}
		}
		return $transient;
	}
}
