# Zu QUIQQER beitragen

Danke, dass du QUIQQER verbesserst. Dieser Leitfaden beschreibt Fehlermeldungen, Bugfixes und weitere Beiträge zum Core. Beachte bei Erweiterungen zusätzlich die README, Werkzeuge und CI-Konfiguration des betroffenen Repositorys.

## Sprachen

- [🇬🇧 English](../CONTRIBUTING.md)
- [🇩🇪 Deutsch](CONTRIBUTING-de.md)
- [🇪🇸 Español](CONTRIBUTING-es.md)
- [🇫🇷 Français](CONTRIBUTING-fr.md)
- [🇮🇹 Italiano](CONTRIBUTING-it.md)
- [🇵🇱 Polski](CONTRIBUTING-pl.md)
- [🇵🇹 Português](CONTRIBUTING-pt.md)

## Wo Beiträge eingereicht werden

[GitLab](https://dev.quiqqer.com/quiqqer/core) ist das führende Repository und der bevorzugte Ort für Beiträge. Melde Core-Fehler im dortigen [Issue-Tracker](https://dev.quiqqer.com/quiqqer/core/-/issues), Paketfehler im Repository des betroffenen Pakets. Suche zuerst nach bestehenden Issues und verlinke verwandte Meldungen.

Du kannst auch einen Pull Request im [GitHub-Mirror](https://github.com/QUIQQER/core) eröffnen. Maintainer übernehmen angenommene Änderungen nach GitLab. Dafür brauchst du keinen GitLab-Account. Auf beiden Plattformen gelten dieselben Anforderungen an Commits, Code-Style und Tests. Erst mit der abgeschlossenen Übernahme nach GitLab gilt die Änderung als integriert.

## Einen Fehler melden

Verwende einen aussagekräftigen Titel und nenne QUIQQER- und Paketversionen, relevante PHP-, Datenbank-, Browser- und Systeminformationen, genaue Reproduktionsschritte, erwartetes und tatsächliches Verhalten sowie ein minimales Beispiel. Ergänze bei Bedarf bereinigte Logs, Stacktraces, Screenshots und Konfigurationen. Erwähne bekannte Regressionen, Auswirkungen oder Workarounds.

„Media ist kaputt. Bitte schnell beheben.“ reicht nicht: Version, Reproduktion und erwartetes Ergebnis fehlen. Entferne Zugangsdaten, Tokens, personenbezogene Daten und Kundendaten aus allen Anhängen und Beispielen.

Beantworte Rückfragen und ergänze neue Erkenntnisse im Issue. Issues, die auf deine Rückmeldung warten, werden nach drei Monaten ohne Antwort geschlossen. Labels und Status verwalten die Maintainer. Die folgenden Vorlagen sind für die sprachübergreifende Zusammenarbeit auf Englisch; ersetze die Beispieldaten durch deine Angaben.

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

## Eine Sicherheitslücke melden

Melde Sicherheitslücken vertraulich an [support@pcsg.de](mailto:support@pcsg.de). Nenne betroffene Versionen, Reproduktionsschritte oder einen minimalen Proof of Concept, Voraussetzungen und mögliche Auswirkungen. Übermittle nur die zur Untersuchung nötigen Informationen und entferne sensible Daten.

Veröffentliche Details zu Sicherheitslücken nicht in öffentlichen Issues, PRs, MRs oder Discord-Kanälen. Ein unerlaubter Zugriff auf private Dateien gehört beispielsweise in eine vertrauliche Sicherheitsmeldung.

## Eine Änderung vorbereiten

1. Prüfe ein bestehendes Issue oder lege eines an und beschreibe den geplanten Fix. Stimme größere Änderungen vor der Umsetzung mit den Maintainern ab.
2. Forke das Repository und erstelle einen fokussierten Branch vom passenden `next-*.x`-Branch. Für Core 2.x ist das `next-2.x`. Stelle dem Branchnamen die GitLab-Issue-Nummer voran, sofern vorhanden, etwa `123-fix-media-preview`.
3. Richte deinen MR oder PR an denselben Entwicklungsbranch. Breaking Changes gehören in den nächsten unveröffentlichten Major-Branch, beispielsweise `next-3.x`, solange 2.x stabil ist. Stimme das Ziel mit den Maintainern ab.
4. Reproduziere den Fehler und behebe seine Ursache mit einer kleinen Änderung. Trenne unabhängige Refactorings und Formatierungen. Bewahre die Abwärtskompatibilität und dokumentiere beabsichtigte API-Änderungen, Deprecations und Migrationen.
5. Ergänze oder aktualisiere Regressionstests für Fehler und Tests für neues Verhalten. Dokumentiere manuelle Testschritte. Begründe ausdrücklich, wenn automatisierte Testabdeckung nicht möglich ist.

Aktualisiere einen lokalen Feature-Branch mit `git pull --rebase` von seinem Remote-Branch. Löse Konflikte mit dem Zielbranch, indem du diesen in deinen Feature-Branch mergst. Schreibe gemeinsam genutzte Historie nur nach Absprache um; mache veröffentlichte Änderungen mit `git revert` rückgängig.

## Commit-Nachrichten

Jeder Commit muss eine vollständige, unabhängig rückgängig zu machende Arbeitseinheit sein. Verfasse die gesamte Nachricht auf Englisch mit diesem Header:

```text
<type>[(<scope>)][!]: <short summary>
```

- Der gesamte Header hat höchstens 72 Zeichen und endet ohne Punkt.
- Beginne die Kurzbeschreibung mit einem Kleinbuchstaben und verwende Imperativ und Präsens, etwa `fix` statt `fixed`.
- Erlaubte Typen: `build`, `ci`, `docs`, `feat`, `fix`, `perf`, `refactor`, `revert`, `style`, `test`.
- Der optionale Scope benennt einen Bereich der Codebasis, etwa `media`.
- Trenne Header, optionalen Body und optionalen Footer durch Leerzeilen. Body- und Footer-Zeilen dürfen höchstens 100 Zeichen lang sein. Beginne den Body mit einem Großbuchstaben und erläutere die Motivation im Imperativ und Präsens.
- Verweise mit `Related: project/package#123` auf ein zugehöriges Issue. Verwende `Closes: project/package#123` nur, wenn es beim Erreichen des Standard-/Release-Branches geschlossen werden soll. Verlinke in GitHub-PR-Beschreibungen die vollständige GitLab-Issue-URL, um mehrdeutige Nummern zu vermeiden.
- Breaking Changes erfordern sowohl `!` vor dem Doppelpunkt als auch einen `BREAKING CHANGE:`-Footer mit Erklärung und gegebenenfalls Migration.

GitLab-Server-Hooks prüfen Commit-Nachrichten und können einen Push ablehnen. Prüfe vor der Annahme eines GitHub-PRs alle eingehenden Commits einschließlich der abschließenden Merge- oder Squash-Nachricht. Verlasse dich nicht auf eine nachträgliche Korrektur beim Import.

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

## Code-Style und Prüfungen

Nutze eine funktionsfähige QUIQQER-Entwicklungsinstallation. Führe die folgenden Befehle im Core-Paketverzeichnis (`packages/quiqqer/core`) aus. PHP-Version und Erweiterungen müssen zur `composer.json` passen; Composer und PHIVE müssen verfügbar sein. `composer dev:init` installiert die festgelegten Werkzeuge und aktivierten Git-Hooks. Führe es beim Einrichten des Checkouts aus und `composer test` vor der Übergabe einer Änderung.

Der erste Block richtet Core ein und prüft es; der zweite zeigt einzelne Prüfungen:

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

- Für PHP gilt **PSR-12** gemäß `phpcs.xml.dist`. Führe neben der Style-Prüfung auch PHPStan und PHPUnit aus.
- Für JavaScript gelten die QUIQQER-JSHint-Regeln oder die `.jshintrc` des Pakets. Beachte vorhandene Frontend-Konventionen und prüfe bei betroffenen Oberflächen die Browserkonsole.
- Escape Ausgaben passend zum Kontext und verwende Parameterbindung für Datenbankabfragen. Führe externe Befehle aus PHP über `Symfony\Component\Process\Process` aus.
- Aktualisiere Dokumentation und Übersetzungen, wenn sich Verhalten ändert. Nutze die vorhandene Struktur der Locale-Dateien.
- Führe bei Paketbeiträgen die paketeigenen Prüfungen aus. Dokumentiere Befehle, Ergebnisse und nicht ausgeführte Prüfungen. Berücksichtige vor der Integration die GitLab-CI-Ergebnisse und das Review.

## Einen Merge Request oder Pull Request eröffnen

Behandle ein Problem pro MR/PR. Erläutere Problem, Ursache, Lösung, betroffene Pakete, Tests und Auswirkungen auf die Kompatibilität. Verlinke das Issue und dokumentiere manuelle Reproduktions- und Testschritte. Nutze diese Beschreibungsvorlage und ersetze die Beispiel-Issue-URL.

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

## Checkliste für Contributor

- [ ] Das Issue ist verlinkt und der richtige Entwicklungsbranch ausgewählt.
- [ ] Die Änderung ist fokussiert; Commits sind atomar und ihre Nachrichten entsprechen dem Standard.
- [ ] Die Reproduktion wurde geprüft; Regressionstests wurden ergänzt oder aktualisiert oder die Lücke ist begründet.
- [ ] Relevante Style-Prüfungen, statische Analyse und Tests sind erfolgreich; Ergebnisse und Einschränkungen sind dokumentiert.
- [ ] Manuelle Testschritte und Ergebnisse sind dokumentiert.
- [ ] Kompatibilität, Berechtigungen, sensible Daten und Änderungen an öffentlichen APIs wurden berücksichtigt.
- [ ] Dokumentation, Übersetzungen und Release- oder Migrationshinweise sind bei Bedarf aktualisiert.

## Checkliste für Reviewer

- [ ] Das Issue enthält genug Informationen, um den Fehler zu reproduzieren.
- [ ] Der Fix behebt die Ursache und ist klein genug für ein Review.
- [ ] Tests decken die Regression ab und relevante Prüfungen sind erfolgreich.
- [ ] Kompatibilität, Sicherheit, Berechtigungen und Datenverarbeitung wurden geprüft.
- [ ] Zielbranch und Commit-Nachrichten sind gültig, einschließlich GitHub-Merge-/Squash-Commits vor dem Import.

## Releases und weiterführende Informationen

Maintainer verwalten geschützte Branches, Versionierung, Tags und Veröffentlichung. Contributor reichen getestete Änderungen für den passenden Entwicklungsbranch ein. Maintainer bereiten den Release vor und mergen nach `main` oder in den passenden Wartungsbranch; die Release-Automatisierung bestimmt Versionen und Release Notes aus den Commit-Nachrichten.

Beschreibe sichtbare Änderungen, Deprecations und nötige Migrationen im MR/PR. Ergänze Dokumentations- oder Changelog-Hinweise, wenn das betroffene Repository sie verlangt. Prüfe dessen README und CI-Konfiguration, bevor du die Core-Werkzeuge auf ein anderes Paket überträgst.

- [QUIQQER Developer Workflow](../skills/developer/quiqqer_developer_workflow.md)
- [Anleitung für Entwickler (Deutsch)](https://dev.quiqqer.com/quiqqer/stabilization/documentation/-/wikis/Anleitung-f%C3%BCr-Entwickler)
- [composer.json](../composer.json)
- [phpcs.xml.dist](../phpcs.xml.dist)
- [locale.xml](../locale.xml)
