<?php
/**
 * Pagina "Plugin → Plugin LU3G": elenco dei plugin LU3G con installa, aggiorna, attiva e disattiva.
 *
 * - Le azioni passano da admin-ajax (azione "lu3g_hub"), con nonce e controllo dei permessi di WordPress:
 *   installare e aggiornare richiedono install_plugins / update_plugins, attivare e disattivare activate_plugins.
 *   Su un multisite installare e aggiornare restano al super amministratore, come in WordPress; l'attivazione
 *   vale per il singolo sito.
 * - Installare e aggiornare usano l'installatore di WordPress (Plugin_Upgrader) con lo zip allegato alla release
 *   di GitHub. L'aggiornamento sostituisce la cartella (overwrite_package): il plugin resta attivo se lo era.
 * - Dopo ogni azione il server rimanda la scheda aggiornata, che il JS sostituisce senza ricaricare la pagina.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LU3G_Hub_Admin {

	const PAGE = 'lu3g-hub';

	private static $hook = '';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'wp_ajax_lu3g_hub', array( __CLASS__, 'ajax' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice_new' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( LU3G_HUB_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function page_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'plugins.php' ) );
	}

	public static function menu() {
		self::$hook = add_plugins_page( 'Plugin LU3G', 'Plugin LU3G', 'activate_plugins', self::PAGE, array( __CLASS__, 'render_page' ) );
		add_action( 'load-' . self::$hook, array( __CLASS__, 'load_page' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">Apri</a>' );
		return $links;
	}

	/** Prima di disegnare la pagina: "Aggiorna l'elenco" e file CSS/JS. */
	public static function load_page() {
		if ( isset( $_GET['lu3g_hub_refresh'] ) && check_admin_referer( 'lu3g_hub_refresh' ) ) {
			LU3G_Hub_Catalog::refresh();
			wp_safe_redirect( self::page_url( array( 'lu3g_hub_done' => 1 ) ) );
			exit;
		}
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function assets() {
		wp_enqueue_style( 'lu3g-hub', LU3G_HUB_URL . 'assets/admin.css', array(), LU3G_HUB_VERSION );
		wp_enqueue_script( 'lu3g-hub', LU3G_HUB_URL . 'assets/admin.js', array(), LU3G_HUB_VERSION, true );
		wp_localize_script(
			'lu3g-hub',
			'LU3G_HUB',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'lu3g_hub' ),
				'busy'  => array(
					'install'    => 'Installazione…',
					'update'     => 'Aggiornamento…',
					'activate'   => 'Attivazione…',
					'deactivate' => 'Disattivazione…',
				),
				'error' => 'Qualcosa è andato storto. Ricarica la pagina e riprova.',
			)
		);
	}

	/* ------------------------------------------------------------------ pagina */

	public static function render_page() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$data   = LU3G_Hub_Catalog::get();
		$items  = $data['items'];
		$unseen = LU3G_Hub_Catalog::unseen( $items );
		$active = 0;
		foreach ( $items as $item ) {
			$file = LU3G_Hub_Catalog::installed_file( $item );
			if ( $file && is_plugin_active( $file ) ) {
				$active++;
			}
		}
		?>
		<div class="wrap lu3g-hub">
			<h1 class="wp-heading-inline">Plugin LU3G</h1>
			<a class="page-title-action" href="<?php echo esc_url( wp_nonce_url( self::page_url( array( 'lu3g_hub_refresh' => 1 ) ), 'lu3g_hub_refresh' ) ); ?>">Aggiorna l'elenco</a>
			<hr class="wp-header-end">

			<p class="lu3g-hub__intro">
				<?php
				printf(
					/* translators: 1: numero di plugin, 2: numero di plugin attivi */
					esc_html__( '%1$d plugin disponibili, %2$d attivi su questo sito. I plugin nuovi pubblicati su GitHub compaiono qui da soli.', 'lu3g-hub' ),
					count( $items ),
					(int) $active
				);
				?>
				<span class="lu3g-hub__checked">
					<?php
					if ( $data['time'] ) {
						/* translators: %s: tempo trascorso, es. "5 minuti" */
						printf( esc_html__( 'Ultimo controllo: %s fa.', 'lu3g-hub' ), esc_html( human_time_diff( (int) $data['time'] ) ) );
					}
					?>
				</span>
			</p>

			<?php if ( '' !== $data['error'] ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html( $data['error'] ); ?> <?php echo $items ? 'Sotto c\'è l\'ultimo elenco letto.' : ''; ?></p></div>
			<?php endif; ?>

			<?php if ( ! empty( $_GET['lu3g_hub_done'] ) && '' === $data['error'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo un messaggio ?>
				<div class="notice notice-success inline is-dismissible"><p>Elenco aggiornato da GitHub.</p></div>
			<?php endif; ?>

			<?php if ( ! $items ) : ?>
				<p>Nessun plugin trovato. Controlla la connessione del server a GitHub e premi "Aggiorna l'elenco".</p>
			<?php else : ?>
				<div class="lu3g-hub__grid">
					<?php
					foreach ( $items as $item ) {
						echo self::card( $item, in_array( $item['repo'], $unseen, true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup già sanificato in card()
					}
					?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		// Aperta la pagina, i plugin nuovi sono stati visti: l'etichetta "Nuovo" e l'avviso spariscono dalla prossima volta.
		LU3G_Hub_Catalog::mark_seen( $items );
	}

	/** Scheda di un plugin. */
	public static function card( $item, $is_new = false ) {
		$file      = LU3G_Hub_Catalog::installed_file( $item );
		$installed = '' !== $file;
		$current   = '';
		$active    = false;
		$network   = false;

		if ( $installed ) {
			$plugins = get_plugins();
			$current = isset( $plugins[ $file ]['Version'] ) ? $plugins[ $file ]['Version'] : '';
			$active  = is_plugin_active( $file );
			$network = is_multisite() && is_plugin_active_for_network( $file );
		}
		$outdated = $installed && '' !== $current && version_compare( $item['version'], $current, '>' );

		if ( $active ) {
			$state = array( 'is-active', 'Attivo' );
		} elseif ( $installed ) {
			$state = array( 'is-inactive', 'Disattivato' );
		} else {
			$state = array( 'is-missing', 'Non installato' );
		}

		$buttons = array();
		if ( ! $installed && current_user_can( 'install_plugins' ) ) {
			$buttons[] = array( 'install', 'Installa', 'button-primary' );
		}
		if ( $outdated && current_user_can( 'update_plugins' ) ) {
			/* translators: %s: versione */
			$buttons[] = array( 'update', sprintf( 'Aggiorna alla %s', $item['version'] ), 'button-primary' );
		}
		if ( $installed && ! $active && current_user_can( 'activate_plugin', $file ) ) {
			$buttons[] = array( 'activate', 'Attiva', $outdated ? '' : 'button-primary' );
		}
		if ( $active && ! $network && current_user_can( 'deactivate_plugin', $file ) ) {
			$buttons[] = array( 'deactivate', 'Disattiva', '' );
		}

		ob_start();
		?>
		<div class="lu3g-hub__card <?php echo esc_attr( $state[0] ); ?>" data-repo="<?php echo esc_attr( $item['repo'] ); ?>">
			<div class="lu3g-hub__head">
				<h2 class="lu3g-hub__name"><?php echo esc_html( $item['name'] ); ?></h2>
				<?php if ( $is_new ) : ?>
					<span class="lu3g-hub__badge lu3g-hub__badge--new">Nuovo</span>
				<?php endif; ?>
				<span class="lu3g-hub__badge lu3g-hub__badge--state"><?php echo esc_html( $state[1] ); ?></span>
			</div>

			<?php if ( '' !== $item['description'] ) : ?>
				<p class="lu3g-hub__desc"><?php echo esc_html( $item['description'] ); ?></p>
			<?php endif; ?>

			<ul class="lu3g-hub__meta">
				<li>
					<?php if ( $installed ) : ?>
						Versione <strong><?php echo esc_html( $current ); ?></strong>
						<?php if ( $outdated ) : ?>
							<span class="lu3g-hub__update">· disponibile la <?php echo esc_html( $item['version'] ); ?></span>
						<?php endif; ?>
					<?php else : ?>
						Ultima versione <strong><?php echo esc_html( $item['version'] ); ?></strong>
					<?php endif; ?>
				</li>
				<?php if ( '' !== $item['requires'] ) : ?>
					<li>Richiede: <?php echo esc_html( $item['requires'] ); ?></li>
				<?php endif; ?>
				<?php if ( $network ) : ?>
					<li>Attivo su tutta la rete: si disattiva da Rete → Plugin.</li>
				<?php endif; ?>
			</ul>

			<div class="lu3g-hub__actions">
				<?php foreach ( $buttons as $b ) : ?>
					<button type="button" class="button <?php echo esc_attr( $b[2] ); ?>" data-op="<?php echo esc_attr( $b[0] ); ?>"><?php echo esc_html( $b[1] ); ?></button>
				<?php endforeach; ?>
				<a class="lu3g-hub__link" href="<?php echo esc_url( '' !== $item['release_url'] ? $item['release_url'] : $item['url'] ); ?>" target="_blank" rel="noopener">Novità</a>
				<a class="lu3g-hub__link" href="<?php echo esc_url( $item['url'] ); ?>" target="_blank" rel="noopener">GitHub</a>
			</div>
			<p class="lu3g-hub__msg" role="status" aria-live="polite"></p>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ------------------------------------------------------------------ azioni */

	public static function ajax() {
		check_ajax_referer( 'lu3g_hub', 'nonce' );

		$op   = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$repo = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';
		$data = LU3G_Hub_Catalog::stored();

		// Solo i repository del catalogo: niente pacchetti o percorsi arbitrari dalla richiesta.
		if ( ! isset( $data['items'][ $repo ] ) ) {
			wp_send_json_error( array( 'message' => 'Plugin non presente nell\'elenco: ricarica la pagina.' ) );
		}
		$item = $data['items'][ $repo ];

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$file = LU3G_Hub_Catalog::installed_file( $item );

		switch ( $op ) {
			case 'install':
				if ( ! current_user_can( 'install_plugins' ) ) {
					self::deny();
				}
				if ( $file ) {
					wp_send_json_error( array( 'message' => 'È già installato.' ) );
				}
				$result = self::run_installer( $item['package'], false );
				$done   = 'Installato. Ora puoi attivarlo.';
				break;

			case 'update':
				if ( ! current_user_can( 'update_plugins' ) ) {
					self::deny();
				}
				if ( ! $file ) {
					wp_send_json_error( array( 'message' => 'Non è installato.' ) );
				}
				// Lo zip contiene la cartella <slug>/: se il plugin è installato in una cartella con un altro nome,
				// l'installatore ne creerebbe una seconda copia invece di sostituirlo.
				if ( dirname( $file ) !== $item['slug'] ) {
					wp_send_json_error( array( 'message' => 'Il plugin è installato nella cartella "' . dirname( $file ) . '" invece di "' . $item['slug'] . '": aggiornalo dalla pagina Plugin.' ) );
				}
				$result = self::run_installer( $item['package'], true );
				$done   = 'Aggiornato alla ' . $item['version'] . '.';
				break;

			case 'activate':
				if ( ! $file ) {
					wp_send_json_error( array( 'message' => 'Non è installato.' ) );
				}
				if ( ! current_user_can( 'activate_plugin', $file ) ) {
					self::deny();
				}
				// Plugin richiesti (intestazione "Requires Plugins", es. elementor): messaggio chiaro invece di quello di WordPress.
				$missing = self::missing_requirements( $item['requires'] );
				if ( $missing ) {
					wp_send_json_error( array( 'message' => 'Prima installa e attiva: ' . implode( ', ', $missing ) . '.' ) );
				}
				$result = activate_plugin( $file );
				$done   = 'Attivato.';
				break;

			case 'deactivate':
				if ( ! $file ) {
					wp_send_json_error( array( 'message' => 'Non è installato.' ) );
				}
				if ( ! current_user_can( 'deactivate_plugin', $file ) ) {
					self::deny();
				}
				deactivate_plugins( $file );
				$result = true;
				$done   = 'Disattivato.';
				break;

			default:
				wp_send_json_error( array( 'message' => 'Azione sconosciuta.' ) );
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => self::error_text( $result ) ) );
		}

		wp_clean_plugins_cache( true );
		wp_send_json_success(
			array(
				'message' => $done,
				'html'    => self::card( $item ),
			)
		);
	}

	/**
	 * Plugin richiesti non attivi. "Requires Plugins" elenca le cartelle (slug di wordpress.org), separate da virgole.
	 *
	 * @return string[]
	 */
	private static function missing_requirements( $requires ) {
		$missing = array();
		$active  = array_map( 'dirname', (array) get_option( 'active_plugins', array() ) );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_map( 'dirname', array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) ) );
		}
		foreach ( array_filter( array_map( 'trim', explode( ',', (string) $requires ) ) ) as $slug ) {
			if ( ! in_array( $slug, $active, true ) ) {
				$missing[] = 'elementor' === $slug ? 'Elementor' : $slug;
			}
		}
		return $missing;
	}

	private static function deny() {
		wp_send_json_error( array( 'message' => 'Non hai i permessi per questa operazione.' ), 403 );
	}

	/**
	 * Installa (o sostituisce) un plugin dallo zip della release, con l'installatore di WordPress.
	 *
	 * @return true|WP_Error
	 */
	private static function run_installer( $package, $overwrite ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		// Se WordPress non può scrivere i file da solo (credenziali FTP richieste) si dice chiaramente.
		if ( 'direct' !== get_filesystem_method() && ! defined( 'FTP_HOST' ) ) {
			return new WP_Error( 'lu3g_hub_fs', 'WordPress non può scrivere nella cartella dei plugin senza credenziali FTP: installa lo zip da Plugin → Aggiungi nuovo.' );
		}

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $package, array( 'overwrite_package' => $overwrite ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( $skin->get_errors()->has_errors() ) {
			return $skin->get_errors();
		}
		if ( ! $result ) {
			global $wp_filesystem;
			if ( $wp_filesystem instanceof WP_Filesystem_Base && is_wp_error( $wp_filesystem->errors ) && $wp_filesystem->errors->has_errors() ) {
				return $wp_filesystem->errors;
			}
			return new WP_Error( 'lu3g_hub_install', 'Installazione non riuscita.' );
		}

		// I controlli degli aggiornamenti vanno rifatti con la versione nuova.
		delete_site_transient( 'update_plugins' );
		return true;
	}

	private static function error_text( WP_Error $error ) {
		$msgs = $error->get_error_messages();
		$text = wp_strip_all_tags( implode( ' ', array_filter( $msgs ) ) );
		return '' !== $text ? $text : 'Operazione non riuscita.';
	}

	/* ------------------------------------------------------------------ avviso dei plugin nuovi */

	/**
	 * In Bacheca e nella pagina Plugin: avviso quando su GitHub è comparso un plugin LU3G che qui non si è ancora visto.
	 * Usa solo il catalogo salvato (aggiornato dal cron giornaliero), senza chiamare GitHub a ogni pagina.
	 */
	public static function notice_new() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$data   = LU3G_Hub_Catalog::stored();
		$unseen = LU3G_Hub_Catalog::unseen( $data['items'] );
		if ( ! $unseen ) {
			return;
		}
		$names = array();
		foreach ( $unseen as $repo ) {
			$names[] = $data['items'][ $repo ]['name'];
		}
		printf(
			'<div class="notice notice-info"><p>%s <strong>%s</strong>. <a href="%s">Vai ai plugin LU3G</a></p></div>',
			esc_html( 1 === count( $names ) ? 'Nuovo plugin LU3G disponibile:' : 'Nuovi plugin LU3G disponibili:' ),
			esc_html( implode( ', ', $names ) ),
			esc_url( self::page_url() )
		);
	}
}
