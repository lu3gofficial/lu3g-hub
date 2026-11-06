# Registro delle modifiche

## 1.0.0
- Prima versione. Pagina *Plugin → Plugin LU3G* con tutti i plugin LU3G presi da GitHub (repository pubblici di lu3gofficial con la proprietà `Plugin_Wordpress = yes` e uno zip nell'ultima release): installa, attiva, disattiva e aggiorna, senza ricaricare la pagina.
- I plugin nuovi compaiono da soli (controllo ogni 12 ore e una volta al giorno dal cron, o con *Aggiorna l'elenco*), con l'etichetta *Nuovo* e un avviso in Bacheca e nella pagina Plugin.
- Riconosce i plugin già installati dall'intestazione `Update URI` o dal nome della cartella (es. Menu LU3G, repository `lu3g-menu`, cartella `lu3g-mobile-menu`).
- Prima di attivare controlla i plugin richiesti (es. Elementor) e lo dice in chiaro.
- Se GitHub non risponde o il limite di richieste è raggiunto, mostra l'ultimo elenco letto con un avviso.
- Aggiornamenti di LU3G Hub dalle release di https://github.com/lu3gofficial/lu3g-hub.
