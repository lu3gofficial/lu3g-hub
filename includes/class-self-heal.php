<?php
/**
 * LU3G Hub resta attivo dopo il proprio aggiornamento (1.4.1).
 *
 * WordPress, aggiornando un plugin dalla bacheca, prima lo disattiva e poi lo riattiva con un passaggio finale nel
 * browser (la cornice "activate-plugin" della pagina di aggiornamento). Se quel passaggio non avviene (pagina chiusa
 * prima, cornice bloccata dal server, aggiornamento lanciato da un altro strumento) o se la cartella del plugin
 * cambia nome, LU3G Hub restava disattivato: segnalato dall'utente a ogni aggiornamento.
 *
 * Qui: a fine aggiornamento (upgrader_process_complete, eseguito dal codice ancora in memoria) se LU3G Hub era attivo
 * all'inizio della richiesta e ora non lo è più, si rimette nell'elenco dei plugin attivi, anche se la cartella è
 * cambiata (si cerca per Update URI). Si aggiorna solo l'opzione dei plugin attivi, senza ricaricare il file del
 * plugin: niente classi dichiarate due volte; gli hook di attivazione non servono (il cron è già programmato).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LU3G_Hub_Self_Heal {

	/** @var bool|null Attivo all'inizio della richiesta: 'site', 'network' o false. */
	private static $was = null;

	public static function init() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$self      = plugin_basename( LU3G_HUB_FILE );
		self::$was = is_multisite() && is_plugin_active_for_network( $self ) ? 'network' : ( is_plugin_active( $self ) ? 'site' : false );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'heal' ), 99 );
	}

	/** File di LU3G Hub dopo l'aggiornamento (stessa cartella o, se è cambiata, quella con Update URI di lu3g-hub). */
	private static function current_file() {
		$self = plugin_basename( LU3G_HUB_FILE );
		if ( file_exists( WP_PLUGIN_DIR . '/' . $self ) ) {
			return $self;
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		wp_clean_plugins_cache( false );
		foreach ( get_plugins() as $file => $data ) {
			if ( ! empty( $data['UpdateURI'] ) && preg_match( '#github\.com/[^/]+/lu3g-hub/?$#i', trim( (string) $data['UpdateURI'] ) ) ) {
				return $file;
			}
		}
		return '';
	}

	public static function heal() {
		if ( ! self::$was ) {
			return;
		}
		$file = self::current_file();
		if ( '' === $file ) {
			return; // LU3G Hub non c'è più (eliminato): niente da fare
		}
		$old = plugin_basename( LU3G_HUB_FILE );
		if ( 'network' === self::$was ) {
			$list = (array) get_site_option( 'active_sitewide_plugins', array() );
			if ( ! isset( $list[ $file ] ) ) {
				unset( $list[ $old ] );
				$list[ $file ] = time();
				update_site_option( 'active_sitewide_plugins', $list );
			}
			return;
		}
		$list = (array) get_option( 'active_plugins', array() );
		if ( ! in_array( $file, $list, true ) ) {
			$list   = array_values( array_diff( $list, array( $old ) ) );
			$list[] = $file;
			sort( $list );
			update_option( 'active_plugins', $list );
		}
	}
}
