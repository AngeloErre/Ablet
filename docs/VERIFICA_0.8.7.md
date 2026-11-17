# ABLET 0.8.7 — debug e stress test prima del rilascio

Data: 17 novembre 2026. Esito: candidato al collaudo su hosting e dispositivi reali. Le prove locali descritte sono superate; non costituiscono validazione scientifica o attestazione di assenza di difetti.

## Ambito e metodo

Revisione dei punti di ingresso HTTP, autorizzazioni e titolarità, accessi distinti, sessioni/CSRF, payload, orchestration SRL, idempotenza, errori e quote del provider, chiamate audio, gestione asincrona del frontend, attività libera, programmazione, protocolli e aggiornamento del database.

Esecuzione su PHP 8.4 e MariaDB 11.8 isolati, provider HTTP simulato e sintesi/riconoscimento simulati. Solo dati sintetici, nessuna chiave reale e nessuna richiesta di carico al sito in produzione. Il server integrato PHP ha due worker aggiuntivi: non riproduce il processo Apache/PHP e i limiti di Aruba. Revisione del codice e DOM simulato non equivalgono a un controllo visivo del layout sui dispositivi.

## Problemi riprodotti e corretti

| Problema | Effetto | Correzione e verifica |
| --- | --- | --- |
| Lock PHP nelle rotte audio condivise fra ruoli | Una richiesta audio lenta poteva accodare le altre dello stesso utente | Rilascio dopo autenticazione; prova concorrente con audio lento e stesso cookie |
| Lettura troncata del corpo HTTP | Il superamento del limite non era segnalato esplicitamente | Lettura di un byte oltre soglia e risposta 413; prova con oltre 600.000 byte |
| Elenco modelli privo di budget complessivo | Un timeout configurato lungo poteva superare il tempo di attesa del frontend | Budget di 40 secondi come limite applicativo, rispetto ai 70 secondi del client; timeout dell’hosting può essere più breve |
| Bozza del messaggio chiusa prima della risposta | Un errore di rete poteva lasciare lo studente senza il testo da riprovare | Bozza in memoria per la stessa sessione, recuperabile da Scrivo; eliminata dopo conferma. Non persiste dopo refresh o chiusura |
| Nuovo identificatore dopo un esito di rete incerto | Riprovare un invio già salvato poteva duplicarlo | Identificatore riutilizzato per stesso messaggio/azione e sessione dopo rete o 5xx; test client e 16 invii concorrenti sul server |
| Attività libera presente soltanto nel programma | Il nuovo ingresso poteva mostrare Libera disabilitata anche se il passo corrente era libero | Inclusione del solo passo libero corrente, disponibile e autorizzato; nessun salto della sequenza |
| Pipeline del comando principale dei test | Il riepilogo poteva nascondere un codice di uscita fallito | set -euo pipefail |

Il generatore di stress è stato corretto durante le prove: conservava tutte le risposte estese e superava il proprio limite di memoria. Ora durante le letture di carico registra esito e tempi, senza trattenere i corpi JSON. Questo errore riguardava il generatore, non è stato classificato come crash di ABLET.

## Risultati automatici

| Suite | Controlli superati |
| --- | ---: |
| Dominio/persistenza | 100 |
| Dominio attività libera | 28 |
| API HTTP complete | 209 |
| API HTTP specifiche attività libera | 31 |
| Affidabilità provider e concorrenza, compreso audio | 21 |
| Protocolli, budget prompt e WAV | 37 |
| Flusso studente e accoglienza vocale | 32 |
| UI professionista con DOM simulato | 28 |
| Ciclo audio browser simulato | 34 |
| Ciclo audio server simulato | 18 |
| Accessi distinti | 10 |
| Cache/service worker | 12 |
| Invarianti dello stress test | 15 |
| **Totale** | **575** |

Controllo di sintassi dei file PHP superato. Verificato separatamente upgrade 008→009: conservazione di dati, associazioni delle sessioni alle versioni e assegnazioni precedenti; riesecuzione senza migrazioni duplicate. La 0.8.7 non introduce una migrazione.

La suite include tre profili SRL: collaborativo (ciclo completo), stallo (adattamento) e richiesta della soluzione (nessun piano inventato). Verifica isolamento dei proprietari, CSRF, revoche, archiviazione, versioni, autorizzazioni vocali, conservazione, cancellazione, analisi testuale, output invalidi e prompt injection simulata. Questi controlli non garantiscono che un LLM reale rispetti sempre i vincoli pedagogici.

## Stress locale

652 richieste HTTP totali, inclusi accessi e controlli. Cinque ondate di 120 letture, invii concorrenti, duplicati e raffiche oltre limite. Per l’area professionista: 51 sessioni e oltre 1.000 messaggi in una sessione. Nessun HTTP 500 nelle richieste del carico completato. Gli errori 400, 403, 413 e 429 previsti sono stati verificati come esiti corretti.

| Area | Concorrenza | Letture | p50 (ms) | p95 (ms) | Massimo (ms) | Richieste/s |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Studente | 1 | 120 | 6,54 | 10,75 | 14,20 | 148,56 |
| Studente | 4 | 120 | 10,46 | 22,79 | 28,80 | 211,80 |
| Studente | 12 | 120 | 20,15 | 45,67 | 56,65 | 258,68 |
| Professionista | 4 | 120 | 127,54 | 247,11 | 305,16 | 21,46 |
| Professionista | 12 | 120 | 159,20 | 401,54 | 591,58 | 29,71 |

Misure di una singola esecuzione locale, senza generazione LLM reale. Concorrenza significa richieste contemporanee, non studenti connessi. I risultati non stimano la capacità di Aruba, il numero di utenti supportati o i tempi di Gemini. Il dataset e la durata non coprono mesi di esercizio, molti professionisti o esaurimento di disco/connessioni. Dati grezzi: [STRESS_0.8.7.json](STRESS_0.8.7.json).

## Miglioramenti prioritari

| Priorità | Implementazione proposta | Motivazione e criterio di accettazione |
| --- | --- | --- |
| Prima del rilascio pubblico | Collaudo su staging Aruba con account sintetici | Eseguire un ciclo SRL completo, libera e programma, con modello realmente configurato; verificare richieste concorrenti nei limiti dell’hosting |
| Prima del rilascio pubblico | Collaudo fisico Chrome Android/desktop e Safari iPad | Verificare audio udibile, accoglienza, permessi negati/concessi, silenzio 3 s, schermo bloccato/ripresa, conferme vocali e pulsanti raggiungibili a larghezze differenti |
| Alta | Paginazione e aggregazione della dashboard | Il codice calcola gli indicatori di ogni sessione con più query; la dashboard seleziona al massimo 150 sessioni. Mostrare esplicitamente il limite e il totale, paginare e preaggregare senza alterare tempi/indicatori |
| Alta | Contatori e attese condivise anche per il provider audio | L’audio ha un percorso esterno distinto dal registro llm_calls. Registrare metadati senza audio/testo, distinguere 429/503 e rispettare Retry-After anche per TTS/STT |
| Alta | Monitoraggio degli errori e identificatore della richiesta | Correlare fallimenti di browser/server/provider, senza contenuti degli studenti o chiavi. Mostrare tasso di errore, timeout e latenza osservabili sullo staging |
| Alta | Recupero delle bozze anche nei moduli di piano | La correzione attuale riguarda i messaggi. Estendere a micro-obiettivi e moduli lunghi, definendo durata e cancellazione delle bozze per dispositivo condiviso |
| Media | Scelta esplicita fra più programmi attivi | L’home attuale espone il primo programma attivo pertinente. Permettere la scelta del percorso senza mostrare in anticipo le attività future |
| Media | Test automatici del browser e controlli accessibilità | Coprire focus, tastiera, contrasto, zoom e viewport con motore di rendering reale. Le prove DOM attuali verificano struttura e comportamento, non la disposizione visiva |
| Media | Prove prolungate e multiutente | Eseguire carico graduale su staging con più identità, archivi più ampi e richieste LLM lente simulate; osservare memoria, database, connessioni e code nel tempo |
| Media | CI sul repository | Eseguire automaticamente lint, suite Node/PHP e API con database temporaneo su ogni modifica, impedendo il rilascio quando un controllo fallisce |

Restano da verificare sul servizio reale le quote del modello, la correttezza disciplinare delle risposte, l’aderenza pedagogica e la stima dei costi effettivamente fatturati. Le tariffe configurate e i token non dichiarati dal provider non possono essere ricostruiti dal solo stress test.

## Riproducibilità

Usare un database dedicato: tests/run_all.sh e tests/run_free_activity.sh AZZERANO lo schema configurato. Non eseguirli sul database degli studenti.

- Suite principale: bash tests/run_all.sh.
- Stress opzionale nello stesso ambiente: ABLET_STRESS=1 STRESS_REPORT=/percorso/risultati.json bash tests/run_all.sh. Richiede APP_ENV=development, DB_NAME contenente test, server locale e ambiente Unix con pkill. Il flag reimposta il database prima del carico.
- Suite libera: bash tests/run_free_activity.sh.
- Budget/protocolli/audio WAV: php tests/features_v05.php.
- Frontend: node tests/student_flow.cjs, ui_v05.cjs, voice.cjs, voice_server.cjs, login_home.cjs e cache_refresh.cjs, indicando la cartella tests per ciascun file.

Prima dell’aggiornamento fare un backup. Conservare config.php, .env e storage/, caricare i sorgenti e aprire refresh.php. Il sito di produzione non è stato modificato durante questa verifica.
