# ABLET — Adaptive Based Learning Enhancer Tool

Piattaforma web in italiano per sostenere l'autoregolazione dell'apprendimento con un agente pedagogico conversazionale.
Committente scientifico: Prof. Angelo Rega.

**Stato: MVP con modalità Attività libera (aggiornamento 0.5).** Frontend HTML/CSS/JavaScript senza dipendenze, backend PHP, database MySQL/MariaDB.

> La riuscita tecnica dell'MVP non equivale alla sua validazione scientifica: soglie, livelli di supporto, codebook e lessico di polarità sono parametri progettuali da validare.

## Versione 0.5 — attività assegnate, protocolli, voce e manuale

- **Assegna attività**: scegli studente reale, attività e versione pubblicata. Disponibile anche dalle schede Studenti e Attività. Le nuove attività dell’interfaccia sono riservate agli assegnatari per impostazione iniziale. Le precedenti restano visibili come prima, finché non viene fatta un’assegnazione esplicita.
- **Protocolli più chiari**: tre sezioni SRL separate, guida ai processi e pannelli avanzati richiudibili. Cinque originali di base protetti; è possibile creare copie personali modificabili.
- **Eliminazione reversibile**: anteprima dell’impatto, archiviazione e revoca delle assegnazioni. Dati e versioni delle sessioni già create sono conservati. Il ripristino non riattiva automaticamente le assegnazioni.
- **Prompt economico**: rappresentazione compatta, cronologia recente, stime di token/costi per provider e modello selezionati. Sono disponibili tariffe note per alcuni modelli Gemini/OpenAI; altrimenti occorre inserire tariffe verificate. Il limite completo del turno viene controllato prima dell’invio con una stima prudenziale, non un tokenizer ufficiale.
- **Voce server alternativa**: sintesi e trascrizione tramite Gemini o API audio OpenAI compatibili, prova di sintesi e microfono, diagnostica. Richiede provider e modelli audio configurati e autorizzazione dedicata per ciascuno studente. Nessuna archiviazione permanente dell’audio in ABLET. Le condizioni del provider restano applicabili.
- **Manuale e tutorial**: pagina `manual.php`, raggiungibile dalla scheda **Manuale e tutorial**, con procedure, tre fasi, esempio completo, gestione degli studenti e risoluzione dei problemi.

### Aggiornare dalla versione precedente

Fare un backup, caricare i file conservando `config.php`, l’eventuale `.env` e `storage/`, poi aprire `install.php` e scegliere **Aggiorna il database**. Sono necessarie le migrazioni **007_assignments.sql** e **008_audio_consent.sql**. Eliminare nuovamente `public/install.php` e usare `refresh.php` nel dispositivo interessato. Non azzerare o reinstallare il database.

Le credenziali esistenti restano valide. Home con accessi distinti `?accesso=studente` e `?accesso=docente`; il link non cambia il ruolo dell’account.

### Verifica 0.5

- PHP 8.4 + MariaDB 11.8 in ambiente isolato: 100 controlli di dominio/persistenza, 28 di attività libera e **165 HTTP**, tutti superati.
- `php tests/features_v05.php`: **33** controlli su cinque originali, fasi SRL preservate, economia, tariffe, limiti e WAV.
- `node tests/voice.cjs`: **22** controlli del motore browser; `node tests/voice_server.cjs`: **15** controlli del ciclo di registrazione/riproduzione server.
- `node tests/login_home.cjs`: **10** controlli accessi; `node tests/ui_v05.cjs`: **9** controlli dei nuovi pannelli con DOM simulato; `node tests/cache_refresh.cjs`: **12** controlli cache.

I test audio e provider sono simulati: non attestano l’udibilità su un dispositivo reale né la disponibilità di un modello per una chiave. Provare entrambi i pulsanti vocali sul dispositivo e con il provider scelto. Le prove di rendering DOM non equivalgono a una verifica visiva su tablet. Il successo tecnico non equivale a validazione scientifica.

## Aggiornamento e pulizia della cache

Disponibile il comando **Aggiorna app e svuota cache** nella barra del professionista e nelle impostazioni dello studente. Se l'interfaccia è ancora vecchia, aprire direttamente `PERCORSO_DI_ABLET/refresh.php` e premere il pulsante. La pagina funziona indipendentemente dal JavaScript già in cache.

La pulizia riguarda solo i file nella cache di questa installazione e il suo service worker: non cancella cookie, preferenze, account, protocolli, attività o sessioni nel database. Prima di usarla, salvare eventuali modifiche non ancora inviate. Va eseguita nel browser/dispositivo che mostra il problema. Non svuota le cache del servizio di hosting o di una CDN.

Il caricamento usa ora una versione comune basata sul contenuto di tutti i moduli JavaScript e del CSS, una import map protetta da nonce e la rivalidazione dalla rete prima del fallback offline. Test dedicati: `node tests/cache_refresh.cjs` (12 verifiche). Questa modifica non aggiunge migrazioni del database.

## Novità: Attività libera

Lo studente sceglie e descrive il compito nel dialogo e stima il tempo totale oppure i tempi dei micro-obiettivi. L'agente usa le conoscenze del modello per guidare il lavoro **sempre secondo il protocollo SRL pubblicato assegnato dallo psicologo**. Tempi disponibili e durate stimate rimangono separati. Compiti e materiali preimpostati restano disponibili.

**Per aggiornare un sito già installato:** caricare i file conservando `config.php`, `.env` e `storage/`, poi aprire l'installatore e scegliere **Aggiorna il database** (migrazioni mancanti fino alla 008); non reinstallare o azzerare lo schema. Istruzioni complete e limiti in [docs/ATTIVITA_LIBERA.md](docs/ATTIVITA_LIBERA.md).

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
- `tests/http.php` — 165 verifiche end-to-end via HTTP (accesso, isolamento, ciclo SRL, sicurezza, provider, dashboard, configuratore, autorizzazioni, conservazione, accordo fra codificatori, gruppi).
- Con trigger non disponibili, `tests/run.php` salta le 3 verifiche a livello di database (97 invece di 100).
- Contro un'installazione Apache: `APP=https://host/ablet MOCK=http://127.0.0.1:8102 DB_NAME=… DB_USER=… DB_PASS=… php tests/http.php` (database di test).

## Struttura

```
bin/                    migrate.php, create_user.php (riga di comando)
public/install.php      installatore dal browser (hosting senza SSH)
database/migrations/    001–008 · database/seeds/demo.php
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
