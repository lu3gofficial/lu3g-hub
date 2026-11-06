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
 * Richieste all'API: senza token GitHub ne concede 60 l'ora per indirizzo IP. Un controllo costa 1 richiesta per
 * l'elenco dei repository più 1 per l'ultima release di ogni plugin (le proprietà solo una volta a settimana);
 * il catalogo si rilegge ogni 12 ore, una volta al giorno dal cron o col pulsante "Aggiorna l'elenco".
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

		if ( is_wp_error( $repos ) ) {
			$old['error'] = $repos->get_error_message();
			$old['time']  = time() - self::MAX_AGE + HOUR_IN_SECONDS; // riprova tra un'ora, non a ogni pagina
			update_site_option( self::OPTION, $old );
			return $old;
		}

		$props = get_site_option( self::PROPS );
		$props = is_array( $props ) ? $props : array();
		$items = array();
		$error = '';

		foreach ( $repos as $repo ) {
			$name = isset( $repo['name'] ) ? (string) $repo['name'] : '';
			if ( '' === $name || self::SELF_REPO === $name || ! empty( $repo['private'] ) || ! empty( $repo['archived'] ) ) {
				continue;
			}

			// Proprietà Plugin_Wordpress, riletta una volta a settimana.
			if ( empty( $props[ $name ] ) || time() - $props[ $name ]['time'] > self::PROPS_AGE ) {
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
			if ( empty( $props[ $name ]['yes'] ) ) {
				continue;
			}

			$release = self::api( 'repos/' . self::ORG . '/' . rawurlencode( $name ) . '/releases/latest' );
			if ( is_wp_error( $release ) ) {
				$error = $release->get_error_message();
				// Release non leggibile: si tiene la voce del catalogo precedente, se c'era.
				if ( isset( $old['items'][ $name ] ) ) {
					$items[ $name ] = $old['items'][ $name ];
				}
				continue;
			}

			$asset = self::zip_asset( $release );
			if ( ! $asset ) {
				continue; // nessuno zip: non è installabile
			}

			$slug    = preg_replace( '/\.zip$/i', '', $asset['name'] );
			$version = ltrim( (string) $release['tag_name'], 'vV' );
			$header  = self::plugin_header( $name, (string) $release['tag_name'], $slug );

			$items[ $name ] = array(
				'repo'        => $name,
				'slug'        => $slug,
				'name'        => '' !== $header['name'] ? $header['name'] : self::nice_name( $name ),
				'description' => '' !== $header['description'] ? $header['description'] : (string) ( $repo['description'] ?? '' ),
				'requires'    => $header['requires'],
				'version'     => '' !== $header['version'] ? $header['version'] : $version,
				'package'     => (string) $asset['browser_download_url'],
				'url'         => (string) $repo['html_url'],
				'release_url' => (string) ( $release['html_url'] ?? '' ),
				'released'    => (string) ( $release['published_at'] ?? '' ),
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
				return new WP_Error( 'lu3g_hub_limit', 'GitHub ha raggiunto il limite di richieste per questo server: riprova tra un\'ora (oppure aggiungi LU3G_GITHUB_TOKEN in wp-config.php).' );
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
