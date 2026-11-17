# Sito pubblico e account professionali — ABLET 0.9.0

## Homepage

La pagina iniziale senza autenticazione presenta l’applicazione: progetto educativo, ruolo di educatori e psicologi, tre fasi del Self-Regulated Learning, attività libere e programmi, strumenti di osservazione, funzionamento vocale, FAQ e informazioni sull’account. Riferimento teorico: Zimmerman (2002), DOI 10.1207/s15430421tip4102_2. La piattaforma non è presentata come trattamento validato né come strumento diagnostico.

In alto a destra: Accesso studente e Accesso educatore / psicologo. La registrazione è raggiungibile dai pulsanti della homepage e dalla pagina di accesso professionale. Il layout prevede adattamenti per schermi piccoli; la verifica automatica del DOM non sostituisce il collaudo visivo sui dispositivi.

Le rappresentazioni dell’interfaccia sono **anteprime illustrative con contenuti sintetici**, non screenshot di sessioni reali. Non sono state acquisite schermate del sito di produzione o di studenti.

## Registrazione e profilo

Percorso: `?registrazione=professionista`. Campi obbligatori: nome, professione educatore/psicologo, e-mail, password e conferma di lettura delle informazioni sull’account. Organizzazione e presentazione sono facoltative.

L’e-mail diventa il codice di accesso. Al termine l’utente entra nel proprio ambiente, con provider dimostrativo, rubriche e impostazioni iniziali. La professione dichiarata è salvata nel profilo; entrambi i tipi usano internamente il ruolo professionale esistente, con isolamento per proprietario. Non viene conferito un ruolo amministrativo. Gli studenti non possono autoregistrarsi.

Da Impostazioni applicazione → Il mio profilo si modificano nome, professione, organizzazione e presentazione. Il profilo è privato, non è una directory pubblica e non è incluso nel prompt della conversazione con lo studente. L’accesso al profilo usa l’identità della sessione, non un identificatore scelto dal client.

L’e-mail e la qualifica non sono verificate automaticamente. Non vengono inviate e-mail e non è presente un recupero password via e-mail: l’interfaccia lo dichiara. Per il lancio pubblico il titolare dell’installazione deve predisporre la propria informativa con contatti e condizioni applicabili; il testo tecnico sull’account non la sostituisce. La registrazione non include credito dei provider AI.

## Protezioni e configurazione

CSRF anche sulla registrazione, hash della password, validazione server, e-mail univoca, limite di cinque tentativi di registrazione per IP nell’ora e campo antispam. Password: almeno 12 caratteri e al massimo 72 byte per compatibilità con il backend di hash. Il limite per IP può essere condiviso da utenti dietro la stessa rete/proxy. Non sostituisce una verifica e-mail o un sistema completo antiautomazione.

Per disattivare la registrazione pubblica, mantenendo accessibili gli account già creati, aggiungere `ALLOW_SELF_REGISTRATION` con valore `0` in config.php o nell’ambiente. Valore predefinito: `1`. Il profilo e l’autenticazione esistenti continuano a funzionare.

## Aggiornamento da 0.8.7

1. Eseguire un backup del database e dei file.
2. Caricare il pacchetto preservando config.php, .env e storage/.
3. Aprire install.php → Aggiorna database: viene applicata **010_professional_profiles.sql**. Aggiunge una tabella senza cambiare login, password o ruolo degli account precedenti.
4. Eliminare nuovamente public/install.php e aprire refresh.php.
5. Provare registrazione, logout/accesso e modifica del profilo con un account di test. Gli account preesistenti possono completare il proprio profilo dal nuovo pannello.

Nessuna reinstallazione e nessun azzeramento del database reale.

## Verifiche

- 17 verifiche API specifiche: CSRF, password breve, conferma, professione non valida, ruolo tecnico non amministrativo, hash, profilo privato, spazio iniziale, isolamento studenti, modifica persistita, autenticazione, duplicati, limite IP, secondo professionista, isolamento del profilo e logout/accesso.
- 15 verifiche DOM della homepage/accessi/registrazione, comprese sezioni SRL e dichiarazione delle anteprime illustrative.
- Suite principale eseguita: 100 dominio/persistenza, 28 attività libera, 209 HTTP e 21 affidabilità provider/audio; rieseguito lo stress locale senza provider reali.
- Regressioni del frontend studente, audio, UI professionista e cache rieseguite.
- Suite specifica libera: 31 HTTP; budget/protocolli/WAV: 37 verifiche.
- Migrazione 009→010 verificata con quattro controlli: sola migrazione prevista, credenziali/ruoli preservati, nessun profilo preesistente inventato e riesecuzione senza modifiche.

Le prove non includono invio di e-mail, browser fisici o pubblicazione su Aruba. Il codice e il pacchetto aggiornati non modificano da soli il sito di produzione.

## Dashboard nella homepage — 0.9.1

La homepage presenta ora panoramica, filtri e dettaglio di sessione, distinguendo dati osservati, dichiarati e valutati. Spiega tempi, obiettivi, richieste d’aiuto, calibrazione, conversazione, codifiche, polarità lessicale e clustering esplorativo, con i limiti delle interpretazioni.

Due iframe sandbox mostrano pagine statiche generate dal codice effettivo di app.js, charts.js e psy.js: dashboard e dettaglio sessione. I dati provengono dai profili sintetici collaborativo, stallo e richiesta della soluzione. Non si accede a sessioni reali o a provider esterni. Nessuno script è incluso nelle anteprime; i comandi di modifica sono disattivati. Gli iframe sono caricati soltanto quando prossimi alla visualizzazione.

Non sono screenshot: il browser ha rifiutato l’accesso al file locale per l’acquisizione. Le anteprime non vengono spacciate per catture del sito in produzione. Le verifiche di struttura e sintassi sono passate, ma non è stato completato un controllo visivo nel browser di tali anteprime.

Verifica mirata: 18 controlli DOM su homepage/accessi, 28 UI professionista, 32 flusso studente, 34 audio browser, 18 audio server e 12 cache, tutti superati. Le pagine delle anteprime sono state controllate per assenza di script e presenza dell’etichetta dati sintetici. Nessuna nuova migrazione oltre la 010.
