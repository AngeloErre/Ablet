# ABLET — Attività libera

Aggiornamento 0.6 · 16 novembre 2026

## Se non compare il menu Modalità

Dopo aver caricato l'aggiornamento, aprire `PERCORSO_DI_ABLET/refresh.php` nel browser che non mostra il menu e premere **Aggiorna app e svuota cache**. La pagina non dipende dall'interfaccia già memorizzata. Il comando è disponibile anche nella barra del professionista e nelle impostazioni dello studente.

Non vengono cancellati studenti, protocolli, sessioni, credenziali o preferenze. Salvare prima i testi non ancora inviati. Se il problema persiste su tutti i dispositivi, verificare che i file siano stati caricati nella cartella giusta ed eventualmente la cache dell'hosting: questo comando agisce solo sul browser.

## Attivazione da parte dello psicologo

1. Aprire **Attività → Nuova attività**.
2. Scegliere **Attività libera: compito scelto dallo studente**, dare un titolo (per esempio «Scegli tu il compito») e premere **Crea attività**.
3. Aprire **Studenti → scheda dello studente → Programma sessioni · attività e protocolli**. Scegliere una sessione singola o una sequenza; per ogni riga selezionare attività e versione pubblicata. Le nuove attività sono inizialmente visibili soltanto agli studenti assegnati. Il protocollo generale resta disponibile per le attività con la visibilità precedente.
4. Lo studente trova la nuova modalità nella propria schermata iniziale. L'attività libera non richiede consegna, materiali, criteri o durata preimpostati dal professionista. È sempre un'attività con agente.

Il titolo dato dallo psicologo identifica l'accesso alla modalità, non il compito scolastico. Basta creare questa attività una volta: lo studente può iniziare più compiti distinti e riprendere le sessioni in corso.

## Esperienza dello studente

Lo studente comunica il compito attraverso il dialogo, a voce o per iscritto. L'agente chiarisce progressivamente obiettivo, risultato atteso e pianificazione, secondo il protocollo assegnato. Lo studente può dichiarare una stima totale, oppure i tempi di tutti i micro-obiettivi. **Il mio piano** permette anche di inserire o correggere direttamente il totale, il tempo disponibile e la stima di ogni passo durante la pianificazione.

Esempio: «Devo studiare le frazioni. Penso che serviranno 30 minuti» è una stima della durata; «Ho 30 minuti prima di uscire» indica invece una disponibilità. Se il significato non è chiaro, l'agente deve chiedere una precisazione.

Quando tutti i passi hanno una stima, il server calcola il totale derivato. La somma non viene trasformata in una stima totale dichiarata dallo studente. Una somma parziale non viene presentata come totale completo. Le discrepanze tra totale e passi, o il superamento del tempo disponibile, vengono segnalati per rivedere il piano, senza modificare automaticamente i valori.

Le stime di ogni passo possono essere corrette anche nel dialogo: il modello può proporre una nuova durata ripetendo la descrizione esatta di un passo ancora in pianificazione, con evidenza del messaggio dello studente. La comprensione della frase e la corrispondenza dell'evidenza rimangono dipendenti dal modello.

## Guida pedagogica

La modalità libera mantiene il ciclo **forethought → performance → self-reflection**, con ritorni alla pianificazione, pause e chiusura confermata dallo studente. Non introduce un protocollo generico alternativo.

- L'obiettivo psicoeducativo, le strategie, il feedback, i livelli di scaffolding, le regole di adattamento e i limiti sono quelli della versione del protocollo professionale fissata alla sessione.
- Senza un protocollo pubblicato assegnato, non è possibile avviare una sessione libera. Restano applicati i controlli di accesso e le autorizzazioni già previsti dalla piattaforma.
- L'agente usa le conoscenze del modello per formulare domande, indizi graduati ed esempi analoghi al livello dello studente. Il supporto disciplinare serve alle strategie SRL del protocollo, senza fornire la soluzione del compito o elaborati pronti.
- L'assenza di materiali non blocca la guida. Il modello non deve fingere di conoscere il libro, le pagine assegnate o le richieste specifiche dell'insegnante; non dispone di ricerca web aggiuntiva.
- I contenuti del modello restano non verificati. Le istruzioni richiedono di dichiarare l'assenza del materiale al primo uso e segnalare le incertezze rilevanti.
- Le proposte dell'agente non devono diventare stime o micro-obiettivi dello studente senza sua conferma. La piattaforma verifica la presenza e l'appartenenza delle evidenze, ma non può garantire automaticamente che l'inferenza linguistica sia corretta.
- La riflessione confronta risultati, strategie, stime e tempi registrati. Il tempo nell'app non costituisce da solo una misura del tempo effettivo di studio.

## Tracciabilità per il professionista

Le sessioni e le esportazioni distinguono modalità libera/preimpostata, compito scelto, stima totale dichiarata, somma completa dei tempi dei passi e tempo disponibile. La scheda della sessione riporta il compito scelto e i tempi separati. La cronologia dello studente conserva il nome del suo compito.

Il prompt pubblicato e il suo hash restano invariati: al turno delle sole attività libere viene aggiunto un contesto operativo fisso che vincola esplicitamente l'agente al protocollo. I metadati del messaggio riportano `activity_mode` e `content_source`; quest'ultimo segnala l'uso delle conoscenze del modello. L'hash identifica la versione del protocollo, non l'intero contesto dinamico del turno.

## Aggiornare un'installazione esistente

1. Effettuare un backup dei file e del database del sito.
2. Caricare i file aggiornati dell'archivio nella stessa cartella dell'installazione. **Conservare il proprio `config.php`, l'eventuale `.env` e la cartella `storage/`**. L'archivio non contiene credenziali né configurazione dell'installazione di sviluppo.
3. Se l'installatore era stato eliminato, ricaricare anche `public/install.php` dall'archivio.
4. Aprire `https://TUO-SITO/PERCORSO/install.php`, inserire la propria chiave di installazione (`INSTALL_KEY` in `config.php`) e scegliere **Aggiorna il database**. In alternativa, da CLI: `php bin/migrate.php`.
5. La migrazione `006_free_activity.sql` aggiunge la modalità; la `007_assignments.sql` aggiunge le assegnazioni reali e la `008_audio_consent.sql` l’autorizzazione audio server e la `009_session_plans.sql` la programmazione sequenziale. Le attività già esistenti mantengono modalità e visibilità precedenti. Non ripubblicare i protocolli e non reinstallare il database.
6. Eliminare nuovamente `public/install.php` e ricaricare l'app. Se l'app era già aperta/installata sul tablet, chiuderla e riaprirla per acquisire l'aggiornamento dei file statici.
7. Creare l'attività libera e provarla con lo stesso provider reale già configurato sul sito.

**Non eseguire `tests/run_all.sh`, `tests/run_free_activity.sh` o `bin/migrate.php --fresh` sul database del sito**: i comandi di test azzerano lo schema configurato.

## Verifiche dell'aggiornamento

Eseguiti su ambiente di test isolato con PHP 8.4 e MariaDB 11.8:

- Controllo della sintassi di tutti i file PHP e JavaScript.
- Suite preesistenti: **100 verifiche di dominio/persistenza e 134 verifiche HTTP superate**.
- Nuova suite `php tests/free_activity.php`: **28 verifiche superate**, senza database, su requisiti SRL, stime, somma dei passi, disponibilità, prompt e convalida dell'output.
- Nuova suite `bash tests/run_free_activity.sh`: include le precedenti 28 verifiche e **31 nuove verifiche HTTP superate**, su creazione, assegnazione del protocollo, isolamento dei dati, stime, dashboard, richieste a un provider simulato e ciclo della sessione.

I test del provider usano un endpoint simulato: verificano i dati inviati e i controlli del server, non la qualità pedagogica o la correttezza disciplinare di un modello reale. La sintassi dell'interfaccia è verificata; il test visivo con Chromium non è stato eseguibile nell'ambiente, quindi restano da provare layout, voce e uso su tablet reale. Questo aggiornamento non equivale a validazione scientifica.
