<?php
/**
 * Catalogo dei plugin LU3G, letto da GitHub.
 *
 * Un repository dell'organizzazione lu3gofficial entra nel catalogo se:
 * - è pubblico (i siti scaricano lo zip senza token);
 * - ha la proprietà personalizzata dell'organizzazione "Plugin_Wordpress" = yes;
 * - la sua ultima release ha uno zip allegato (il pacchetto che WordPress installa).
 * Un plugin nuovo pubblicato così compare da solo al controllo successivo: non c'è nessun elenco da aggiornare a mano.
 *
 * Nota: la ricerca di GitHub per proprietà (props.Plugin_Wordpress:yes) senza login ignora il filtro, quindi la
 * proprietà si legge repository per repository (questa sì è pubblica) e si tiene in memoria per una settimana.
 *
 * Richieste all'API: senza token GitHub ne concede 60 l'ora per indirizzo IP. Fino alla 1.2.0 ogni controllo costava
 * 1 richiesta per l'elenco più 1 per ogni plugin: con ~15 plugin, 3-4 clic su "Aggiorna l'elenco" bloccavano il sito
 * per un'ora e il catalogo restava fermo alle versioni vecchie (successo con LU3G Manutenzione 1.0.1 → 1.0.2).
 * Dalla 1.3.0 l'ultima release si legge dal rinvio di github.com/<org>/<repo>/releases/latest (porta al tag) e lo zip
 * da github.com/<org>/<repo>/releases/download/<tag>/<slug>.zip: pagine pubbliche, fuori dal limite dell'API.
 * L'API resta solo per l'elenco dei repository (1 richiesta), le proprietà (una volta a settimana per repository) e,
 * una volta sola, per trovare il nome dello zip di un plugin nuovo se non coincide col nome del repository.
 * Se l'elenco dei repository non arriva (limite raggiunto), le versioni dei plugin già noti si aggiornano lo stesso.
 * Il catalogo si rilegge ogni 12 ore, una volta al giorno dal cron o col pulsante "Aggiorna l'elenco".
 * Nome e descrizione del plugin arrivano dall'intestazione del file principale su raw.githubusercontent.com,
 * che non conta nel limite. Facoltativo: define( 'LU3G_GITHUB_TOKEN', 'github_pat_...' ) in wp-config.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LU3G_Hub_Catalog {

	const ORG       = 'lu3gofficial';
	const PROPERTY  = 'Plugin_Wordpress';
	const SELF_REPO = 'lu3g-hub';                 // LU3G Hub non elenca se stesso
	const OPTION    = 'lu3g_hub_catalog';         // ultimo catalogo valido, tenuto anche se GitHub non risponde
	const PROPS     = 'lu3g_hub_props';           // proprietà dei repository: [ repo => [ 'yes' => bool, 'time' => int ] ]
	const SEEN      = 'lu3g_hub_seen';            // repository già visti nella pagina (per l'etichetta "Nuovo")
	const CRON      = 'lu3g_hub_daily_refresh';
	const MAX_AGE   = 12 * HOUR_IN_SECONDS;
	const PROPS_AGE = 7 * DAY_IN_SECONDS;

	/**
	 * Catalogo da mostrare: quello salvato, riletto da GitHub se ha più di 12 ore.
	 *
	 * @return array{time:int,items:array,error:string}
	 */
	public static function get() {
		$data = self::stored();
		if ( time() - $data['time'] > self::MAX_AGE ) {
			$data = self::refresh();
		}
		return $data;
	}

	/** Catalogo salvato, senza chiamare GitHub. */
	public static function stored() {
		$data = get_site_option( self::OPTION );
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		return wp_parse_args(
			$data,
			array(
				'time'  => 0,
				'items' => array(),
				'error' => '',
			)
		);
	}

	/**
	 * Rilegge GitHub. Se qualcosa va storto tiene l'elenco precedente e salva il messaggio d'errore,
	 * così la pagina continua a funzionare con gli ultimi dati buoni.
	 */
	public static function refresh() {
		$old   = self::stored();
		$repos = self::api( 'orgs/' . self::ORG . '/repos?type=public&per_page=100' );
		$error = '';

		if ( is_wp_error( $repos ) ) {
			// Elenco non disponibile (es. limite dell'API): si ricontrollano le versioni dei plugin già nel catalogo,
			// che non usano l'API. I plugin nuovi compariranno al prossimo controllo riuscito.
			if ( ! $old['items'] ) {
				$old['error'] = $repos->get_error_message();
				$old['time']  = time() - self::MAX_AGE + HOUR_IN_SECONDS; // riprova tra un'ora, non a ogni pagina
				update_site_option( self::OPTION, $old );
				return $old;
			}
			$error = $repos->get_error_message() . ' Le versioni dei plugin già elencati sono state ricontrollate lo stesso.';
			$repos = array();
			foreach ( $old['items'] as $it ) {
				$repos[] = array(
					'name'        => $it['repo'],
					'html_url'    => $it['url'],
					'description' => $it['description'],
					'known'       => true, // proprietà già verificata quando è entrato nel catalogo
				);
			}
		}

		$props = get_site_option( self::PROPS );
		$props = is_array( $props ) ? $props : array();
		$items = array();

		foreach ( $repos as $repo ) {
			$name = isset( $repo['name'] ) ? (string) $repo['name'] : '';
			if ( '' === $name || self::SELF_REPO === $name || ! empty( $repo['private'] ) || ! empty( $repo['archived'] ) ) {
				continue;
			}

			// Proprietà Plugin_Wordpress, riletta una volta a settimana (non dall'elenco di riserva: lì è già nota).
			if ( empty( $repo['known'] ) && ( empty( $props[ $name ] ) || time() - $props[ $name ]['time'] > self::PROPS_AGE ) ) {
				$values = self::api( 'repos/' . self::ORG . '/' . rawurlencode( $name ) . '/properties/values' );
				if ( is_wp_error( $values ) ) {
					$error = $values->get_error_message();
					if ( empty( $props[ $name ] ) ) {
						continue;
					}
				} else {
					$yes = false;
					foreach ( (array) $values as $v ) {
						if ( isset( $v['property_name'], $v['value'] ) && self::PROPERTY === $v['property_name'] && 'yes' === strtolower( (string) $v['value'] ) ) {
							$yes = true;
						}
					}
					$props[ $name ] = array(
						'yes'  => $yes,
						'time' => time(),
					);
				}
			}
			if ( empty( $repo['known'] ) && empty( $props[ $name ]['yes'] ) ) {
				continue;
			}

			$prev    = $old['items'][ $name ] ?? null;
			$release = self::latest_release( $name, $prev );
			if ( is_wp_error( $release ) ) {
				if ( 'lu3g_hub_no_release' !== $release->get_error_code() ) {
					$error = $release->get_error_message();
					// Release non leggibile: si tiene la voce del catalogo precedente, se c'era.
					if ( $prev ) {
						$items[ $name ] = $prev;
					}
				}
				continue;
			}
			$slug    = $release['slug'];
			$version = ltrim( $release['tag'], 'vV' );
			$header  = self::plugin_header( $name, $release['tag'], $slug );

			$items[ $name ] = array(
				'repo'        => $name,
				'slug'        => $slug,
				'name'        => '' !== $header['name'] ? $header['name'] : self::nice_name( $name ),
				'description' => '' !== $header['description'] ? $header['description'] : (string) ( $repo['description'] ?? '' ),
				'requires'    => $header['requires'],
				'version'     => '' !== $header['version'] ? $header['version'] : $version,
				'package'     => $release['package'],
				'url'         => (string) $repo['html_url'],
				'release_url' => $release['url'],
				'released'    => $release['released'],
			);
		}

		uasort(
			$items,
			function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		$data = array(
			'time'  => time(),
			'items' => $items,
			'error' => $error,
		);
		update_site_option( self::PROPS, $props );
		update_site_option( self::OPTION, $data );

		return $data;
	}

	/**
	 * Ultima release di un repository SENZA l'API di GitHub (nessun limite di richieste):
	 * - tag dal rinvio di github.com/<org>/<repo>/releases/latest (→ .../releases/tag/<tag>);
	 * - zip <slug>.zip: lo slug è quello già noto dal catalogo, altrimenti il nome del repository; si controlla che
	 *   github.com/<org>/<repo>/releases/download/<tag>/<slug>.zip esista (rinvio al file);
	 * - solo se lo zip non ha quel nome (plugin nuovo con zip diverso, es. lu3g-menu → lu3g-mobile-menu.zip) si usa
	 *   l'API una volta per leggere il nome dell'allegato.
	 *
	 * @return array{tag:string,slug:string,package:string,url:string,released:string}|WP_Error
	 */
	private static function latest_release( $repo, $prev = null ) {
		$base = 'https://github.com/' . self::ORG . '/' . rawurlencode( $repo );
		$res  = wp_remote_head( $base . '/releases/latest', self::web_args() );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'lu3g_hub_http', 'GitHub non risponde: ' . $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$loc  = (string) wp_remote_retrieve_header( $res, 'location' );
		if ( 404 === $code || ( $code >= 300 && $code < 400 && ! preg_match( '#/releases/tag/([^/?\#]+)#', $loc ) ) ) {
			return new WP_Error( 'lu3g_hub_no_release', 'Nessuna release.' ); // repository senza release: non è un plugin installabile
		}
		if ( ! preg_match( '#/releases/tag/([^/?\#]+)#', $loc, $m ) ) {
			return new WP_Error( 'lu3g_hub_web', 'GitHub: risposta inattesa per ' . $repo . ' (codice ' . $code . ').' );
		}
		$tag = rawurldecode( $m[1] );

		$candidates = array_values( array_unique( array_filter( array( $prev['slug'] ?? '', $repo ) ) ) );
		$slug       = '';
		foreach ( $candidates as $c ) {
			if ( self::asset_exists( $base, $tag, $c ) ) {
				$slug = $c;
				break;
			}
		}
		if ( '' === $slug ) {
			// Nome dello zip sconosciuto: una richiesta all'API (solo per questo plugin, solo finché non lo conosciamo).
			$release = self::api( 'repos/' . self::ORG . '/' . rawurlencode( $repo ) . '/releases/latest' );
			if ( is_wp_error( $release ) ) {
				return $release;
			}
			$asset = self::zip_asset( $release );
			if ( ! $asset ) {
				return new WP_Error( 'lu3g_hub_no_release', 'Release senza zip.' );
			}
			$slug = preg_replace( '/\.zip$/i', '', $asset['name'] );
		}

		return array(
			'tag'      => $tag,
			'slug'     => $slug,
			'package'  => $base . '/releases/download/' . rawurlencode( $tag ) . '/' . rawurlencode( $slug ) . '.zip',
			'url'      => $base . '/releases/tag/' . rawurlencode( $tag ),
			// La data si conosce solo dall'API: si tiene quella già nota se la versione non è cambiata.
			'released' => ( $prev && ltrim( (string) $prev['version'], 'vV' ) === ltrim( $tag, 'vV' ) ) ? (string) ( $prev['released'] ?? '' ) : '',
		);
	}

	/** Lo zip della release esiste? (github.com risponde con un rinvio al file, senza scaricarlo.) */
	private static function asset_exists( $base, $tag, $slug ) {
		$res = wp_remote_head( $base . '/releases/download/' . rawurlencode( $tag ) . '/' . rawurlencode( $slug ) . '.zip', self::web_args() );
		if ( is_wp_error( $res ) ) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		return 200 === $code || ( $code >= 300 && $code < 400 && '' !== (string) wp_remote_retrieve_header( $res, 'location' ) );
	}

	/** Richieste alle pagine pubbliche di github.com: senza seguire i rinvii (serve l'indirizzo di destinazione). */
	private static function web_args() {
		return array(
			'timeout'     => 10,
			'redirection' => 0,
			'headers'     => array( 'User-Agent' => 'lu3g-hub' ),
		);
	}

	/** Primo allegato .zip della release. */
	private static function zip_asset( $release ) {
		foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
			if ( isset( $asset['name'], $asset['browser_download_url'] ) && preg_match( '/\.zip$/i', $asset['name'] ) ) {
				return $asset;
			}
		}
		return null;
	}

	/**
	 * Intestazione del file principale alla versione della release (nome, descrizione, versione, plugin richiesti).
	 * Il file principale ha il nome dello zip: lu3g-mobile-menu.zip → lu3g-mobile-menu.php (repository lu3g-menu).
	 */
	private static function plugin_header( $repo, $tag, $slug ) {
		$out = array(
			'name'        => '',
			'description' => '',
			'version'     => '',
			'requires'    => '',
		);
		$url = sprintf( 'https://raw.githubusercontent.com/%s/%s/%s/%s.php', self::ORG, rawurlencode( $repo ), rawurlencode( $tag ), rawurlencode( $slug ) );
		$res = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => array( 'User-Agent' => 'lu3g-hub' ),
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return $out;
		}
		$head = substr( (string) wp_remote_retrieve_body( $res ), 0, 8192 );
		$map  = array(
			'name'        => 'Plugin Name',
			'description' => 'Description',
			'version'     => 'Version',
			'requires'    => 'Requires Plugins',
		);
		foreach ( $map as $key => $label ) {
			if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $label, '/' ) . ':(.*)$/mi', $head, $m ) ) {
				$out[ $key ] = trim( wp_strip_all_tags( $m[1] ) );
			}
		}
		return $out;
	}

	/** lu3g-sponsor-carousel → LU3G Sponsor Carousel */
	private static function nice_name( $repo ) {
		$words = array_map( 'ucfirst', explode( '-', $repo ) );
		if ( isset( $words[0] ) && 'Lu3g' === $words[0] ) {
			$words[0] = 'LU3G';
		}
		return implode( ' ', $words );
	}

	/**
	 * Richiesta all'API GitHub. Restituisce l'array decodificato o un WP_Error con un messaggio comprensibile.
	 */
	private static function api( $path ) {
		$headers = array(
			'Accept'               => 'application/vnd.github+json',
			'User-Agent'           => 'lu3g-hub',
			'X-GitHub-Api-Version' => '2022-11-28',
		);
		if ( defined( 'LU3G_GITHUB_TOKEN' ) && LU3G_GITHUB_TOKEN ) {
			$headers['Authorization'] = 'Bearer ' . LU3G_GITHUB_TOKEN;
		}
		$res = wp_remote_get(
			'https://api.github.com/' . $path,
			array(
				'timeout' => 15,
				'headers' => $headers,
			)
		);
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'lu3g_hub_http', 'GitHub non risponde: ' . $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code ) {
			if ( ( 403 === $code || 429 === $code ) && '0' === (string) wp_remote_retrieve_header( $res, 'x-ratelimit-remaining' ) ) {
				$reset = (int) wp_remote_retrieve_header( $res, 'x-ratelimit-reset' );
				$when  = $reset > time() ? 'dalle ' . wp_date( 'H:i', $reset ) : 'tra un\'ora';
				return new WP_Error( 'lu3g_hub_limit', 'GitHub ha raggiunto il limite di richieste per questo server: l\'elenco dei plugin nuovi si potrà rileggere ' . $when . ' (oppure aggiungi LU3G_GITHUB_TOKEN in wp-config.php).' );
			}
			$msg = is_array( $body ) && isset( $body['message'] ) ? $body['message'] : 'errore ' . $code;
			return new WP_Error( 'lu3g_hub_api', 'GitHub: ' . $msg );
		}
		return is_array( $body ) ? $body : array();
	}

	/* ------------------------------------------------------------------ plugin installati */

	/**
	 * File del plugin installato che corrisponde a una voce del catalogo, o '' se non è installato.
	 * Prima per Update URI (github.com/<qualunque proprietario>/<repo>: vale anche per le copie installate prima
	 * del trasferimento a lu3gofficial), poi per nome della cartella (nome dello zip o del repository).
	 */
	public static function installed_file( $item ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		$pattern = '#github\.com/[^/]+/' . preg_quote( $item['repo'], '#' ) . '/?$#i';

		foreach ( $plugins as $file => $data ) {
			if ( ! empty( $data['UpdateURI'] ) && preg_match( $pattern, trim( $data['UpdateURI'] ) ) ) {
				return $file;
			}
		}
		foreach ( $plugins as $file => $data ) {
			$dir = dirname( $file );
			if ( $dir === $item['slug'] || $dir === $item['repo'] ) {
				return $file;
			}
		}
		return '';
	}

	/* ------------------------------------------------------------------ "Nuovo" */

	/** Repository nel catalogo che l'utente non ha ancora visto nella pagina. */
	public static function unseen( $items ) {
		$seen = get_site_option( self::SEEN );
		if ( ! is_array( $seen ) ) {
			// Primo elenco letto: tutto quello che c'è oggi conta come già visto, "Nuovo" vale per i plugin pubblicati dopo.
			// Con l'elenco ancora vuoto (GitHub non ha risposto) non si segna niente, se no al primo elenco buono
			// sarebbero tutti "nuovi".
			if ( $items ) {
				self::mark_seen( $items );
			}
			return array();
		}
		return array_values( array_diff( array_keys( $items ), $seen ) );
	}

	public static function mark_seen( $items ) {
		$seen = get_site_option( self::SEEN );
		$seen = is_array( $seen ) ? $seen : array();
		update_site_option( self::SEEN, array_values( array_unique( array_merge( $seen, array_keys( $items ) ) ) ) );
	}
}
