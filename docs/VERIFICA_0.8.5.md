# Verifica tecnica ABLET 0.8.5

17 novembre 2026. PHP 8.4, MariaDB 11.8, database dedicato ai test, provider HTTP locale simulato. Nessuna chiave o dato reale degli studenti.

| Verifica | Controlli superati |
| --- | ---: |
| Dominio e persistenza | 100 |
| Attività libera | 28 |
| API HTTP complete | 209 |
| Affidabilità provider e richieste concorrenti | 18 |
| Protocolli, budget del prompt, audio WAV | 37 |
| Interfaccia professionista con DOM simulato | 28 |
| Sintesi e riconoscimento browser simulati | 34 |
| Ciclo audio server simulato | 18 |
| Flusso studente con DOM simulato | 19 |
| Accessi distinti | 10 |
| Cache e service worker | 12 |
| **Totale** | **513** |

Tutti i file PHP superano il controllo di sintassi.

La suite HTTP comprende tre profili sintetici: collaborativo (ciclo completo), stallo (adattamento) e richiesta della soluzione (nessun piano inventato). Verifica inoltre programmazione delle sessioni, attività libera e conservazione delle caratteristiche precedenti.

Le nuove prove controllano: nessun retry immediato su HTTP 429; Retry-After numerico e data HTTP; RetryInfo Gemini; redazione delle chiavi dai dettagli; retry transitorio limitato; timeout distinto; attesa condivisa fra richieste; nessuna chiamata remota durante tale attesa; ripresa a scadenza; cambio del modello; messaggio dello studente conservato; microfono automatico sospeso; registro dei consumi mancanti. Una prova con due worker PHP e lo stesso cookie conferma che /api/me risponde mentre la prova del provider è ancora in corso.

Le prove simulate non attestano disponibilità, quote o qualità delle risposte di Gemini reale, né udibilità sul dispositivo dell’utente. Non è stato aggiornato il sito di produzione. Il pacchetto non richiede migrazioni aggiuntive rispetto alla 0.8.4.

Per riprodurre le prove: usare esclusivamente un database di test, impostare APP_ENV=development e le variabili DB; eseguire bash tests/run_all.sh (AZZERA il database configurato), php tests/features_v05.php e i file tests/*.cjs con Node. La verifica concorrente richiede un ambiente Unix e il comando pkill; il runtime dell’applicazione non richiede Node né un server PHP locale.
