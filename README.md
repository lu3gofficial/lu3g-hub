# LU3G Hub

Raccoglitore dei plugin WordPress di LU3G Agenzia Web: da un'unica pagina (**Plugin → Plugin LU3G**) si installano,
attivano, disattivano e aggiornano tutti i plugin LU3G pubblicati su GitHub. I plugin nuovi compaiono da soli.

Requisiti: PHP 7.4+, WordPress 6.0+.

## Come trova i plugin
Un repository dell'organizzazione [lu3gofficial](https://github.com/lu3gofficial) entra nell'elenco se:
1. è **pubblico**;
2. ha la proprietà personalizzata **`Plugin_Wordpress` = `yes`** (Settings del repository → Custom properties);
3. la sua **ultima release ha uno zip allegato** (quello creato dall'automazione di ogni plugin).

Per aggiungere un plugin nuovo all'elenco basta pubblicarlo così: compare sui siti al controllo successivo
(entro 12 ore, una volta al giorno dal cron, o subito con "Aggiorna l'elenco"), con l'etichetta **Nuovo** e un avviso
in Bacheca. Non viene installato da solo: lo si installa dalla pagina quando serve.

## La pagina
- **Installa** scarica lo zip della release e lo installa (disattivato).
- **Attiva / Disattiva** sul sito corrente. Se manca un plugin richiesto (es. Elementor) lo dice prima di attivare.
- **Aggiorna alla X** sostituisce la cartella con lo zip nuovo; il plugin resta attivo se lo era.
  Gli aggiornamenti continuano ad arrivare anche dalla Bacheca, perché ogni plugin ha il suo sistema di aggiornamento.
- I plugin già installati vengono riconosciuti dall'intestazione `Update URI` (qualunque sia il proprietario del
  repository al momento dell'installazione) o dal nome della cartella.
- Permessi: installare e aggiornare richiedono i permessi di WordPress per farlo (su un multisite solo il super
  amministratore); attivare e disattivare `activate_plugins`.

## Limiti di GitHub
Senza token GitHub concede 60 richieste l'ora per indirizzo IP. Un controllo dell'elenco ne usa una per l'elenco dei
repository e una per ogni plugin (la proprietà `Plugin_Wordpress` si rilegge una volta a settimana); nome e descrizione
arrivano da raw.githubusercontent.com, che non conta. Se il limite viene raggiunto la pagina mostra l'ultimo elenco letto.
Facoltativo, in `wp-config.php`: `define( 'LU3G_GITHUB_TOKEN', 'github_pat_...' );`

## Struttura
- `lu3g-hub.php` – intestazione, avvio, cron giornaliero.
- `includes/class-catalog.php` – lettura del catalogo da GitHub, cache, riconoscimento dei plugin installati, "Nuovo".
- `includes/class-admin.php` – pagina, azioni (admin-ajax), avviso dei plugin nuovi.
- `includes/class-updater.php` – aggiornamenti di LU3G Hub stesso dalle release.
- `assets/` – CSS e JS della pagina.

## Pubblicare una nuova versione
1. Aumenta la versione in `lu3g-hub.php` (`Version:` e `LU3G_HUB_VERSION`) e `Stable tag` in `readme.txt`.
2. Aggiungi la sezione in `CHANGELOG.md` (diventa la nota della release).
3. Commit, poi `git tag vX.Y.Z && git push && git push --tags`: l'automazione crea lo zip e la release.
