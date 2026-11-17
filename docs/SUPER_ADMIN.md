# Super admin — ABLET 0.10.0

## Aggiornamento e prima attivazione

1. Fare un backup di file e database. Caricare il pacchetto mantenendo config.php, .env e storage/.
2. Aprire install.php → Aggiorna database. La migrazione **011_super_admin.sql** aggiunge il contatore di revoca delle sessioni e le tabelle editoriali. Se mancano migrazioni precedenti, l’installatore applica anche quelle. Non reinstallare o azzerare.
3. Eliminare nuovamente public/install.php e aprire refresh.php.
4. Accedere con il proprio account professionale. Aprire **Impostazioni applicazione → Il mio profilo → Attivazione del primo super admin**.
5. Inserire la chiave **INSTALL_KEY** conservata in config.php e premere Attiva. Nelle installazioni precedenti prive di INSTALL_KEY, il controllo di titolarità usa la password del database non vuota, come l’installatore.

L’attivazione richiede un account professionale autenticato, CSRF valido e la chiave privata dell’installazione. È disponibile solo quando non esiste alcun utente con ruolo admin. Il controllo è serializzato nel database per evitare attivazioni concorrenti. Nessun professionista viene promosso automaticamente e la registrazione pubblica non può scegliere il ruolo admin.

Le credenziali dell’account attivato rimangono le stesse. Per gli accessi successivi utilizzare **Super admin** nel footer della homepage, oppure `?accesso=admin`. L’amministratore mantiene l’accesso al proprio spazio professionale tramite il collegamento dedicato nell’area admin. I dati del proprio spazio non vengono trasferiti ad altri utenti.

Alternativa con SSH: `php bin/create_user.php admin <login> [alias]`. Crea un account con password casuale mostrata una sola volta. È un comando per il gestore del server, non una registrazione pubblica. Gli eventuali account admin già esistenti usano il nuovo accesso dedicato senza necessità di una nuova promozione.

Non pubblicare config.php o la chiave di installazione e non inserirla nella homepage.

## Gestione dei professionisti

La sezione Professionisti mostra account educatori/psicologi, stato, organizzazione e numero di studenti collegati. Offre ricerca per nome/codice ed elenco paginato di 25 account. Non mostra conversazioni o schede individuali degli studenti altrui.

- **Crea professionista:** nome, codice di accesso/e-mail, professione dichiarata e presentazione. La password iniziale casuale viene mostrata solo nella risposta di creazione; non è inviata via e-mail. Lo spazio comprende provider demo, rubriche e impostazioni iniziali.
- **Modifica:** aggiorna profilo, codice di accesso e stato. Le qualifiche restano autodichiarate.
- **Sospendi / riattiva:** la sospensione blocca l’accesso del professionista e invalida le sue sessioni precedenti. La riattivazione richiede un nuovo accesso e non riabilita i vecchi cookie. Nessun dato educativo viene cancellato.
- **Reimposta password:** dopo conferma nell’interfaccia genera una nuova password e invalida le sessioni precedenti. La nuova password appare una volta sola nel pannello; i log non la contengono.

La sospensione riguarda l’account professionale: **gli account studente non vengono sospesi automaticamente**. Le richieste già avviate prima della revoca possono terminare; i controlli si applicano alle richieste successive. Non è prevista cancellazione fisica degli account o dei dati da questo pannello.

Gli account admin sono esclusi dalle modifiche del pannello professionisti: non si può sospendere o declassare accidentalmente il proprio amministratore da questa lista.

## Testi e immagini della homepage

Il catalogo è ricavato dal renderer pubblico e comprende 141 testi unici. Il filtro consente di trovare un testo; la modifica si applica alle sue occorrenze nella homepage. I testi sono semplici stringhe: eventuali tag HTML sono visualizzati come testo, non eseguiti. Le destinazioni dei link e la struttura dell’app non si modificano attraverso il catalogo. Per gli sviluppatori: rigenerarlo con `node tests/export_home_content.cjs` dopo aver cambiato i testi originali.

Tre slot gestiscono l’immagine iniziale, la dashboard e l’analisi/conversazione. Sono ammessi PNG, JPEG e WebP, massimo 2 MB, 4096 pixel per lato e 10 milioni di pixel totali. Il testo alternativo è obbligatorio; la didascalia è facoltativa. Non sono ammessi SVG o URL esterni. Usare immagini dimostrative prive di dati personali; i file vengono conservati come caricati e non è previsto uno strumento di rimozione dei metadati fotografici.

Le immagini sono archiviate in storage/site-media con nome derivato dal contenuto e servite da un endpoint con tipo MIME esplicito e protezione nosniff. Non si possono caricare script eseguibili. La cartella storage resta negata all’accesso diretto secondo la configurazione Apache dell’app. Deve essere scrivibile dal processo PHP. Le immagini sostituite restano archiviate per consentire il ripristino delle versioni.

### Flusso editoriale

1. Modificare i testi oppure caricare un’immagine, impostando testo alternativo e didascalia.
2. **Salva bozza:** registra la modifica senza cambiare la homepage dei visitatori.
3. **Salva e apri anteprima:** mostra la bozza solo alla sessione autenticata dell’admin.
4. **Pubblica homepage:** salva prima la bozza corrente e la rende pubblica. I visitatori vedono i nuovi contenuti al caricamento della pagina.
5. Lo storico mostra le ultime 20 pubblicazioni. **Ripristina in bozza** recupera una versione senza pubblicarla automaticamente.
6. **Prepara bozza originale** permette di ripartire dai testi e dalle anteprime originali. Occorre pubblicare per applicare il ripristino al sito.

Il server verifica il numero di revisione: se due richieste tentano di modificare una versione non più corrente, restituisce un conflitto anziché sovrascrivere silenziosamente. I passaggi di pubblicazione e ripristino sono registrati nell’audit. Il pannello avverte prima di cambiare sezione con modifiche editoriali non salvate; è comunque opportuno salvare prima di uscire dall’account o chiudere la pagina.

## Verifiche

- 35 verifiche API dedicate: autenticazione/ruoli, chiave titolare, attivazione unica, accesso admin e spazio professionale, creazione, ricerca, CSRF, sospensione, riattivazione, cookie conservato durante la sospensione, reset password, catalogo, bozze/pubblicato, conflitti, versioni e immagini.
- 8 verifiche UI/CMS: area separata, collegamenti, editor e slot, testo trattato senza HTML e catalogo univoco.
- Rieseguite le suite di dominio/persistenza (100), attività libera (28), API complete (209), provider/audio (21), account (17), UI professionista (28), home/accessi (21), studente (32), audio browser (34), audio server (18) e cache (12).
- Suite HTTP libera (31), budget/protocolli/WAV (37), aggiornamento account 009→011 (4 controlli) e migrazione specifica 010→011 (6 controlli).

Le verifiche usano database e account sintetici isolati, senza modificare il sito reale. Il collaudo visivo e la verifica della scrittura dei file sull’hosting devono essere completati sull’installazione di destinazione. I test distruttivi di migrazione azzerano il database configurato e non vanno eseguiti sul database reale.
