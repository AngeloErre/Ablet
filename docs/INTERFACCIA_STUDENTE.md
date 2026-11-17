# Interfaccia studente — tablet, «voce prima»

## 1. Utente finale

- **Chi**: studenti di scuola primaria e secondaria (8–16 anni), anche con bisogni educativi speciali (es. difficoltà di pianificazione e di automonitoraggio, decodifica lenta del testo scritto).
- **Dove**: tablet, spesso tenuto in mano o appoggiato, a casa o a scuola; tocco, non mouse e tastiera.
- **Come**: preferisce parlare piuttosto che scrivere; legge con fatica testi lunghi; perde facilmente il filo se lo schermo propone più scelte contemporaneamente.

Conseguenze progettuali:

| Vincolo osservato | Scelta | Riferimento |
|---|---|---|
| La lettura e la scrittura consumano risorse che servono al compito | La conversazione avviene a voce: l'avatar parla, lo studente risponde parlando. Il testo resta come sottotitolo e come alternativa | Effetto di modalità e carico estraneo (Sweller, van Merriënboer & Paas) |
| Più scelte sullo schermo = più decisioni = più dispersione | Un solo gesto principale (il microfono), tre comandi fissi (Pausa, Parla, Riascolta), risposte rapide solo quando servono | Un passo per turno; inibizione e memoria di lavoro (Miyake et al.; Diamond) |
| Il bambino dimentica che cosa gli è stato chiesto | **Riascolta** sempre disponibile; sottotitolo con evidenziata la parola letta; percorso dei passi sempre visibile | Esternalizzazione della consegna |
| Il riconoscimento vocale sbaglia | Le parole capite compaiono sempre prima dell'invio, con **Ridico** e **Correggo**; l'invio parte da solo dopo 4 secondi se non si tocca nulla | Trascrizione modificabile (requisito) |
| Ansia da prestazione | Nessun voto, nessun punteggio; pausa con avatar che «dorme»; chiusura non valutativa | Feedback sul processo, non sulla persona |

## 2. Schermate

1. **Inizio**: l'avatar saluta; i compiti sono schede grandi con 🔊 per farsi leggere il compito; il compito in corso è evidenziato con «Continua».
2. **Palco**: avatar grande; accanto il sottotitolo dell'ultima frase; sotto le risposte rapide del momento; in basso **Pausa · Parla · Riascolta**; in fondo Il testo, Il mio piano, Scrivo, Tutto.
3. **Pausa**: schermata intera, avatar addormentato, **Riprendi** o «Continuo un'altra volta».
4. **Sospensione di sicurezza**: schermata intera, messaggio fisso letto ad alta voce, riferimento a un adulto e al 112, nessuna promessa di intervento.
5. **Fine**: passi completati e strategia usata, senza giudizi.

## 3. Turni di conversazione

Stati dell'avatar: attesa, parla (la bocca segue le parole), ascolta (anello pulsante), pensa (puntini), dorme (pausa).

- Dopo ogni messaggio l'avatar parla, poi **ascolta da solo** se è il turno dello studente: sempre in pianificazione e riflessione; durante il lavoro solo se fa una domanda, per non ascoltare mentre lo studente studia. Dopo due silenzi consecutivi smette di ascoltare da solo fino al prossimo tocco del microfono. L'ascolto automatico si può spegnere (⚙).
- **Comandi vocali brevi** (sempre mostrati e confermati prima di agire): «fatto», «aiuto», «sono bloccato», «pausa», «ripeti», «sì/no» alle proposte. I numeri detti a parole («tre», «venti minuti») diventano cifre quando la risposta attesa è un numero.
- Ogni comando vocale ha un pulsante equivalente, e viceversa: le due modalità sono intercambiabili.

## 4. Risposte rapide per fase

- Pianificazione: scale 1–5 per difficoltà attesa e autoefficacia; minuti (5–45); «Non so da dove iniziare»; «Leggimi il compito»; «Inizio a lavorare» quando il piano è completo.
- Lavoro: **Fatto!**, Un aiuto, Sono bloccato, Non ci sono riuscito, Provo in un altro modo; scala di sicurezza 1–5 quando l'avatar la chiede.
- Proposte di adattamento: lette dall'avatar, risposta «Sì, proviamo» / «No, grazie» (anche a voce).
- Riflessione: com'è andata (1–5), come mi sento (facoltativo, con «Preferisco non dirlo»), Ho finito.

## 5. Voce: limiti e ripieghi

- Sintesi e riconoscimento sono quelli del **browser**; il riconoscimento può inviare l'audio ai server del produttore del browser (dichiarato in ℹ e ⚙). ABLET non riceve audio.
- iPad: la voce viene «sbloccata» al primo tocco (requisito dei browser per l'audio).
- Microfono negato, assente o rete lenta: il pulsante diventa **Scrivi**, l'avatar lo dice e si prosegue con la tastiera.
- Verifica eseguita con riconoscimento e sintesi **simulati** in Chrome (tablet 1180×820 orizzontale e 820×1180 verticale). Da provare su iPad e tablet Android reali: qualità delle voci italiane, disponibilità del riconoscimento, comportamento dell'ascolto automatico in ambienti rumorosi.

## 6. Accessibilità

Pulsanti di almeno 56 px, etichette per lettori di schermo su tutti i comandi, sottotitoli attivabili, testo ingrandibile, contrasto elevato, animazioni riducibili (l'avatar resta fermo), lettura del testo di studio a blocchi con la parola evidenziata.

## App sul tablet (PWA)

ABLET si installa come app dal browser, senza store.

| Dispositivo | Come si installa |
|---|---|
| Android (Chrome), tablet Windows/Chromebook (Chrome, Edge) | Nella home dello studente compare **«📲 Metti sul tablet»**: apre la finestra di installazione del sistema. |
| iPad (Safari) | Lo stesso pulsante mostra (e legge ad alta voce) i tre passi: Condividi → «Aggiungi alla schermata Home» → Aggiungi. |

Requisito: il sito deve essere servito in **HTTPS** (su Aruba basta attivare il certificato SSL del dominio). Senza HTTPS il browser non attiva il service worker e non offre l'installazione; tutto il resto funziona.

Comportamento dell'app installata:
- si apre a schermo intero, con icona e colori di ABLET, rispettando le aree sicure dello schermo (notch, barra di sistema);
- **schermo sempre acceso** solo mentre una sessione è attiva (Screen Wake Lock); in pausa e nella home il tablet può spegnersi normalmente; se il browser non supporta la funzione, nessun effetto;
- **standby o cambio di app**: voce e microfono si fermano subito; al ritorno lo stato della sessione viene riletto dal server (vale anche dopo disconnessioni o uso in un'altra scheda);
- **senza rete**: avviso vocale e testuale «il tuo lavoro è salvato»; se l'app viene aperta senza connessione compare una pagina «Manca la connessione» con il pulsante Riprova;
- il service worker conserva solo icone e file statici: **le chiamate `/api/` non passano mai dalla cache** e nessun messaggio, materiale o audio viene salvato sul dispositivo.

File: `public/manifest.webmanifest`, `public/sw.js`, `public/assets/icons/` (icone normali, adattive «maskable» e Apple).

## Testi pensati per l'ascolto

L'agente parla a un bambino o a un ragazzo che ascolta, non a un lettore. Ogni turno include, oltre al prompt del protocollo, un blocco **CONSEGNA A VOCE** generato dal server in base alla fascia d'età del profilo funzionale (vale anche per i protocolli già pubblicati, che non vengono modificati):

| Fascia | Parole per messaggio | Parole per frase |
|---|---|---|
| 8-10 | 30 | 10 |
| 11-13 (anche se la fascia manca) | 40 | 12 |
| 14-16 | 50 | 15 |
| 17-19, adulto | 60 | 18 |

Regole: una sola richiesta, sempre **alla fine** (è l'ultima cosa che si sente prima di rispondere); niente elenchi, simboli, link, parentesi o emoji; «dimmi», «raccontami», «quando hai finito dimmi fatto» invece di «scrivi», «premi», «clicca»; numeri piccoli in parole; passi del piano citati brevemente.

Controlli sul server (`src/Domain/Agent/Delivery.php`):
1. **pulizia per la voce**: markdown, elenchi, frecce, emoji e link vengono rimossi; «1-5» diventa «da 1 a 5»; le parentesi diventano incisi; gli a capo diventano frasi;
2. **un solo nuovo tentativo** se il messaggio supera di oltre il 40% il limite della fascia o contiene più di due domande; se anche il secondo non va bene, si usa il primo (già ripulito): il testo non viene mai troncato, perché si perderebbe proprio la domanda finale. Il tentativo è registrato (evento `delivery_retry`) e i problemi residui restano nei metadati del messaggio per la revisione.

Riscritti per l'ascolto anche: le risposte della modalità dimostrativa, i messaggi di sicurezza (disagio, emergenza, pausa), il messaggio di ripiego, quello di autorizzazione mancante e le proposte di adattamento. Regole della piattaforma: versione **0.2.0** (nuova sezione ASCOLTO).

Le soglie di lunghezza sono parametri iniziali di progetto, coerenti con il carico sulla memoria di lavoro fonologica durante l'ascolto, **da validare** con prove d'uso reali (comprensione del messaggio, richieste di ripetizione, uso di «Riascolta» per fascia).
