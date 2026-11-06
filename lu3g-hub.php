<?php
/**
 * Plugin Name:       LU3G Hub
 * Plugin URI:        https://github.com/lu3gofficial/lu3g-hub
 * Description:       Raccoglie tutti i plugin LU3G: li installa dalle release GitHub, li attiva, li disattiva e li aggiorna da un'unica pagina. I plugin nuovi compaiono da soli.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            LU3G Agenzia Web
 * Author URI:        https://lu3g.it
 * License:           GPL-2.0-or-later
 * Text Domain:       lu3g-hub
 * Update URI:        https://github.com/lu3gofficial/lu3g-hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LU3G_HUB_VERSION', '1.1.0' );
define( 'LU3G_HUB_FILE', __FILE__ );
define( 'LU3G_HUB_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/includes/class-updater.php';
require_once __DIR__ . '/includes/class-catalog.php';
require_once __DIR__ . '/includes/class-updates.php';
require_once __DIR__ . '/includes/class-admin.php';

// Aggiornamenti di LU3G Hub stesso dalle release di GitHub.
LU3G_Hub_Updater::init( __FILE__ );

// Aggiornamenti automatici: anche nel cron di WordPress, che li esegue.
LU3G_Hub_Updates::init();

// Il catalogo serve solo in bacheca e al controllo giornaliero.
if ( is_admin() || wp_doing_cron() ) {
	LU3G_Hub_Admin::init();
}

// Controllo giornaliero del catalogo: così l'avviso "nuovo plugin disponibile" compare anche senza aprire la pagina.
add_action( LU3G_Hub_Catalog::CRON, array( 'LU3G_Hub_Catalog', 'refresh' ) );

register_activation_hook(
	__FILE__,
	function () {
		if ( ! wp_next_scheduled( LU3G_Hub_Catalog::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', LU3G_Hub_Catalog::CRON );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( LU3G_Hub_Catalog::CRON );
	}
);
