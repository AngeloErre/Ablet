# Recupero password — ABLET 0.11.0

## Aggiornamento e attivazione

Fare un backup. Caricare i file conservando config.php, .env e storage/. Da install.php scegliere **Aggiorna database** e applicare **012_password_recovery.sql**, senza reinstallare. Eliminare nuovamente public/install.php e aprire refresh.php.

Entrare come super admin → **Posta e recupero**. Inserire un mittente reale autorizzato dall'hosting e l'URL pubblico esatto, per esempio `https://www.angelorega.net/ablet`. Abilitare e salvare. Inviare una prova alla propria casella e controllare anche lo spam.

L'invio usa PHP mail(), senza dipendenze esterne o configurazione SMTP nell'interfaccia. «Accettata» attesta l'accettazione del server, non la consegna. La ricezione su Aruba deve essere verificata dall'amministratore; durante i test non sono state inviate e-mail reali. Se la prova non arriva, verificare mittente e servizio di posta con l'hosting.

In assenza di impostazioni nel database si possono usare PASSWORD_RESET_ENABLED, MAIL_FROM e APP_PUBLIC_URL nell'ambiente/config.php. Le impostazioni salvate dall'admin hanno precedenza. APP_PUBLIC_URL è distinto da APP_URL.

## Uso

Nell'accesso professionista o super admin scegliere **Password dimenticata?**, inserire l'e-mail usata come codice di accesso e aprire il link ricevuto entro 30 minuti. La nuova password deve avere almeno 12 caratteri e massimo 72 byte. Accedere normalmente dopo il cambio.

Il link è monouso. Il cambio revoca le sessioni precedenti e gli altri link dello stesso account. Non autentica automaticamente. Il token viene rimosso dalla barra degli indirizzi durante la compilazione: dopo un aggiornamento della pagina occorre riaprire il link ricevuto.

Gli account con codice diverso da un'e-mail continuano a usare il reset del super admin. Gli studenti non usano questo recupero. Gli account sospesi sono esclusi. La disattivazione del servizio revoca i link pendenti.

## Protezioni e verifiche

Token casuali da 32 byte, memorizzati solo come hash SHA-256, HTTPS in produzione, CSRF, limiti per IP e indirizzo. Il link usa l'URL configurato, non l'header Host. La risposta pubblica è identica per indirizzi presenti e assenti; l'invio sincrono non garantisce tempi identici. L'audit non contiene password o token.

Le conferme sono serializzate per account e ricontrollate in transazione: due conferme simultanee consentono un solo cambio. La registrazione continua a non verificare l'e-mail o la qualifica professionale.

Superati 35 controlli specifici, compresi scadenza, riuso, revoca sessioni, rate limit e concorrenza; verificati gli aggiornamenti dagli schemi 009, 010 e 011 e le regressioni delle API/interfacce. La posta dei test è simulata esclusivamente in ambiente development con un file temporaneo; la consegna reale va provata sul server.
