# ABLET — Adaptive Based Learning Enhancer Tool

Piattaforma web in italiano per sostenere l'autoregolazione dell'apprendimento con un agente pedagogico conversazionale.
Committente scientifico: Prof. Angelo Rega.

**Stato: MVP con modalità Attività libera (aggiornamento 0.8.7).** Frontend HTML/CSS/JavaScript senza dipendenze, backend PHP, database MySQL/MariaDB.

> La riuscita tecnica dell'MVP non equivale alla sua validazione scientifica: soglie, livelli di supporto, codebook e lessico di polarità sono parametri progettuali da validare.

## Versione 0.8.7 — verifica prima del rilascio

Correzioni: sessione PHP liberata anche durante audio server; richieste troppo grandi respinte con HTTP 413; elenco modelli con budget di 40 secondi; recupero in memoria della bozza del messaggio dopo errore di rete e riuso dell’identificatore per riprovare lo stesso invio; selezione dell’attività libera anche quando è la sessione corrente di un programma. La suite principale propaga ora correttamente i fallimenti dei comandi in pipeline.

**575 controlli superati**. Stress locale: **652 richieste**, concorrenza fino a 12, area studente e professionista con 51 sessioni e oltre 1.000 messaggi. Duplicati, limiti e payload non validi verificati. Dettagli, misure e miglioramenti prioritari in [docs/VERIFICA_0.8.7.md](docs/VERIFICA_0.8.7.md). Nessuna nuova migrazione. Non equivale a un collaudo su Aruba, Gemini reale o dispositivi fisici.

## Versione 0.8.6 — accoglienza vocale dello studente

All’accesso compare subito l’avatar con la domanda «Vuoi scegliere un’attività libera oppure seguire il programma che ti è stato assegnato?». La voce usa impostazioni e autorizzazioni esistenti; se il browser richiede un gesto, usare Ascolta. La risposta vocale viene ascoltata dopo la fine della domanda, con arresto dopo tre secondi di silenzio e riattivazione manuale. La scelta riconosciuta richiede conferma; restano disponibili due pulsanti.

Attività libera mostra soltanto le attività libere già rese disponibili dal professionista, con il protocollo assegnato. Programma assegnato conserva la scelta tra una sessione alla volta e tutte in sequenza. La sequenza resta visibile soltanto su richiesta. Le opzioni non disponibili sono disabilitate con spiegazione. L’accoglienza non chiama il modello LLM e non crea sessioni. Nessuna nuova migrazione.

Verifica mirata: test del flusso studente con sintesi e riconoscimento simulati, inclusi accoglienza, conferma vocale, separazione libera/programma e sequenze; regressioni audio, interfaccia, accessi e cache. Le politiche di riproduzione automatica e l’udibilità devono essere provate sul dispositivo reale.

## Versione 0.8.5 — attese e diagnostica LLM

- Il lock PHP della sessione viene liberato dopo l’autenticazione, prima delle operazioni lente. Login e logout conservano la scrittura della sessione.
- Gli HTTP 429 non generano retry immediati. Si rispetta `Retry-After` (o `RetryInfo.retryDelay` Gemini) con un’attesa condivisa per configurazione, senza tenere il browser in attesa. In assenza di indicazioni: 60 secondi per 429, 10 per 503. Le quote giornaliere possono richiedere un’attesa più lunga: questi tempi non garantiscono il ripristino della quota.
- Retry transitori limitati dal budget complessivo; con `Retry-After` si torna subito al browser. La prova connessione ha un budget di 40 secondi.
- Distinzione quota / indisponibilità / timeout; messaggio dello studente conservato e microfono automatico sospeso dopo un errore del provider. Si riattiva manualmente.
- Dettaglio sessione: richieste, rinvii locali, token dichiarati e ultime 30 chiamate tecniche. I consumi mancanti non sono stimati come consumi nulli; i retry HTTP interni non sono contati separatamente.

Nessuna nuova migrazione: l’attesa usa la tabella `rate_limits` già esistente. Preservare `config.php`, `.env` e `storage/`, poi aprire `refresh.php`. Il modello configurato dallo psicologo e il sistema TTS/STT restano quelli scelti. Le verifiche automatiche usano provider simulati, senza chiamare Gemini reale.

Verifica della 0.8.5: **513 controlli superati**, inclusi tre profili SRL sintetici, conservazione del messaggio dopo errori e richieste concorrenti con lo stesso cookie. Dettaglio e limiti in [docs/VERIFICA_0.8.5.md](docs/VERIFICA_0.8.5.md).

## Versione 0.8.4 — risultato persistente della prova provider

«Prova la connessione» mostra l’avanzamento, blocca doppi clic e conserva l’esito sotto la configurazione: modello salvato, categoria di errore, eventuale HTTP e dettaglio restituito dal server. Anche errori di rete, sessione o autorizzazione restano leggibili. Le modifiche nel modulo richiedono «Salva» prima della prova, che usa la configurazione persistita. Il cambiamento migliora la diagnosi; non dimostra il ripristino della connessione Gemini dell’utente. Nessuna modifica al TTS, al riconoscimento, al modello o ai parametri inviati all’LLM. Nessuna migrazione.

## Versione 0.8.3 — diagnosi del TTS Chrome

- La sintesi attende fino a due secondi il caricamento delle voci (`voiceschanged`) e segnala esplicitamente quando Chrome non ne rende disponibile nessuna.
- Frasi molto lunghe sono suddivise in segmenti di massimo 180 caratteri. La selezione automatica privilegia una voce locale della lingua richiesta; una voce esplicitamente selezionata mantiene la precedenza.
- Nuova pagina pubblica `public/tts-check.html`, raggiungibile anche da Voce e avatar → Diagnostica audio del dispositivo. Confronta voce nativa, ABLET breve e ABLET lunga, mostrando elenco voci, eventi, errori e stato di attivazione.
- Il report è temporaneo e rimane sul dispositivo: non include testo delle sessioni, credenziali o audio. Con «Copia report» può essere condiviso manualmente. I test possono utilizzare il servizio TTS del dispositivo o del produttore del browser, come le altre funzioni TTS browser.
- Il riconoscimento vocale non è modificato.

La causa del problema riferito sul Chrome dell’utente non è ancora confermata: occorre eseguire A/B/C sul dispositivo interessato e indicare quali prove si sentono. Gli eventi `start`/`end` non dimostrano da soli che l’audio sia udibile. Nessun aggiornamento del database. Caricare i file, aprire `refresh.php` e quindi `tts-check.html`.

## Versione 0.8.2 — modifica delle caratteristiche dell’Attività Libera

In Attività e materiali, ogni scheda libera offre «Modifica caratteristiche». Il professionista può aggiornare titolo, indicazioni generali, criteri orientativi di completamento e durata indicativa anche dopo l’assegnazione. Questi campi sono configurabili anche alla creazione. Il compito specifico e la stima del tempo restano definiti dallo studente nel dialogo SRL. Materiali preimpostati e disattivazione dell’agente restano esclusi per questa modalità.

Le modifiche valgono per le nuove sessioni. Prima del primo aggiornamento si conserva in ogni sessione già creata una copia delle precedenti caratteristiche; gli aggiornamenti successivi non la sovrascrivono. Dialoghi, stime dello studente, versione del protocollo e assegnazioni rimangono conservati. La modifica è riservata al proprietario dell’attività e richiede autenticazione e CSRF.

Nessuna migrazione aggiuntiva. Preservare `config.php`, `.env`, `storage/`, poi aprire `refresh.php`.

## Versione 0.8.1 — tutti gli originali sono modelli protetti

Anche «Pianificazione e autoregolazione» e «Comprensione del testo» sono identificati esplicitamente come modelli di base protetti. Gli originali si consultano o si copiano; non si modificano e non si eliminano tramite l’app. I protocolli personali già esistenti, anche con gli stessi titoli, conservano configurazioni, assegnazioni e sessioni: sono ora distinti da un’etichetta «Copia personale modificabile». Nessuna migrazione aggiuntiva. Caricare anche `resources/templates/` e aggiornare la cache con `refresh.php`.

## Versione 0.8 — scelta del percorso e turni vocali

All’ingresso lo studente sceglie «Un’attività alla volta» oppure «Tutte in sequenza». La scelta vale per l’accesso corrente e può essere cambiata dalla home. Viene mostrata soltanto l’attività attuale; la sequenza viene costruita e mostrata soltanto dopo il comando «Qual è la sequenza delle attività?» (pulsante o richiesta vocale/testuale durante una sessione). Il piano dei micro-obiettivi della singola attività resta consultabile separatamente.

In modalità sequenza il completamento di una sessione avvia quella successiva dopo il messaggio conclusivo. L’ordine assegnato dal professionista, le autorizzazioni e i blocchi rimangono applicati. Una pausa o una chiusura incompleta non fanno avanzare il percorso. Se il browser blocca la voce conclusiva è disponibile «Prosegui con la prossima attività». Non vengono avviate sessioni in anticipo.

Il microfono si apre al termine reale della risposta dell’assistente, se l’ingresso audio è abilitato e autorizzato. Tre secondi senza parlato lo spengono con un avviso; occorre toccare «Parla» per riattivarlo. Anche dopo una risposta successiva resta spento fino al tocco. La trascrizione passa dalla conferma esistente prima dell’invio. Nella modalità server il segnale è analizzato localmente: l’energia audio rileva suoni, non distingue perfettamente parlato e rumore ambientale; non è inviato audio se non si rileva alcun suono significativo. Inizialmente il browser può richiedere il permesso del microfono. Restano disponibili scrittura e pulsanti.

**Aggiornamento dalla 0.7:** nessuna nuova migrazione. Preservare `config.php`, `.env`, `storage/`, quindi aprire `refresh.php`.

**Verifica:** test simulati del percorso, della visibilità della sequenza, dei blocchi e del riarmo manuale; timer di silenzio verificati per entrambi i motori vocali. Voce reale, rumore ambientale, permessi e comportamento mobile vanno verificati sul dispositivo utilizzato.

## Versione 0.7 — interfaccia responsive

- Navigazione laterale su desktop, menu richiudibile sotto 64rem; cambio orientamento gestito senza ricaricare.
- Ricerca studenti per alias, pseudonimo e codice; creazione account in pannello richiudibile.
- Sessioni ordinate in schede adattive; moduli e griglie senza larghezze minime che eccedano lo schermo.
- Collegamenti alle tre fasi SRL, stato della bozza e conferma prima di abbandonare modifiche non salvate nell’editor dei protocolli. Non è un salvataggio automatico.
- Controlli vocali proporzionati allo schermo, barra raggiungibile, pannelli con altezza dinamica e margini sicuri; gestione del focus nei dialoghi.
- Tabelle scorrevoli raggiungibili da tastiera, contrasto del link nella barra superiore corretto.

**Aggiornamento dalla 0.6:** caricare i file aggiornati preservando `config.php`, `.env` e `storage/`. Nessuna nuova migrazione rispetto alla 009. Aprire `refresh.php` dopo il caricamento per aggiornare la cache.

**Verifica:** test DOM dell’interfaccia, accessi e cicli audio simulati. Il browser grafico nell’ambiente di sviluppo non si avvia: la verifica visiva su dispositivi fisici resta necessaria. Provare 320/390px, tablet 768/1024px e desktop 1366px, anche con caratteri ingranditi, tastiera virtuale e rotazione. Verificare sul dispositivo la reale udibilità della voce e i permessi del microfono.

## Versione 0.6 — programmazione centrata sullo studente

Le nuove assegnazioni si fanno esclusivamente in **Studenti → scheda dello studente → Programma sessioni · attività e protocolli**. Attività e Protocolli sono cataloghi/configuratori: non offrono comandi di assegnazione. Non esiste più una scheda separata Assegna attività.

Per ogni studente puoi preparare una **sessione singola** oppure una **sequenza di 1–30 sessioni**. Ogni riga fissa un’attività e una versione pubblicata del protocollo; puoi cambiare ordine e rimuovere righe prima di assegnare. Ogni sessione svolge il proprio ciclo SRL completo. Le sequenze non confondono le tre fasi con tre incontri separati.

Lo studente avvia solo la prima sessione non risolta del programma. La chiusura completa sblocca la successiva. La chiusura incompleta blocca la catena finché il professionista decide di saltare quella sessione o riprogrammare. Gli avvii ripetuti dello stesso passo riprendono la medesima sessione, senza duplicarla. Il backend impedisce avvii fuori ordine o dalla vecchia voce generica dell’attività.

L’annullamento di un programma disattiva le sessioni future, conservando la sessione eventualmente già creata e i suoi dati. L’archiviazione di un protocollo blocca le future sessioni collegate; il ripristino non le riattiva automaticamente. Per cambiare attività/protocolli dopo l’assegnazione si annullano le sessioni future e si crea un nuovo programma. Le sessioni già create mantengono la versione originale.

Le assegnazioni delle versioni precedenti rimangono conservate e revocabili nella scheda dello studente. Programmare una stessa attività disattiva per quello studente la vecchia abilitazione a ripeterla liberamente. I dati e gli altri studenti già assegnati non vengono cancellati.

**☰ Impostazioni applicazione** raccoglie Provider, Voce e avatar, Validazione codifiche e Dati e autorizzazioni. Il manuale `manual.php` è aggiornato al nuovo percorso operativo. Restano disponibili i cinque modelli SRL protetti, i protocolli personali, le stime di costo e il motore audio server della 0.5.

### Aggiornare

Fare un backup e caricare i file conservando `config.php`, l’eventuale `.env` e `storage/`. Aprire `install.php` e scegliere **Aggiorna il database**: dalla 0.5 serve **009_session_plans.sql**; da versioni precedenti vengono applicate anche le migrazioni mancanti. Eliminare nuovamente `public/install.php` e usare `refresh.php`. Non reinstallare o azzerare il database.

### Verifica 0.6

PHP 8.4 + MariaDB 11.8: **100** controlli di dominio/persistenza, **28** di attività libera e **192 HTTP**, tutti superati. I 27 nuovi controlli HTTP coprono ordine, isolamento degli studenti, protocolli diversi, avvio idempotente, chiusura incompleta, salto professionale, annullamento e archiviazione/ripristino.

Test JavaScript senza dipendenze: `voice.cjs` (22), `voice_server.cjs` (15), `login_home.cjs` (10), `cache_refresh.cjs` (12), `ui_v05.cjs` aggiornato al nuovo flusso (13). `features_v05.php` mantiene 33 controlli di modelli, economia e WAV. Le prove DOM/audio sono simulate: resta la verifica visiva e sonora sul dispositivo reale. Il successo tecnico non equivale a validazione scientifica.

## Aggiornamento e pulizia della cache

Disponibile il comando **Aggiorna app e svuota cache** nella barra del professionista e nelle impostazioni dello studente. Se l'interfaccia è ancora vecchia, aprire direttamente `PERCORSO_DI_ABLET/refresh.php` e premere il pulsante. La pagina funziona indipendentemente dal JavaScript già in cache.

La pulizia riguarda solo i file nella cache di questa installazione e il suo service worker: non cancella cookie, preferenze, account, protocolli, attività o sessioni nel database. Prima di usarla, salvare eventuali modifiche non ancora inviate. Va eseguita nel browser/dispositivo che mostra il problema. Non svuota le cache del servizio di hosting o di una CDN.

Il caricamento usa ora una versione comune basata sul contenuto di tutti i moduli JavaScript e del CSS, una import map protetta da nonce e la rivalidazione dalla rete prima del fallback offline. Test dedicati: `node tests/cache_refresh.cjs` (12 verifiche). Questa modifica non aggiunge migrazioni del database.

## Novità: Attività libera

Lo studente sceglie e descrive il compito nel dialogo e stima il tempo totale oppure i tempi dei micro-obiettivi. L'agente usa le conoscenze del modello per guidare il lavoro **sempre secondo il protocollo SRL pubblicato assegnato dallo psicologo**. Tempi disponibili e durate stimate rimangono separati. Compiti e materiali preimpostati restano disponibili.

**Per aggiornare un sito già installato:** caricare i file conservando `config.php`, `.env` e `storage/`, poi aprire l'installatore e scegliere **Aggiorna il database** (migrazioni mancanti fino alla 009); non reinstallare o azzerare lo schema. Istruzioni complete e limiti in [docs/ATTIVITA_LIBERA.md](docs/ATTIVITA_LIBERA.md).

## Requisiti

- PHP ≥ 8.1 con `pdo_mysql`, `mbstring`, `json`; consigliati `curl` (provider LLM) e `sodium` oppure `openssl` (cifratura delle chiavi API)
- MySQL ≥ 8.0 oppure MariaDB ≥ 10.2 (consigliati); MySQL 5.6.5–5.7 in **modalità ridotta**: i dati JSON sono salvati come testo e i limiti dei valori sono controllati solo dall'applicazione (i vincoli CHECK non esistono su quei server). Il funzionamento è identico; cambia solo il numero di protezioni a livello di database.
- Apache con `.htaccess` e `mod_rewrite` (hosting condiviso) oppure qualsiasi server con document root su `public/`
- Nessuna dipendenza Composer o Node.js; nessun accesso SSH necessario

## Installazione su hosting condiviso (es. Aruba Hosting Linux) — senza SSH

1. **Piano**: serve un database MySQL (su Aruba: Hosting Linux Easy o Advanced; il Basic non lo include). Nel pannello scegli **PHP 8.3 o 8.4** e crea il database annotando host, nome, utente e password.
2. **Caricamento**: con FTP o File Manager carica il contenuto dell'archivio nella radice del sito o in una sottocartella (es. `/ablet`). Non serve spostare la document root: l'`.htaccess` principale instrada tutto in `public/` e le cartelle `src/`, `database/`, `resources/`, `tests/`, `storage/` sono negate dal web.
3. **Installazione**: apri `https://tuodominio.it/ablet/install.php` (o `/install.php` se alla radice). L'installatore verifica PHP ed estensioni, chiede i dati del database e il tuo codice di accesso, crea `config.php` (con una chiave di cifratura casuale), crea le tabelle e il tuo account. La password viene mostrata una sola volta. Facoltativo: dati dimostrativi sintetici.
   - Se l'hosting non consente di scrivere `config.php`, l'installatore ne mostra il contenuto: crea il file via FTP nella cartella principale di ABLET e ricarica la pagina (ti chiederà la password del database come conferma).
   - Se l'utente del database non può creare trigger (frequente su hosting condiviso), le migrazioni proseguono e lo segnalano: l'immutabilità delle versioni pubblicate resta garantita dall'applicazione.
4. **Dopo**: elimina `public/install.php` (consigliato) e collega un provider dalla scheda «Provider». Per un aggiornamento: carica i nuovi file e riapri l'installatore, che a installazione esistente offre solo «Aggiorna il database».
5. **HTTPS** obbligatorio in produzione (Aruba include un certificato DV): senza HTTPS il browser non concede il microfono.

Punti da verificare sul piano scelto (non verificabili da qui):
- chiamate HTTPS in uscita da PHP verso i provider: usa «Prova la connessione»; se fallisce con «errore di rete» l'hosting le blocca e resta disponibile la modalità dimostrativa;
- tempo massimo delle richieste: ogni turno dell'agente ha un budget complessivo di 50 s (`LLM_TURN_BUDGET_S` in `config.php`); con l'hosting condiviso conviene impostare timeout 20–25 s e 0–1 nuovi tentativi nella configurazione del provider;
- sistemi di cache dell'hosting (su Aruba «HiSpeed Cache»): le API inviano `Cache-Control: no-store`; se l'accesso si comporta in modo anomalo, escludi il sito o la cartella dalla cache.

## Installazione su AlterVista

Possibile, con un limite importante: i modelli linguistici (Gemini e gli altri) si raggiungono solo se AlterVista consente le connessioni del sito verso l'esterno. Senza, ABLET funziona soltanto in modalità dimostrativa.

1. **Pannello AlterVista → Risorse → Modifica impostazioni PHP**: scegliere l'ultima versione di PHP disponibile (almeno 8.1) e sbloccare le **connessioni verso l'esterno** (server to server), senza limitarle ai domini predefiniti.
2. Attivare **HTTPS** per il sito, se non è già attivo: serve per installare l'app sul tablet e per usare il microfono.
3. Con il File Manager o via FTP creare la cartella **`ablet`** e caricarci il contenuto dell'archivio (le cartelle `public`, `src`, `database`, …). Usare una sottocartella: caricare il `.htaccess` di ABLET nella radice del sito sostituirebbe quello in cui AlterVista registra la versione di PHP.
4. Aprire `https://NOMESITO.altervista.org/ablet/install.php`:
   - host `localhost`, porta `3306`;
   - nome del database `my_NOMESITO` (è l'unico database dell'account e il nome non si cambia);
   - utente `NOMESITO`, password vuota.
5. Annotare **password del professionista** e **chiave di installazione** mostrate alla fine. La chiave (voce `INSTALL_KEY` in `config.php`) serve per aggiornare il database dopo aver caricato una nuova versione; non si usa la password del database, che su AlterVista è vuota.
6. Eliminare `public/install.php`.
7. Area del professionista → **Provider** → inserire la chiave del fornitore → **Prova la connessione**. Se la prova fallisce con un errore di rete o «host non consentito», le connessioni esterne non sono attive (punto 1).

Il database è dedicato all'account: ABLET deve essere l'unica applicazione installata su `my_NOMESITO`, oppure va usato un altro account.

## Installazione locale (sviluppo)

```bash
mysql -uroot -e "CREATE DATABASE ablet CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                 CREATE USER 'ablet'@'localhost' IDENTIFIED BY '<password>';
                 GRANT ALL ON ablet.* TO 'ablet'@'localhost';"
cp .env.example .env                 # compilare DB_PASS e APP_SECRET_KEY (comando indicato nel file)
php bin/migrate.php                  # crea lo schema
php database/seeds/demo.php          # dati sintetici: stampa UNA volta le credenziali generate
php -S 127.0.0.1:8080 -t public public/index.php
```

In alternativa a `.env` si può usare `config.php` (formato generato dall'installatore), che prevale su `.env`.
Primo professionista senza dati dimostrativi: `php bin/create_user.php psychologist <login>`.

## Interfaccia studente

Pensata per tablet e per l'interazione a voce (avatar che parla e ascolta, Pausa · Parla · Riascolta). Analisi dell'utente e scelte progettuali in `docs/INTERFACCIA_STUDENTE.md`. L'interfaccia del professionista è invariata.

- **App sul tablet**: installabile dal browser (pulsante «📲 Metti sul tablet» nella home dello studente; su iPad, istruzioni lette ad alta voce). Richiede HTTPS. Schermo acceso durante la sessione, ripresa dopo lo standby, pagina «Manca la connessione» senza rete; le chiamate `/api/` non vengono mai messe in cache.
- **Testi per l'ascolto**: ogni turno dell'agente riceve limiti di lunghezza per fascia d'età e regole di consegna a voce; il server ripulisce il testo per la sintesi vocale e chiede un solo nuovo tentativo se il messaggio è troppo lungo o ha troppe domande.

## Collegare un modello linguistico

Area del professionista → **Provider**: scegliere il fornitore (Gemini, OpenAI/ChatGPT, Anthropic, DeepSeek, endpoint compatibile), incollare la chiave, salvare, premere **Carica modelli** (l'elenco arriva dal fornitore: l'applicazione non contiene nomi di modelli), scegliere il modello, **Prova la connessione**, impostare come predefinito.
Gli adapter seguono le interfacce REST pubbliche dei fornitori; sono stati verificati contro un finto endpoint «chat completions», **non contro i servizi reali** (nessuna chiave disponibile in sviluppo): la prova di connessione è il primo controllo da fare.

## Verifica

```bash
bash tests/run_all.sh    # usa il database indicato in .env e lo AZZERA: usare un database di test, APP_ENV=development
```

- `tests/run.php` — 100 verifiche di dominio e persistenza.
- `tests/free_activity.php` — 28 verifiche della modalità libera senza database.
- `tests/run_free_activity.sh` — 28 verifiche di dominio e 31 HTTP della modalità libera (azzera il database di test).
- `tests/http.php` — 192 verifiche end-to-end via HTTP (accesso, isolamento, ciclo SRL, sicurezza, provider, dashboard, configuratore, autorizzazioni, conservazione, accordo fra codificatori, gruppi).
- Con trigger non disponibili, `tests/run.php` salta le 3 verifiche a livello di database (97 invece di 100).
- Contro un'installazione Apache: `APP=https://host/ablet MOCK=http://127.0.0.1:8102 DB_NAME=… DB_USER=… DB_PASS=… php tests/http.php` (database di test).

## Struttura

```
bin/                    migrate.php, create_user.php (riga di comando)
public/install.php      installatore dal browser (hosting senza SSH)
database/migrations/    001–009 · database/seeds/demo.php
public/index.php        front controller · public/assets/ interfaccia (app, student, psy, charts, voice)
resources/              templates (slide 16 e 17), codebook, indicatori, schema dell'output
src/Domain/             macchina a stati SRL, regole di adattamento, prompt a cinque livelli, convalide, calibrazione, tempi
src/Llm/                adapter (Demo, Gemini, OpenAI-compatibile, Anthropic), gateway, client HTTP
src/Service/            orchestratore del turno, viste di sessione, sicurezza, controllo dell'output, simulatore,
                        annotatore, polarità lessicale, clustering, indicatori
src/Http/               nucleo HTTP (sessione, CSRF, limiti), API studente e psicologo
docs/PROGETTO.md        assunzioni, architettura, specifica pedagogica, tracciabilità, limiti
```

## Limiti noti

- **Provider reali non provati**: adapter scritti sulle interfacce pubbliche, da verificare con chiavi vere. Output strutturato richiesto in «modalità JSON» (Gemini, OpenAI, DeepSeek) o via prompt (Anthropic); la conformità allo schema è sempre verificata dal server.
- **Voce**: usa le API vocali del browser (riconoscimento e sintesi). Disponibilità e qualità dipendono da browser e dispositivo; il riconoscimento può passare dai server del produttore del browser (l'interfaccia lo dichiara). È disponibile anche il motore server descritto nel manuale, con configurazione e autorizzazione dedicate. Il flusso a voce è verificato con riconoscimento e sintesi simulati; da provare su iPad e tablet Android reali.
- **Nessuno streaming**: le risposte compaiono solo dopo i controlli; con modelli lenti l'attesa è visibile.
- **Controlli sull'agente**: regole di piattaforma, convalida dello schema, controlli deterministici (copia estesa del materiale, etichette diagnostiche, identità umana, promesse di intervento) e rete lessicale di sicurezza riducono il rischio ma non garantiscono l'assenza di risposte improprie. Il server non può stabilire se un messaggio «contiene la soluzione» né se un'affermazione disciplinare è corretta.
- **Modalità dimostrativa**: l'agente simulato non comprende il contenuto; serve a provare il flusso.
- **Analisi testuale**: annotatore lessicale a incertezza dichiarata «alta»; annotatore tramite modello non provato su provider reali. Polarità lessicale su lessico interno ridotto e non validato. Clustering esplorativo.
- **Conservazione**: cancellazione automatica al più una volta al giorno all'accesso del professionista (nessun cron); se nessun professionista accede, la cancellazione attende il primo accesso. Il registro di audit non viene cancellato automaticamente.
- **Autorizzazioni**: ABLET registra le autorizzazioni raccolte fuori dalla piattaforma (es. modulo firmato) e, se richiesto, ne subordina l'uso; non raccoglie firme e non stabilisce quali autorizzazioni servano. Il modello di informativa è una bozza da adattare con il titolare del trattamento.
- **Accordo fra codificatori**: kappa di Cohen per codice senza intervalli di confidenza; per più di due codificatori o per la pubblicazione esportare i dati e usare un software statistico.
- **App installabile**: verificata con Chrome (manifest, service worker, installabilità, pagina senza rete) e con un iPad simulato; da provare su iPad e tablet Android reali. Su iPad lo schermo sempre acceso dipende dalla versione di iPadOS (Safari supporta il Wake Lock dalla 16.4, ma nelle app aggiunte alla Home ha funzionato in modo irregolare in alcune versioni): da verificare sul dispositivo; in mancanza, vale il blocco automatico impostato sul tablet.
- **Testi per l'ascolto**: i limiti per fascia d'età sono parametri di partenza; il rispetto da parte di un modello reale non è stato provato.
- **MySQL 5.6** (es. Aruba Hosting Linux): verificato simulando lo schema 5.6 su MySQL 8 con righe in formato COMPACT (limite di 767 byte per gli indici), non su un server 5.6 reale. Con materiali molto lunghi e formato COMPACT è possibile l'errore «Row size too large»: in quel caso chiedere all'hosting il formato DYNAMIC o un database MySQL 8.
- **AlterVista**: installazione con password del database vuota verificata in locale; non verificato su un account AlterVista reale. Le chiamate verso l'esterno passano da un proxy del servizio e richiedono lo sblocco nel pannello; durata massima degli script e quote non verificate.
- Cancellazione di una richiesta in corso: implementata lato server, non verificata. 
- Verifica: MariaDB 10.11 e MySQL 8.0 (log binario attivo, utente senza privilegi amministrativi, come su hosting condiviso), Apache 2.4 con `.htaccess` in sottocartella, server integrato di PHP. Non verificato su un account Aruba reale.
