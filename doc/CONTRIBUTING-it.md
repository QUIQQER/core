# Contribuire a QUIQQER

Grazie per il tuo contributo a QUIQQER. Questa guida descrive come segnalare errori, proporre correzioni e contribuire al Core. Per le estensioni, consulta anche il README, gli strumenti e la configurazione CI del repository interessato.

## Lingue

- [🇬🇧 English](../CONTRIBUTING.md)
- [🇩🇪 Deutsch](CONTRIBUTING-de.md)
- [🇪🇸 Español](CONTRIBUTING-es.md)
- [🇫🇷 Français](CONTRIBUTING-fr.md)
- [🇮🇹 Italiano](CONTRIBUTING-it.md)
- [🇵🇱 Polski](CONTRIBUTING-pl.md)
- [🇵🇹 Português](CONTRIBUTING-pt.md)

## Dove contribuire

[GitLab](https://dev.quiqqer.com/quiqqer/core) è il repository di riferimento e la piattaforma preferita per contribuire. Segnala gli errori del Core nel suo [issue tracker](https://dev.quiqqer.com/quiqqer/core/-/issues) e quelli dei pacchetti nei rispettivi repository. Cerca prima le issue esistenti e collega le segnalazioni correlate.

Puoi anche aprire una pull request nel [mirror GitHub](https://github.com/QUIQQER/core). I maintainer trasferiscono le modifiche accettate su GitLab. Per questa modalità non serve un account GitLab. I requisiti per commit, stile e test sono uguali su entrambe le piattaforme. La modifica è integrata quando il trasferimento su GitLab è completato.

## Segnalare un errore

Usa un titolo chiaro e indica le versioni di QUIQQER e dei pacchetti, le informazioni pertinenti su PHP, database, browser e sistema, i passaggi esatti per riprodurre il problema, il comportamento atteso e quello effettivo, e un esempio minimo. Aggiungi log privi di dati sensibili, stack trace, schermate e configurazioni quando servono. Indica regressioni, impatto o soluzioni temporanee noti.

«Media non funziona. Correggete subito.» non basta: mancano versione, riproduzione e risultato atteso. Rimuovi credenziali, token, dati personali e dati dei clienti da tutti gli allegati ed esempi.

Rispondi alle domande e aggiorna la issue con le nuove informazioni. Le issue in attesa di una tua risposta vengono chiuse dopo tre mesi senza riscontro. I maintainer gestiscono etichette e stato. I modelli seguenti sono in inglese per facilitare la collaborazione tra lingue diverse; sostituisci i dati di esempio con i tuoi.

````markdown
## Summary
The media manager shows no preview for a transparent PNG.

## Versions and environment
- QUIQQER / Core: <version>
- Affected package(s): <name and version>
- PHP / database: <versions>
- Browser / operating system: <versions, if relevant>

## Steps to reproduce
1. Open the administration area and go to Media.
2. Upload the attached minimal example.png.
3. Open its details.

## Expected behaviour
The image preview is displayed.

## Actual behaviour
The details panel opens, but the preview remains blank.

## Additional context
- Reproducible with: <minimal example or configuration>
- Regression / last working version: <if known>
- Workaround / impact: <if known>
- Sanitized logs, screenshot or stack trace: <if available>
````

## Segnalare una vulnerabilità

Segnala le vulnerabilità in privato a [support@pcsg.de](mailto:support@pcsg.de). Includi versioni interessate, passaggi di riproduzione o un proof of concept minimo, prerequisiti e impatto possibile. Invia solo le informazioni necessarie all’analisi, rimuovendo i dati sensibili.

Non pubblicare dettagli di vulnerabilità in issue, PR, MR o canali Discord pubblici. Un accesso non autorizzato a file privati, per esempio, va segnalato in modo riservato.

## Preparare una modifica

1. Consulta o crea una issue e descrivi la correzione prevista. Concorda le modifiche più ampie con i maintainer prima di implementarle.
2. Crea un fork e un branch dedicato a partire dal branch `next-*.x` appropriato. Per Core 2.x, usa `next-2.x`. Se esiste una issue GitLab, anteponi il suo numero al nome, per esempio `123-fix-media-preview`.
3. Indirizza MR o PR allo stesso branch di sviluppo. Le modifiche incompatibili vanno nel prossimo major non ancora rilasciato, per esempio `next-3.x` mentre 2.x è stabile; conferma la destinazione con i maintainer.
4. Riproduci l’errore e correggine la causa con una modifica limitata. Separa refactoring e formattazione non correlati. Mantieni la retrocompatibilità e documenta modifiche intenzionali delle API, deprecazioni e migrazioni.
5. Aggiungi o aggiorna i test di regressione per le correzioni e i test per i nuovi comportamenti. Documenta i test manuali. Spiega esplicitamente se non è possibile aggiungere copertura automatizzata.

Aggiorna un branch locale di lavoro dal suo remoto con `git pull --rebase`. Risolvi i conflitti effettuando il merge del branch di destinazione nel tuo branch. Non riscrivere la cronologia condivisa senza accordo; annulla le modifiche pubblicate con `git revert`.

## Messaggi di commit

Ogni commit deve essere un’unità di lavoro completa e annullabile in modo indipendente. Scrivi l’intero messaggio in inglese con questa intestazione:

```text
<type>[(<scope>)][!]: <short summary>
```

- L’intestazione completa ha un massimo di 72 caratteri e non termina con un punto.
- Inizia il riepilogo con una minuscola e usa l’imperativo presente, come `fix`, non `fixed`.
- Tipi ammessi: `build`, `ci`, `docs`, `feat`, `fix`, `perf`, `refactor`, `revert`, `style`, `test`.
- Lo scope facoltativo identifica un’area del codice, per esempio `media`.
- Separa intestazione, corpo facoltativo e footer facoltativo con righe vuote. Le righe del corpo e del footer hanno un massimo di 100 caratteri. Inizia il corpo con una maiuscola e spiega la motivazione all’imperativo presente.
- Collega una issue con `Related: project/package#123`. Usa `Closes: project/package#123` solo se deve chiudersi quando la modifica raggiunge il branch predefinito o di rilascio. Nelle PR GitHub includi l’URL GitLab completo per evitare numeri ambigui.
- Le modifiche incompatibili richiedono `!` prima dei due punti e un footer `BREAKING CHANGE:` che spieghi il cambiamento e l’eventuale migrazione.

Gli hook del server GitLab verificano i messaggi e possono rifiutare un push. Prima di accettare una PR GitHub, controlla tutti i commit in arrivo, incluso il messaggio finale di merge o squash. Non affidarti all’importazione successiva per correggere i messaggi.

```text
fix(media): restore preview for transparent PNG images

Use the original image when the thumbnail URL is missing.
Add a regression test for the preview metadata.

Related: quiqqer/core#123
```

```text
build!: drop support for an obsolete dependency

BREAKING CHANGE: Remove support for old/package 1.x
Upgrade to old/package 2.x before updating.
```

## Stile del codice e verifiche

Usa un’installazione di sviluppo QUIQQER funzionante. Esegui i comandi seguenti dalla directory del pacchetto Core (`packages/quiqqer/core`). PHP e le estensioni devono rispettare `composer.json`; Composer e PHIVE devono essere disponibili. `composer dev:init` installa gli strumenti nelle versioni fissate e gli hook Git abilitati. Eseguilo durante la preparazione del checkout ed esegui `composer test` prima di consegnare una modifica.

Il primo blocco prepara e verifica Core; il secondo mostra i controlli individuali:

```shell
composer dev:init
composer test
```

```shell
composer dev:lint
composer dev:phpunit
./tools/phpcs
./tools/phpstan
./tools/phpunit
```

- PHP segue **PSR-12**, come configurato in `phpcs.xml.dist`. Esegui PHPStan e PHPUnit oltre al controllo dello stile.
- JavaScript segue le regole JSHint di QUIQQER o il `.jshintrc` del pacchetto. Rispetta le convenzioni frontend esistenti e controlla la console del browser quando modifichi l’interfaccia.
- Esegui l’escaping dell’output in base al contesto e usa il binding dei parametri nelle query al database. Esegui i comandi esterni da PHP tramite `Symfony\Component\Process\Process`.
- Aggiorna documentazione e traduzioni quando cambia il comportamento. Segui la struttura esistente dei file di lingua.
- Per contribuire a un pacchetto, esegui i suoi controlli specifici. Riporta comandi, risultati e verifiche non eseguite. Considera i risultati della CI GitLab e della revisione prima dell’integrazione.

## Aprire una merge request o pull request

Affronta un problema per MR/PR. Spiega problema, causa, soluzione, pacchetti interessati, test e implicazioni per la compatibilità. Collega la issue e documenta i passaggi manuali di riproduzione e test. Usa questo modello e sostituisci l’URL di esempio.

````markdown
## Problem and affected packages
<What fails, who is affected, and which packages change?>

## Related issue
https://dev.quiqqer.com/quiqqer/core/-/issues/123

## Root cause
<Why does the problem occur?>

## Solution
<How does this change address the cause?>

## Tests
- Automated: <command, regression covered and result>
- Manual: <steps, environment and result>
- Limitations: <checks not run and why, or none>

## Compatibility and documentation
<Public API, permissions, dependencies, translations, release notes or migration needs>
````

## Checklist per chi contribuisce

- [ ] La issue è collegata ed è selezionato il branch di sviluppo corretto.
- [ ] La modifica è mirata; i commit sono atomici e i messaggi rispettano lo standard.
- [ ] La riproduzione è stata verificata e i test di regressione aggiunti o aggiornati, oppure la mancanza è motivata.
- [ ] I controlli pertinenti di stile, analisi statica e test sono superati; risultati e limiti sono documentati.
- [ ] Passaggi e risultati dei test manuali sono documentati.
- [ ] Compatibilità, permessi, dati sensibili e modifiche alle API pubbliche sono stati considerati.
- [ ] Documentazione, traduzioni e note di rilascio o migrazione sono aggiornate quando necessario.

## Checklist per chi revisiona

- [ ] La issue contiene informazioni sufficienti a riprodurre il problema.
- [ ] La correzione risolve la causa ed è abbastanza piccola da revisionare.
- [ ] I test coprono la regressione e i controlli pertinenti sono superati.
- [ ] Compatibilità, sicurezza, permessi e trattamento dei dati sono stati verificati.
- [ ] Branch di destinazione e messaggi sono validi, inclusi i commit GitHub di merge o squash prima dell’importazione.

## Rilasci e approfondimenti

I maintainer gestiscono branch protetti, versioni, tag e pubblicazioni. Chi contribuisce propone modifiche testate al branch di sviluppo appropriato. I maintainer preparano il rilascio e fanno il merge in `main` o nel branch di manutenzione corretto; l’automazione determina versioni e note dai messaggi di commit.

Descrivi nel MR/PR le modifiche visibili, le deprecazioni e le migrazioni necessarie. Aggiungi documentazione o note al changelog se richiesto dal repository. Consulta il suo README e la configurazione CI prima di supporre che un altro pacchetto usi esattamente gli strumenti del Core.

- [Documentazione QUIQQER](https://www.quiqqer.com/docs/)
- [Flusso di sviluppo](https://www.quiqqer.com/docs/developer/development-workflow)
- [Standard del codice](https://www.quiqqer.com/docs/developer/coding-standards)
- [Test e qualità](https://www.quiqqer.com/docs/developer/testing-and-quality)
- [composer.json](../composer.json)
- [phpcs.xml.dist](../phpcs.xml.dist)
- [locale.xml](../locale.xml)
