<?php
/**
 * Pagina "LU3G" (voce propria nel menu della bacheca, col logo LU3G): elenco dei plugin LU3G con
 * installa, attiva/disattiva, aggiorna e aggiornamenti automatici.
 *
 * - Le azioni passano da admin-ajax (azione "lu3g_hub"), con nonce e controllo dei permessi di WordPress:
 *   installare e aggiornare richiedono install_plugins / update_plugins, attivare e disattivare activate_plugins,
 *   gli aggiornamenti automatici update_plugins (su un multisite: solo il super amministratore, valgono per la rete).
 * - Installare e aggiornare usano l'installatore di WordPress (Plugin_Upgrader) con lo zip della release di GitHub.
 *   L'aggiornamento sostituisce la cartella (overwrite_package): il plugin resta attivo se lo era.
 * - Dopo ogni azione il server rimanda le schede aggiornate e i contatori; il JS li sostituisce senza ricaricare.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LU3G_Hub_Admin {

	const PAGE = 'lu3g-hub';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'wp_ajax_lu3g_hub', array( __CLASS__, 'ajax' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice_new' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( LU3G_HUB_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function page_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Voce "LU3G" subito sotto la Bacheca, con il logo LU3G come icona. L'icona è un SVG con fill="black":
	 * WordPress lo ricolora con i colori dello schema della bacheca (anche nello stato attivo e al passaggio del mouse).
	 */
	public static function menu() {
		$svg  = (string) file_get_contents( dirname( LU3G_HUB_FILE ) . '/assets/lu3g-icon.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- file locale del plugin
		$icon = 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- formato richiesto dalle icone del menu
		$hook = add_menu_page( 'Plugin LU3G', 'LU3G', 'activate_plugins', self::PAGE, array( __CLASS__, 'render_page' ), $icon, 3 );
		add_action( 'load-' . $hook, array( __CLASS__, 'load_page' ) );

		// La pagina stava in Plugin → Plugin LU3G fino alla 1.0.0: chi ha il vecchio indirizzo arriva qui.
		global $pagenow;
		if ( 'plugins.php' === $pagenow && isset( $_GET['page'] ) && self::PAGE === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo un rinvio
			wp_safe_redirect( self::page_url() );
			exit;
		}
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
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
	}

	public static function body_class( $classes ) {
		return $classes . ' lu3g-hub-page';
	}

	public static function assets() {
		wp_enqueue_style( 'lu3g-hub', LU3G_HUB_URL . 'assets/admin.css', array(), LU3G_HUB_VERSION );
		wp_add_inline_style( 'lu3g-hub', '@font-face{font-family:"LU3G Manrope";src:url(' . esc_url( LU3G_HUB_URL . 'assets/fonts/manrope-latin-wght.woff2' ) . ') format("woff2");font-weight:200 800;font-display:swap}' );
		wp_enqueue_script( 'lu3g-hub', LU3G_HUB_URL . 'assets/admin.js', array(), LU3G_HUB_VERSION, true );
		wp_localize_script(
			'lu3g-hub',
			'LU3G_HUB',
			array(
				'ajax'    => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'lu3g_hub' ),
				'busy'    => array(
					'install'    => 'Installazione…',
					'update'     => 'Aggiornamento…',
					'activate'   => 'Attivazione…',
					'deactivate' => 'Disattivazione…',
					'auto_on'    => 'Attivo gli aggiornamenti automatici…',
					'auto_off'   => 'Spengo gli aggiornamenti automatici…',
				),
				'error'   => 'Qualcosa è andato storto. Ricarica la pagina e riprova.',
				'allDone' => 'Tutti i plugin sono aggiornati.',
				'empty'   => 'Nessun plugin corrisponde.',
			)
		);
	}

	/* ------------------------------------------------------------------ dati */

	/** Stato di ogni voce del catalogo su questo sito. */
	private static function state( $item ) {
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
		return array(
			'file'      => $file,
			'installed' => $installed,
			'current'   => $current,
			'active'    => $active,
			'network'   => $network,
			'outdated'  => $installed && '' !== $current && version_compare( $item['version'], $current, '>' ),
			'auto'      => $installed && LU3G_Hub_Updates::is_on( $file ),
		);
	}

	/** Contatori dell'intestazione. */
	private static function stats( $items ) {
		$s = array(
			'total'   => count( $items ),
			'active'  => 0,
			'updates' => 0,
			'auto'    => 0,
		);
		foreach ( $items as $item ) {
			$st             = self::state( $item );
			$s['active']   += $st['active'] ? 1 : 0;
			$s['updates']  += $st['outdated'] ? 1 : 0;
			$s['auto']     += $st['auto'] ? 1 : 0;
		}
		return $s;
	}

	/* ------------------------------------------------------------------ pagina */

	public static function render_page() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$data   = LU3G_Hub_Catalog::get();
		$items  = $data['items'];
		$unseen = LU3G_Hub_Catalog::unseen( $items );
		$stats  = self::stats( $items );
		$logo   = (string) file_get_contents( dirname( LU3G_HUB_FILE ) . '/assets/lu3g-logo.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- file locale del plugin
		$manage = LU3G_Hub_Updates::can_manage();
		?>
		<div class="wrap lu3g-hub">
			<h1 class="screen-reader-text">Plugin LU3G</h1>
			<hr class="wp-header-end">

			<header class="lu3g-hub__hero">
				<div class="lu3g-hub__glow" aria-hidden="true"></div>
				<div class="lu3g-hub__brand">
					<span class="lu3g-hub__logo" aria-hidden="true"><?php echo $logo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG del plugin ?></span>
					<div>
						<p class="lu3g-hub__eyebrow">LU3G Agenzia Web</p>
						<h2 class="lu3g-hub__title">I nostri plugin</h2>
						<p class="lu3g-hub__lead">Installa, attiva e aggiorna da qui. I plugin nuovi pubblicati su GitHub compaiono da soli.</p>
					</div>
				</div>
				<a class="lu3g-hub__refresh" href="<?php echo esc_url( wp_nonce_url( self::page_url( array( 'lu3g_hub_refresh' => 1 ) ), 'lu3g_hub_refresh' ) ); ?>">
					<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11a8 8 0 0 0-14.9-3.9M4 4v4h4M4 13a8 8 0 0 0 14.9 3.9M20 20v-4h-4"/></svg>
					<span>Aggiorna l'elenco</span>
				</a>
				<dl class="lu3g-hub__stats">
					<div><dt>Disponibili</dt><dd data-stat="total"><?php echo (int) $stats['total']; ?></dd></div>
					<div><dt>Attivi</dt><dd data-stat="active"><?php echo (int) $stats['active']; ?></dd></div>
					<div><dt>Da aggiornare</dt><dd data-stat="updates"><?php echo (int) $stats['updates']; ?></dd></div>
					<div><dt>Aggiornamenti automatici</dt><dd data-stat="auto"><?php echo (int) $stats['auto']; ?></dd></div>
				</dl>
				<p class="lu3g-hub__checked">
					<?php
					if ( $data['time'] ) {
						/* translators: %s: tempo trascorso */
						printf( esc_html__( 'Ultimo controllo su GitHub: %s fa', 'lu3g-hub' ), esc_html( human_time_diff( (int) $data['time'] ) ) );
					}
					?>
				</p>
			</header>

			<?php if ( '' !== $data['error'] ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html( $data['error'] ); ?> <?php echo $items ? 'Sotto c\'è l\'ultimo elenco letto.' : ''; ?></p></div>
			<?php endif; ?>
			<?php if ( ! empty( $_GET['lu3g_hub_done'] ) && '' === $data['error'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo un messaggio ?>
				<div class="notice notice-success inline is-dismissible"><p>Elenco aggiornato da GitHub.</p></div>
			<?php endif; ?>

			<?php if ( ! $items ) : ?>
				<p class="lu3g-hub__empty">Nessun plugin trovato. Controlla la connessione del server a GitHub e premi "Aggiorna l'elenco".</p>
			<?php else : ?>
				<div class="lu3g-hub__toolbar">
					<div class="lu3g-hub__filters" role="tablist" aria-label="Filtra i plugin">
						<button type="button" role="tab" class="is-current" aria-selected="true" data-filter="all">Tutti</button>
						<button type="button" role="tab" aria-selected="false" data-filter="active">Attivi</button>
						<button type="button" role="tab" aria-selected="false" data-filter="inactive">Disattivati</button>
						<button type="button" role="tab" aria-selected="false" data-filter="missing">Da installare</button>
						<button type="button" role="tab" aria-selected="false" data-filter="update">Da aggiornare</button>
					</div>
					<label class="lu3g-hub__search">
						<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
						<span class="screen-reader-text">Cerca un plugin</span>
						<input type="search" placeholder="Cerca un plugin…" data-search>
					</label>
					<button type="button" class="lu3g-hub__btn lu3g-hub__btn--primary" data-update-all <?php echo $stats['updates'] && current_user_can( 'update_plugins' ) ? '' : 'hidden'; ?>>
						<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v12M6 10l6 6 6-6M5 20h14"/></svg>
						Aggiorna tutti
					</button>
				</div>

				<?php if ( $manage ) : ?>
					<div class="lu3g-hub__autobar">
						<div>
							<strong>Aggiornamenti automatici</strong>
							<span>WordPress installa da solo le versioni nuove, due volte al giorno, e ti avvisa per email.</span>
						</div>
						<div class="lu3g-hub__autobar-switches">
							<?php echo self::switch_html( 'auto_all', 'Tutti i plugin installati', $stats['auto'] > 0 && self::all_auto( $items ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo self::switch_html( 'auto_new', 'Anche su quelli che installi da qui', LU3G_Hub_Updates::auto_new() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo self::switch_html( 'auto_self', 'LU3G Hub', LU3G_Hub_Updates::is_on( plugin_basename( LU3G_HUB_FILE ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
					</div>
				<?php elseif ( current_user_can( 'update_plugins' ) ) : ?>
					<p class="lu3g-hub__note">Gli aggiornamenti automatici sono disattivati su questo sito (costante o filtro di WordPress).</p>
				<?php endif; ?>

				<div class="lu3g-hub__grid" data-grid>
					<?php
					$i = 0;
					foreach ( $items as $item ) {
						echo self::card( $item, in_array( $item['repo'], $unseen, true ), $i++ ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup già sanificato in card()
					}
					?>
				</div>
				<p class="lu3g-hub__empty" data-empty hidden>Nessun plugin corrisponde.</p>
			<?php endif; ?>

			<footer class="lu3g-hub__foot">LU3G Hub <?php echo esc_html( LU3G_HUB_VERSION ); ?> · <a href="https://github.com/lu3gofficial" target="_blank" rel="noopener">github.com/lu3gofficial</a></footer>
			<div class="lu3g-hub__toasts" aria-live="polite" data-toasts></div>
		</div>
		<?php
		// Aperta la pagina, i plugin nuovi sono stati visti: l'etichetta "Nuovo" e l'avviso spariscono dalla prossima volta.
		LU3G_Hub_Catalog::mark_seen( $items );
	}

	private static function all_auto( $items ) {
		foreach ( $items as $item ) {
			$st = self::state( $item );
			if ( $st['installed'] && ! $st['auto'] ) {
				return false;
			}
		}
		return true;
	}

	/** Interruttore (pulsante con role="switch"): data-op è l'operazione, aria-checked lo stato. */
	private static function switch_html( $op, $label, $on, $disabled = false, $hint = '' ) {
		return sprintf(
			'<button type="button" class="lu3g-switch" role="switch" aria-checked="%1$s" data-switch="%2$s"%3$s%4$s><span class="lu3g-switch__track" aria-hidden="true"><span class="lu3g-switch__thumb"></span></span><span class="lu3g-switch__label">%5$s</span></button>',
			$on ? 'true' : 'false',
			esc_attr( $op ),
			$disabled ? ' disabled' : '',
			'' !== $hint ? ' title="' . esc_attr( $hint ) . '"' : '',
			esc_html( $label )
		);
	}

	/** Iniziali per il segno colorato della scheda: "LU3G Sponsor Carousel" → "SC", "Menu LU3G" → "M". */
	private static function initials( $name ) {
		$words = array_values( array_filter( preg_split( '/\s+/', $name ), function ( $w ) {
			return 'LU3G' !== strtoupper( $w );
		} ) );
		$out = '';
		foreach ( array_slice( $words, 0, 2 ) as $w ) {
			$out .= function_exists( 'mb_substr' ) ? mb_substr( $w, 0, 1 ) : substr( $w, 0, 1 );
		}
		return strtoupper( '' !== $out ? $out : 'L' );
	}

	/** Scheda di un plugin. $index serve solo al ritardo dell'animazione d'ingresso. */
	public static function card( $item, $is_new = false, $index = 0 ) {
		$st = self::state( $item );

		if ( $st['active'] ) {
			$state = array( 'active', 'Attivo' );
		} elseif ( $st['installed'] ) {
			$state = array( 'inactive', 'Disattivato' );
		} else {
			$state = array( 'missing', 'Da installare' );
		}
		$hue = abs( crc32( $item['repo'] ) ) % 360; // colore del segno, sempre lo stesso per lo stesso plugin

		$can_toggle = $st['installed'] && ( $st['active'] ? current_user_can( 'deactivate_plugin', $st['file'] ) : current_user_can( 'activate_plugin', $st['file'] ) );
		$hint       = $st['network'] ? 'Attivo su tutta la rete: si disattiva da Rete → Plugin' : '';

		ob_start();
		?>
		<article class="lu3g-hub__card is-<?php echo esc_attr( $state[0] ); ?><?php echo $st['outdated'] ? ' has-update' : ''; ?>"
			data-repo="<?php echo esc_attr( $item['repo'] ); ?>"
			data-state="<?php echo esc_attr( $state[0] ); ?>"
			data-update="<?php echo $st['outdated'] ? '1' : '0'; ?>"
			data-text="<?php echo esc_attr( strtolower( $item['name'] . ' ' . $item['description'] ) ); ?>"
			style="--i:<?php echo (int) $index; ?>;--hue:<?php echo (int) $hue; ?>">
			<div class="lu3g-hub__progress" aria-hidden="true"></div>

			<div class="lu3g-hub__head">
				<span class="lu3g-hub__mark" aria-hidden="true"><?php echo esc_html( self::initials( $item['name'] ) ); ?></span>
				<div class="lu3g-hub__heading">
					<h3 class="lu3g-hub__name"><?php echo esc_html( $item['name'] ); ?></h3>
					<p class="lu3g-hub__version">
						<?php if ( $st['installed'] ) : ?>
							v<?php echo esc_html( $st['current'] ); ?>
							<?php if ( $st['outdated'] ) : ?>
								<span class="lu3g-hub__arrow">→</span> <strong>v<?php echo esc_html( $item['version'] ); ?></strong>
							<?php endif; ?>
						<?php else : ?>
							v<?php echo esc_html( $item['version'] ); ?>
						<?php endif; ?>
					</p>
				</div>
				<div class="lu3g-hub__badges">
					<?php if ( $is_new ) : ?>
						<span class="lu3g-hub__badge lu3g-hub__badge--new">Nuovo</span>
					<?php endif; ?>
					<span class="lu3g-hub__badge lu3g-hub__badge--state"><i aria-hidden="true"></i><?php echo esc_html( $state[1] ); ?></span>
				</div>
			</div>

			<?php if ( '' !== $item['description'] ) : ?>
				<p class="lu3g-hub__desc"><?php echo esc_html( $item['description'] ); ?></p>
			<?php endif; ?>

			<div class="lu3g-hub__chips">
				<?php if ( '' !== $item['requires'] ) : ?>
					<span class="lu3g-hub__chip">Richiede <?php echo esc_html( ucfirst( $item['requires'] ) ); ?></span>
				<?php endif; ?>
				<a class="lu3g-hub__chip lu3g-hub__chip--link" href="<?php echo esc_url( '' !== $item['release_url'] ? $item['release_url'] : $item['url'] ); ?>" target="_blank" rel="noopener">Novità ↗</a>
				<a class="lu3g-hub__chip lu3g-hub__chip--link" href="<?php echo esc_url( $item['url'] ); ?>" target="_blank" rel="noopener">GitHub ↗</a>
			</div>

			<div class="lu3g-hub__foot-card">
				<?php if ( ! $st['installed'] ) : ?>
					<?php if ( current_user_can( 'install_plugins' ) ) : ?>
						<button type="button" class="lu3g-hub__btn lu3g-hub__btn--primary" data-op="install">
							<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v12M6 10l6 6 6-6M5 20h14"/></svg>
							Installa
						</button>
					<?php else : ?>
						<span class="lu3g-hub__note">Solo un amministratore può installarlo.</span>
					<?php endif; ?>
				<?php else : ?>
					<div class="lu3g-hub__switches">
						<?php echo self::switch_html( $st['active'] ? 'deactivate' : 'activate', 'Attivo', $st['active'], ! $can_toggle || $st['network'], $hint ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php if ( LU3G_Hub_Updates::can_manage() ) : ?>
							<?php echo self::switch_html( $st['auto'] ? 'auto_off' : 'auto_on', 'Aggiornamento automatico', $st['auto'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endif; ?>
					</div>
					<?php if ( $st['outdated'] && current_user_can( 'update_plugins' ) ) : ?>
						<button type="button" class="lu3g-hub__btn lu3g-hub__btn--primary" data-op="update">
							<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11a8 8 0 0 0-14.9-3.9M4 4v4h4M4 13a8 8 0 0 0 14.9 3.9M20 20v-4h-4"/></svg>
							Aggiorna
						</button>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</article>
		<?php
		return ob_get_clean();
	}

	/* ------------------------------------------------------------------ azioni */

	public static function ajax() {
		check_ajax_referer( 'lu3g_hub', 'nonce' );
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$op    = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$repo  = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';
		$on    = ! empty( $_POST['on'] ) && 'false' !== $_POST['on'];
		$data  = LU3G_Hub_Catalog::stored();
		$items = $data['items'];

		// Interruttori generali: nessun plugin preciso.
		if ( in_array( $op, array( 'auto_all', 'auto_new', 'auto_self' ), true ) ) {
			if ( ! LU3G_Hub_Updates::can_manage() ) {
				self::deny();
			}
			if ( 'auto_all' === $op ) {
				$files = array();
				foreach ( $items as $item ) {
					$file = LU3G_Hub_Catalog::installed_file( $item );
					if ( $file ) {
						$files[] = $file;
					}
				}
				LU3G_Hub_Updates::set( $files, $on );
				$msg = $on ? 'Aggiornamenti automatici attivi su tutti i plugin installati.' : 'Aggiornamenti automatici spenti su tutti i plugin.';
			} elseif ( 'auto_new' === $op ) {
				update_site_option( LU3G_Hub_Updates::AUTO_NEW, $on );
				$msg = $on ? 'I plugin che installi da qui si aggiorneranno da soli.' : 'I plugin che installi da qui non si aggiorneranno da soli.';
			} else {
				LU3G_Hub_Updates::set( array( plugin_basename( LU3G_HUB_FILE ) ), $on );
				$msg = $on ? 'LU3G Hub si aggiornerà da solo.' : 'Aggiornamenti automatici di LU3G Hub spenti.';
			}
			self::respond( $msg, $items );
		}

		// Solo i repository del catalogo: niente pacchetti o percorsi arbitrari dalla richiesta.
		if ( ! isset( $items[ $repo ] ) ) {
			wp_send_json_error( array( 'message' => 'Plugin non presente nell\'elenco: ricarica la pagina.' ) );
		}
		$item = $items[ $repo ];
		$file = LU3G_Hub_Catalog::installed_file( $item );
		$name = $item['name'];

		switch ( $op ) {
			case 'install':
				if ( ! current_user_can( 'install_plugins' ) ) {
					self::deny();
				}
				if ( $file ) {
					wp_send_json_error( array( 'message' => $name . ' è già installato.' ) );
				}
				$result = self::run_installer( $item['package'], false );
				$done   = $name . ' installato. Ora puoi attivarlo.';
				if ( true === $result && LU3G_Hub_Updates::auto_new() && LU3G_Hub_Updates::can_manage() ) {
					wp_clean_plugins_cache( true );
					$new_file = LU3G_Hub_Catalog::installed_file( $item );
					if ( $new_file ) {
						LU3G_Hub_Updates::set( array( $new_file ), true );
						$done .= ' Aggiornamenti automatici attivi.';
					}
				}
				break;

			case 'update':
				if ( ! current_user_can( 'update_plugins' ) ) {
					self::deny();
				}
				if ( ! $file ) {
					wp_send_json_error( array( 'message' => $name . ' non è installato.' ) );
				}
				// Lo zip contiene la cartella <slug>/: se il plugin è installato in una cartella con un altro nome,
				// l'installatore ne creerebbe una seconda copia invece di sostituirlo.
				if ( dirname( $file ) !== $item['slug'] ) {
					wp_send_json_error( array( 'message' => $name . ' è installato nella cartella "' . dirname( $file ) . '" invece di "' . $item['slug'] . '": aggiornalo dalla pagina Plugin.' ) );
				}
				$result = self::run_installer( $item['package'], true );
				$done   = $name . ' aggiornato alla ' . $item['version'] . '.';
				break;

			case 'activate':
				if ( ! $file ) {
					wp_send_json_error( array( 'message' => $name . ' non è installato.' ) );
				}
				if ( ! current_user_can( 'activate_plugin', $file ) ) {
					self::deny();
				}
				// Plugin richiesti (intestazione "Requires Plugins", es. elementor): messaggio chiaro invece di quello di WordPress.
				$missing = self::missing_requirements( $item['requires'] );
				if ( $missing ) {
					wp_send_json_error( array( 'message' => 'Per attivare ' . $name . ' prima installa e attiva ' . implode( ', ', $missing ) . '.' ) );
				}
				$result = activate_plugin( $file );
				$done   = $name . ' attivato.';
				break;

			case 'deactivate':
				if ( ! $file ) {
					wp_send_json_error( array( 'message' => $name . ' non è installato.' ) );
				}
				if ( ! current_user_can( 'deactivate_plugin', $file ) ) {
					self::deny();
				}
				deactivate_plugins( $file );
				$result = true;
				$done   = $name . ' disattivato.';
				break;

			case 'auto_on':
			case 'auto_off':
				if ( ! $file ) {
					wp_send_json_error( array( 'message' => $name . ' non è installato.' ) );
				}
				if ( ! LU3G_Hub_Updates::can_manage() ) {
					self::deny();
				}
				LU3G_Hub_Updates::set( array( $file ), 'auto_on' === $op );
				$result = true;
				$done   = 'auto_on' === $op ? $name . ': aggiornamenti automatici attivi.' : $name . ': aggiornamenti automatici spenti.';
				break;

			default:
				wp_send_json_error( array( 'message' => 'Azione sconosciuta.' ) );
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => self::error_text( $result ) ) );
		}
		wp_clean_plugins_cache( true );
		self::respond( $done, $items );
	}

	/** Risposta comune: messaggio, tutte le schede aggiornate, contatori e stato degli interruttori generali. */
	private static function respond( $message, $items ) {
		$cards = array();
		foreach ( $items as $repo => $item ) {
			$cards[ $repo ] = self::card( $item );
		}
		$stats = self::stats( $items );
		wp_send_json_success(
			array(
				'message'  => $message,
				'cards'    => $cards,
				'stats'    => $stats,
				'switches' => array(
					'auto_all'  => $stats['auto'] > 0 && self::all_auto( $items ),
					'auto_new'  => LU3G_Hub_Updates::auto_new(),
					'auto_self' => LU3G_Hub_Updates::is_on( plugin_basename( LU3G_HUB_FILE ) ),
				),
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
		$text = wp_strip_all_tags( implode( ' ', array_filter( $error->get_error_messages() ) ) );
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
