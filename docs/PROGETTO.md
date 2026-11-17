> Aggiornamento 0.6 (16/11/2026): questo documento descrive l’architettura iniziale. Per programmazione di sessioni singole e sequenze dalla scheda studente, impostazioni raggruppate, archiviazione, cinque modelli protetti, budget del prompt e motore audio server consultare il README aggiornato e `public/manual.php` (pagina Manuale e tutorial).

# ABLET — Documento di progetto (MVP, incrementi 1–7)

Versione 0.1 · 14 novembre 2026

## 1. Fonti, assunzioni e scelte

### 1.1 Requisiti ricavati dalle slide (S) e proposte progettuali (P)

| Elemento | Origine |
|---|---|
| Ciclo a tre fasi, funzioni dell'agente per fase | S9 |
| Un passo per turno, riformulazione della consegna, strategia alternativa dopo n tentativi, confronto stima/tempo reale | S7 |
| Sei domande metacognitive | S8 |
| Segmentazione, fading, inversione dell'expertise | S10 |
| Tre condizioni dell'adattività: misura, modello dello studente, regola | S11 |
| Indicatori: latenza di avvio, % micro-obiettivi, scarto stima/reale, solleciti, autoefficacia pre-post; calibrazione sicurezza/prestazione | S12, S13 |
| Nove campi del prompt; obbligatorietà di limiti (8) ed escalation (9) | S15 |
| Procedura in 8 passi; regola «due non completati / due completati» | S16 |
| Procedura in 6 passi; riduzione del blocco e indizio; sintassi semplificata, concetti intatti | S17 |
| Macchina a stati con attori e guardie; stati `paused`, `safety_hold`, chiusure distinte | P |
| Livelli di supporto 0–3, ampiezza del passo 1–5, soglie di fading (4 e 3 passi senza indizi), regola di ripristino, anti-ripetizione | P |
| Fading come **proposta** da concordare con lo studente, non come cambiamento automatico | P |
| Codebook a 12 codici (gli 11 richiesti + reazione emotiva autoriferita) | P |
| Output strutturato, cinque livelli del prompt, sigillo dei contenuti non attendibili | P |

Nomi: le slide sciolgono ABLET come «Agent-Based Learning Enhancement Tools»; la piattaforma adotta la denominazione del brief, «Adaptive Based Learning Enhancer Tool».
Dove la slide 9 indica «guida l'attribuzione alla strategia, non all'abilità», la piattaforma applica il vincolo più prudente del brief: nessuna attribuzione automatica, né all'abilità né all'impegno; l'attribuzione è esplorata con lo studente.

### 1.2 Verifica delle affermazioni delle slide

| Affermazione | Esito |
|---|---|
| Bastani et al. (2025), PNAS 122(26), e2422633122: circa 1.000 studenti, +48% in pratica, −17% alla verifica senza assistenza | **Confermata** sull'abstract. Precisazione: per la versione con vincoli pedagogici l'abstract parla di effetti negativi «largely mitigated», non di scomparsa; in pratica quella versione ottiene +127%. |
| Bloom (1984), VanLehn (2011), meta-analisi sui chatbot (g ≈ 0,5) | Non riverificate in questo incremento; non riprodotte nel software. |
| AI Act, «Reg. (UE) 2026/1744», date di applicazione, L. 132/2025 (slide 22) | **Non verificate** e non riprodotte: il software non contiene affermazioni normative. Da controllare sulle fonti ufficiali prima di qualsiasi informativa. |
| Zimmerman, Flavell, Nelson & Narens, Miyake, Diamond, Sweller | Riferimenti teorici consolidati, usati come cornice; nessuna grandezza numerica derivata. |

### 1.3 Assunzioni

1. «Dati di integrazione» = integrazione fra tracce comportamentali (eventi), contenuto delle interazioni (annotazioni) e autovalutazioni. Le tre fonti restano in tabelle distinte e sono affiancate, mai fuse in un punteggio.
2. Lo studente accede con un codice, non con e-mail; il sistema non conserva dati anagrafici.
3. Il profilo funzionale non ha un campo diagnosi; i termini diagnostici nel testo libero producono un avviso.
4. Numero di emergenza indicato dall'agente: 112 (da confermare nel protocollo di escalation del servizio).
5. Le pagine ufficiali di documentazione dei provider non erano consultabili direttamente dall'ambiente di sviluppo: endpoint, intestazioni e campi sono stati riscontrati tramite ricerca; nessun nome di modello è codificato (l'elenco è letto dal fornitore) e nessun adapter è stato provato contro il servizio reale.

## 2. Architettura

```
Browser (HTML/CSS/JS)                Server PHP                                   MySQL
┌──────────────────────┐   HTTPS    ┌────────────────────────────────────────┐
│ Studente             │──────────► │ Front controller · sessione · CSRF ·   │
│ Psicologo            │ ◄────────  │ controllo accessi · rate limit         │
└──────────────────────┘ solo       ├────────────────────────────────────────┤
                         message    │ Orchestratore del turno                │
                         + phase    │  1 carica stato (FOR UPDATE)           │
                                    │  2 PromptCompiler.buildTurn            │──► sessions, messages
                                    │  3 Adapter provider                    │──► LLM (solo dal backend)
                                    │  4 OutputValidator                     │
                                    │  5 StateMachine.apply                  │──► state_transitions
                                    │  6 RuleEngine.evaluate                 │──► adaptations, student_models
                                    │  7 publicView → frontend               │──► events (append-only)
                                    └────────────────────────────────────────┘
```

Principi:

- **Il server decide lo stato.** L'LLM propone (`proposed_action`, `proposed_state_update`); `StateMachine` e `OutputValidator` accettano o rifiutano. Un rifiuto è registrato come evento.
- **Cinque livelli separati**: regole di piattaforma → protocollo → stato → materiali → messaggi. I primi due sono compilati e sottoposti ad hash alla pubblicazione; materiali e messaggi sono sigillati come dati.
- **Registro eventi append-only** come fonte unica di timer e indicatori, con orario del server e chiave di idempotenza del client.
- **Quattro classi di dato mai confuse**: osservato (`events`), autovalutato (`self_assessments`), inferito dall'LLM (`messages.llm_meta`, mai usato da regole o indicatori), valutato dal professionista (`professional_ratings`, `professional_notes`).
- **Immutabilità**: trigger su `protocol_versions` (pubblicate) e su `sessions.protocol_version_id/prompt_hash`.

## 3. Specifica pedagogica

### 3.1 Macchina a stati

Stati: `created`, `forethought`, `performance`, `self_reflection`, `paused`, `safety_hold`, `closed_complete`, `closed_incomplete`.

| Azione | Da → A | Attori ammessi | Guardia |
|---|---|---|---|
| `start` | created → forethought | studente, sistema | — |
| `begin_performance` | forethought → performance | studente, **agente** | consegna dichiarata + slot richiesti dal protocollo + numero minimo di micro-obiettivi (+ riformulazione se prevista) |
| `return_to_planning` | performance / self_reflection → forethought | studente, **agente** | — |
| `begin_reflection` | performance → self_reflection | studente, **agente** | — |
| `pause` / `resume` | fase ↔ paused | studente (pausa anche sistema) | ripresa nella fase memorizzata |
| `close_complete` | self_reflection → closed_complete | **solo studente** | — |
| `close_incomplete` | qualsiasi stato aperto → closed_incomplete | studente, professionista, sistema | — |
| `safety_hold` | fase / paused → safety_hold | agente, sistema, professionista | — |
| `safety_release` | safety_hold → fase memorizzata | studente, professionista | — |

Gli slot di forethought (consegna, risultato atteso, criterio, conoscenze pregresse, difficoltà attesa, autoefficacia, strategia, tempo, riformulazione) sono facoltativi salvo quelli che il protocollo dichiara richiesti: la raccolta è progressiva, non un questionario.

### 3.2 Adattività

Indicatori calcolati dai soli eventi dello studente: passi consecutivi non completati / completati / completati senza indizi, richieste di indizio (passo, totale), difficoltà segnalate, tentativi falliti con la strategia corrente.

Regole = `quando (indicatore, operatore, soglia)` → `allora (azione)`. Vincoli del motore:

- tempo di risposta e numero di parole non possono essere l'unica condizione; la diagnosi non è un indicatore;
- al più una decisione per valutazione, variazione massima di un livello;
- nessuna nuova decisione senza evidenze successive all'ultimo adattamento (`min_new_events`);
- riduzione del supporto e aumento del passo sono **proposte** (richiedono l'accordo dello studente); aumento del supporto, riduzione del passo e ripristino sono applicati;
- la preferenza `lock_support_level` sospende i cambi di livello, registrando la sospensione;
- ogni decisione registra regola (con copia integrale), indicatori, id degli eventi di evidenza, stato precedente e nuovo.

Livelli: 3 guida strutturata · 2 guida condivisa · 1 supporto su richiesta · 0 pianificazione autonoma con checkpoint essenziali. Ripristino: dopo un fading applicato, due passi non completati riportano al livello precedente.
Le attività con `agent_enabled = 0` consentono di osservare il lavoro senza agente; il confronto è descrittivo e non dimostra il transfer.

### 3.3 Sicurezza conversazionale

`safety_status ∈ {ok, distress, emergency}`. Un valore diverso da `ok` senza `proposed_action = safety_hold` rende l'output invalido. In `safety_hold` la procedura didattica è sospesa; la sessione è evidenziata al professionista al suo accesso successivo. Nessun testo dell'interfaccia o dell'agente può presentare questa evidenza come monitoraggio continuo o intervento garantito. Disagio ed emergenza hanno messaggi fissi distinti, non generati dal modello; una rete lessicale indipendente dall'LLM intercetta le formulazioni esplicite.

## 4. Schema dati

28 tabelle, InnoDB, chiavi esterne, indici sui percorsi di lettura della dashboard.

- **Persone e relazioni**: `users` (pseudonimo, ruolo), `professional_students`, `student_groups`, `student_group_members`, `functional_profiles` (versionati), `consents`.
- **Protocolli**: `protocols`, `protocol_versions` (bozza/pubblicata, `config_json`, `compiled_prompt`, `prompt_hash`), `protocol_assignments` (studente **oppure** gruppo, vincolo CHECK).
- **Attività**: `activities` (`agent_enabled`), `materials` (`scan_flags`), `rubrics` (prestazione/osservazione, scala esplicita).
- **Sessioni**: `sessions` (stato, `state_version`, versione del protocollo, livello, passo, slot), `session_goals`, `messages` (`in_reply_to` per distinguere produzioni sollecitate), `events`, `state_transitions`.
- **Valutazioni**: `self_assessments`, `professional_ratings`, `professional_notes`.
- **Adattività**: `student_models`, `adaptations`.
- **Analisi testuale**: `codebook_versions`, `text_annotations` (append-only, `supersedes_id`, stato di revisione, incertezza/astensione, spontanea/sollecitata).
- **Sistema**: `provider_configs` (chiave cifrata, ultime 4 cifre, capacità, fallback esplicito), `professional_settings` (voce, avatar, conservazione), `audit_log`, `schema_migrations`.

## 5. Tracciabilità scenario → funzione → verifica

Verifiche in `tests/http.php` (end-to-end) salvo diversa indicazione.

| Scenario di accettazione | Funzione | Stato |
|---|---|---|
| Ciclo SRL completo | `TurnService`, `StateMachine`, `DemoAdapter` | Verificato (pianificazione via chat → lavoro → riflessione → chiusura dello studente) |
| Richiesta ripetuta della soluzione | `PlatformRules`, `SolutionRequest`, `DemoAdapter` | Verificato in modalità dimostrativa (3 richieste: rilevate, nessuna soluzione, piano invariato). Con LLM reali da osservare |
| Stallo → adattamento registrato | `RuleEngine`, `TurnService::adapt` | Verificato |
| Autonomia → ritiro graduale | idem + proposta allo studente | Verificato (proposta, rifiuto, accettazione, registro) |
| Interruzione e ripresa | `SessionRepository::transition` | Verificato (pausa, ricarica, ripresa; doppio invio; stato superato) |
| Voce o ripiego sul testo | `voice.js` | Parziale: controlli presenti e messaggi di errore previsti; riconoscimento non verificabile in ambiente automatico |
| Provider non disponibile | `Gateway`, `Http` | Verificato (messaggio di ripiego, stato salvato, nessun errore 500) |
| Output del modello invalido | `OutputValidator`, `OutputGuard` | Verificato (non JSON, evidenza estranea, copia del materiale) |
| Materiale con istruzioni malevole | `PromptCompiler`, `MaterialScanner` | Verificato sulla richiesta effettivamente inviata al provider (blocco sigillato, fuori dal livello di sistema) |
| Accesso ai dati di un altro utente | `StudentApi::resolve`, `PsyApi::own` | Verificato (studente↔studente, psicologo↔psicologo, ruoli incrociati) |
| Calibrazione non calcolabile | `Calibration`, `Indicators` | Verificato (non calcolabile → calcolata dopo valutazione con rubrica) |
| Correzione di una codifica | `AnnotationRepository::correct` | Verificato |
| Disagio ed emergenza | `SafetyNet`, `TurnService::hold` | Verificato (due flussi distinti, messaggi fissi, nessuna promessa di intervento) |
| Chiavi API | `Secrets`, `PsyApi::providerSave` | Verificato (cifrate, mascherate, assenti da risposte, audit e registro) |

## 6. Scelte progettuali aggiunte negli incrementi 2–7

- **Nessuno streaming**: il contenuto pedagogico è mostrato solo dopo convalida e controlli.
- **Un tentativo di correzione**: output invalido o bloccato → una nuova richiesta con l'errore indicato → altrimenti messaggio di ripiego non generato dal modello.
- **Proposte, non automatismi**: riduzione del supporto, aumento del passo e confronto di strategie sono proposti allo studente (accetta/rifiuta); aumento del supporto, riduzione del passo e ripristino sono applicati.
- **Pulsante «Sono bloccato»** = difficoltà segnalata + tentativo fallito con la strategia corrente (alimenta la regola sulla strategia alternativa).
- **Checkpoint**: ogni N passi (protocollo); raddoppiati al livello 1; al livello 0 solo avvio e chiusura.
- **Sollecitata/spontanea**: una produzione è «sollecitata» se risponde a un messaggio dell'agente che contiene una domanda (criterio strutturale).
- **Avatar** stilizzato (forma, volto, robot), mai realistico; la bocca si anima durante la sintesi vocale.
- **Modelli**: nessun nome di modello nel codice; l'elenco è letto dal fornitore.
- **Polarità lessicale** (ABLET-lex 0.1) al posto di una «sentiment analysis» interpretativa: conteggio su lessico interno con inversione per negazione; descrive le parole, non lo stato emotivo.
- **Studente sintetico**: tre copioni fissi che attraversano lo stesso orchestratore delle sessioni reali.

## 7. Hosting condiviso

- Installatore dal browser (`public/install.php`): requisiti, `config.php` generato (o da caricare via FTP), migrazioni, primo professionista; bloccato a installazione esistente (solo aggiornamento del database, previa password del database).
- Trigger di immutabilità facoltativi: se l'utente del database non può crearli, l'immutabilità è garantita dalle query applicative (modifiche solo su righe in bozza; versione della sessione mai riassegnata). Stato registrato in `app_flags.db_triggers`.
- Installazione in sottocartella senza spostare la document root; `.htaccess` di negazione nelle cartelle non pubbliche; solo direttive comunemente ammesse (mod_rewrite, Require).
- Budget di tempo per turno (`LLM_TURN_BUDGET_S`, 50 s) applicato a timeout e nuovi tentativi; cifratura delle chiavi con sodium o, in mancanza, OpenSSL AES-256-GCM.
- Nuovi professionisti creati con le rubriche dei due template (prima solo nel seed dimostrativo: senza rubriche la calibrazione non era mai calcolabile).

## 8. Funzioni aggiunte dopo l'MVP

- **Autorizzazioni** (`Consent`): registrazione per studente (tipo, chi le rilascia, versione dell'informativa, data), revoca tracciata, audit. Con l'obbligo attivo: sessioni subordinate a informativa e uso della piattaforma; nessun testo inviato a fornitori esterni senza l'autorizzazione specifica (l'agente mostra un messaggio fisso); funzioni vocali subordinate alla loro autorizzazione. Modello di informativa in `resources/informativa/`.
- **Informativa per lo studente** nella schermata iniziale: natura di IA dell'assistente, dati registrati, servizio che elabora i testi, trattamento della voce, assenza di monitoraggio in tempo reale.
- **Conservazione** (`Retention`): sessioni oltre il limite (minimo 30 giorni), simulazioni oltre 30 giorni, registro tecnico oltre il limite (minimo 7). Esecuzione giornaliera all'accesso del professionista, anteprima e applicazione manuale, audit.
- **Accordo fra codificatori** (`Agreement`): campione stratificato per fase con seme riproducibile, CSV cieco protetto dalle formule, importazione del secondo codificatore, kappa di Cohen per codice, accordo percentuale, precisione e richiamo, segnalazione dei codici con meno di 5 casi positivi, elenco dei disaccordi; accordo fra proposte automatiche e revisione umana.
- **Gruppi**: creazione, modifica dei membri, eliminazione; assegnazione del protocollo al gruppo dal configuratore (l'assegnazione individuale prevale).
- **Andamento per studente** nella panoramica: micro-obiettivi completati con e senza agente, livello di supporto, autoefficacia e valutazione del risultato per sessione; dichiarato come confronto descrittivo, non come prova di transfer.

## 9. Limiti residui

Vedi `README.md`, sezione «Limiti noti». In sintesi: provider reali e voce non verificati sul campo; controlli sull'agente probabilistici; analisi testuale e soglie non validate; conservazione automatica e interfaccia delle autorizzazioni non implementate.

## 10. Protocollo di validazione dell'analisi testuale

Riportato nel codebook (`resources/codebook/codebook_v0.1.json`, `validation_protocol`): campionamento stratificato, doppia codifica umana cieca, accordo per codice (kappa di Cohen, alpha di Krippendorff), riconciliazione, confronto modello–consenso umano separando produzioni spontanee e sollecitate, soglie fissate prima dell'analisi.
