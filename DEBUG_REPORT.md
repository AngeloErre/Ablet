# ABLET 0.15.1 — rapporto di debug

Data: 21 novembre 2026. Verifica sul codice distribuito, in ambiente locale isolato con dati sintetici. Nessuna modifica ai dati dell'installazione Aruba.

## Problemi riprodotti e risolti

1. **Voce nel workspace del Super admin:** le API vocali rifiutavano il ruolo admin pur consentendogli il workspace professionale. Ripristinato l'accesso e l'uso delle anteprime; restano i controlli specifici per gli studenti.
2. **Conversioni improprie dei dati:** array in login, password, messaggi, azioni, chiavi provider e audio potevano causare errori o diventare la stringa `Array`. Validazione esplicita e risposta 422; verificato che un messaggio improprio non venga salvato. I filtri query rifiutano strutture non scalari e byte nulli.
3. **Budget provider:** un limite superiore alla colonna SQL produceva errore 500 dopo l'azzeramento del predefinito. Ora si valida l'intervallo 0–4.294.967.295 anche nell'interfaccia. Salvataggio atomico con lock del professionista: un errore non lascia l'account senza predefinito; verificati otto salvataggi concorrenti.
4. **Filtri della dashboard:** date impossibili, formati non validi e intervalli invertiti erano ignorati o applicati in modo fuorviante. Ora vengono rifiutati; verificato anche un intervallo valido.
5. **Autoload delle eccezioni:** `ApiError`, definita solo in App.php, non era caricabile direttamente da un servizio CLI. Estratta nel file corrispondente al nome della classe e verificata senza caricare App in anticipo.
6. **Manutenzione delle verifiche:** aggiornati i test di migrazione rimasti fermi allo schema 012. Le suite che azzerano il database controllano la configurazione effettiva prima di procedere: ambiente development e nome database contenente `test`. Verificato il rifiuto su un nome non di test.

## Risultati conclusivi

| Verifica | Esito |
|---|---|
| Sintassi PHP | 85 file validi |
| Sintassi JavaScript | 9 file validi |
| Risorse JSON | 12 file validi |
| Dominio / macchina SRL | 99 controlli passati |
| Attività libera, unità | 28 passati |
| Protocolli, economia del prompt e audio, unità | 37 passati |
| Validazione e autoload | 7 passati |
| Monitor token | 19 passati |
| HTTP principale | 215 passati |
| Modifica attività e storico | 21 passati |
| Nuove regressioni del debug | 21 passati |
| Affidabilità provider simulati | 21 passati |
| Memoria longitudinale | 38 passati |
| Accesso PIN | 37 passati |
| Account professionali | 17 passati |
| Super admin | 38 passati |
| Recupero password e concorrenza | 35 passati |
| Attività libera, percorso HTTP dedicato | 31 passati |
| Aggiornamenti da schemi 009, 010 e 011 | 4 + 6 + 6 controlli passati |
| Aggiornamento 013 → 014 | Migrazione applicata, dati conservati, seconda esecuzione idempotente |
| Interfacce DOM simulate | 249 controlli in 9 suite, tutti passati |
| Carico locale | 15 controlli su 652 richieste, esiti attesi |

Le suite DOM coprono accesso (32), interfaccia professionale (32), wizard provider (65), flusso studente (32), memoria (12), admin (12), cache (12), voce browser (34) e voce server (18).

La prova di carico ha usato due worker PHP, concorrenza fino a 12 richieste, 51 sessioni aggiunte e oltre 1.000 messaggi sintetici. Verificati invii duplicati, conservazione dei messaggi distinti, risposte 429 controllate, payload troppo grandi, JSON malformato e isolamento dei ruoli. Questi risultati non stimano la capacità dell'hosting Aruba. Le suite conclusive non hanno mostrato avvisi PHP o eccezioni inattese nel log del server applicativo.

## Limiti della verifica

- Database locale MariaDB con schema LONGTEXT in modalità di compatibilità MySQL 5.6; nessuna esecuzione su un server MySQL 5.6 originale.
- Provider, consegna e-mail e audio verificati con servizi o API del browser simulati: non è stata verificata una conversazione reale Gemini/OpenAI/Claude/DeepSeek, né l'effettiva consegna della posta.
- Le prove DOM non sostituiscono il controllo visivo su smartphone/tablet, né microfono, TTS e permessi dei browser reali.
- Non verificati in produzione Apache/.htaccess, TLS, quote del database, timeout e configurazione del singolo hosting Aruba. Nessun test di durata prolungata.
- Un esito positivo delle prove non garantisce l'assenza di ogni possibile difetto né costituisce validazione scientifica del modello pedagogico.

## Installazione e ripetibilità

Conservare `config.php`, `.env` e `storage/`, caricare il pacchetto e aprire `refresh.php`. Non ci sono nuove migrazioni rispetto a 0.15.0; da versioni precedenti applicare quelle mancanti fino alla 014 tramite installatore protetto o `php bin/migrate.php`. Non usare `--fresh` su dati reali.

Su un database dedicato ai test: `bash tests/run_all.sh`; con carico: `ABLET_STRESS=1 bash tests/run_all.sh`. Il percorso libero separato usa `bash tests/run_free_activity.sh`. Le suite `tests/upgrade_*.php` azzerano il database di test: eseguirle separatamente e ricreare le fixture prima delle prove HTTP. Per il frontend: `node tests/<nome>.cjs`. Nuovi file: `tests/debug_regressions.php` e `tests/input_validation.php`.
