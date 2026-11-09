# Registro delle modifiche

## 1.1.1
- `includes/class-updater.php` ora è generato dal modello comune di [lu3g-updater](https://github.com/lu3gofficial/lu3g-updater) (1.0.0), a partire dal nuovo file `.lu3g-updater`. Il codice dell'updater è lo stesso di prima: cambia solo il commento in cima.
- Release avviabile anche da GitHub, senza computer: *Actions → Release → Run workflow* sul branch `main`. Il tag lo crea l'automazione dalla `Version` del plugin, e si ferma se quella versione è già pubblicata.

## 1.1.0
- Voce propria **LU3G** nel menu della bacheca, subito sotto la Bacheca, con il logo LU3G come icona (prende i colori dello schema della bacheca). Il vecchio indirizzo Plugin → Plugin LU3G porta qui.
- Nuova interfaccia: intestazione scura col logo LU3G e contatori (disponibili, attivi, da aggiornare, aggiornamenti automatici), filtri (Tutti, Attivi, Disattivati, Da installare, Da aggiornare), ricerca, schede con segno colorato, interruttore *Attivo*, pulsanti Installa/Aggiorna, avvisi a comparsa, pulsante **Aggiorna tutti** (uno alla volta, con l'avanzamento sulla scheda).
- Animazioni: ingresso dell'intestazione e delle schede una dopo l'altra, bagliore che ruota, interruttori che scorrono, barra di avanzamento, contatori che contano, scossa in caso di errore. Ferme con "riduci animazioni".
- **Aggiornamenti automatici**: interruttore su ogni scheda, *Tutti i plugin installati*, *Anche su quelli che installi da qui* e *LU3G Hub*. Sono quelli di WordPress (stessa impostazione dei link della pagina Plugin): WordPress aggiorna da solo due volte al giorno e manda l'email di riepilogo.
- Gli aggiornamenti funzionano anche per le copie dei plugin LU3G il cui sistema di aggiornamento non risponde più (es. Login Brand 1.6.0): la versione nuova arriva dal catalogo di LU3G Hub. Nella pagina Plugin compare il link degli aggiornamenti automatici anche per i plugin LU3G già aggiornati.

## 1.0.0
- Prima versione. Pagina *Plugin → Plugin LU3G* con tutti i plugin LU3G presi da GitHub (repository pubblici di lu3gofficial con la proprietà `Plugin_Wordpress = yes` e uno zip nell'ultima release): installa, attiva, disattiva e aggiorna, senza ricaricare la pagina.
- I plugin nuovi compaiono da soli (controllo ogni 12 ore e una volta al giorno dal cron, o con *Aggiorna l'elenco*), con l'etichetta *Nuovo* e un avviso in Bacheca e nella pagina Plugin.
- Riconosce i plugin già installati dall'intestazione `Update URI` o dal nome della cartella (es. Menu LU3G, repository `lu3g-menu`, cartella `lu3g-mobile-menu`).
- Prima di attivare controlla i plugin richiesti (es. Elementor) e lo dice in chiaro.
- Se GitHub non risponde o il limite di richieste è raggiunto, mostra l'ultimo elenco letto con un avviso.
- Aggiornamenti di LU3G Hub dalle release di https://github.com/lu3gofficial/lu3g-hub.
