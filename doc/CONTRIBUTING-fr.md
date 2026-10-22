# Contribuer à QUIQQER

Merci de contribuer à améliorer QUIQQER. Ce guide explique comment signaler des bogues, proposer des corrections et contribuer au Core. Pour les extensions, consultez aussi le README, les outils et la configuration CI du dépôt concerné.

## Langues

- [🇬🇧 English](../CONTRIBUTING.md)
- [🇩🇪 Deutsch](CONTRIBUTING-de.md)
- [🇪🇸 Español](CONTRIBUTING-es.md)
- [🇫🇷 Français](CONTRIBUTING-fr.md)
- [🇮🇹 Italiano](CONTRIBUTING-it.md)
- [🇵🇱 Polski](CONTRIBUTING-pl.md)
- [🇵🇹 Português](CONTRIBUTING-pt.md)

## Où contribuer

[GitLab](https://dev.quiqqer.com/quiqqer/core) est le dépôt de référence et la plateforme privilégiée pour contribuer. Signalez les bogues du Core dans son [gestionnaire de tickets](https://dev.quiqqer.com/quiqqer/core/-/issues), et ceux des paquets dans leurs dépôts respectifs. Recherchez les tickets existants et liez les signalements associés.

Vous pouvez aussi ouvrir une pull request sur le [miroir GitHub](https://github.com/QUIQQER/core). Les mainteneurs transfèrent les modifications acceptées vers GitLab. Cette démarche ne nécessite pas de compte GitLab. Les exigences de commits, de style et de tests sont identiques sur les deux plateformes. Une modification est intégrée une fois son transfert vers GitLab terminé.

## Signaler un bogue

Choisissez un titre précis et indiquez les versions de QUIQQER et des paquets, les informations pertinentes sur PHP, la base de données, le navigateur et le système, les étapes de reproduction, le comportement attendu et observé, ainsi qu’un exemple minimal. Joignez si nécessaire des journaux expurgés, traces, captures et configurations. Précisez toute régression, conséquence ou solution de contournement connue.

« Media ne fonctionne pas. Corrigez vite. » ne suffit pas : il manque les versions, la reproduction et le résultat attendu. Retirez identifiants, jetons, données personnelles et données clients de toutes les pièces jointes et exemples.

Répondez aux questions et ajoutez les nouvelles informations au ticket. Les tickets en attente de votre réponse sont fermés après trois mois sans retour. Les mainteneurs gèrent les étiquettes et les statuts. Les modèles suivants sont en anglais pour faciliter la collaboration entre langues ; remplacez les données d’exemple par les vôtres.

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

## Signaler une vulnérabilité

Signalez les vulnérabilités en privé à [support@pcsg.de](mailto:support@pcsg.de). Indiquez les versions affectées, les étapes de reproduction ou une preuve de concept minimale, les prérequis et l’impact potentiel. Transmettez uniquement les informations nécessaires à l’analyse, sans données sensibles.

Ne publiez pas les détails d’une vulnérabilité dans un ticket, une PR, une MR ou un canal Discord public. Un accès non autorisé à des fichiers privés doit par exemple être signalé en privé.

## Préparer une modification

1. Consultez ou créez un ticket et décrivez la correction prévue. Discutez des modifications importantes avec les mainteneurs avant de les réaliser.
2. Créez un fork et une branche dédiée à partir de la branche `next-*.x` appropriée. Pour Core 2.x, utilisez `next-2.x`. Si un ticket GitLab existe, préfixez le nom avec son numéro, par exemple `123-fix-media-preview`.
3. Ciblez cette même branche de développement dans votre MR ou PR. Les changements incompatibles vont dans la prochaine version majeure non publiée, par exemple `next-3.x` tant que 2.x est stable ; confirmez la cible avec les mainteneurs.
4. Reproduisez le bogue et corrigez sa cause avec une modification limitée. Séparez les refactorisations et changements de formatage sans rapport. Préservez la rétrocompatibilité et documentez les modifications intentionnelles d’API, les dépréciations et les migrations.
5. Ajoutez ou actualisez les tests de régression pour les corrections et les tests des nouveaux comportements. Documentez les tests manuels. Expliquez explicitement toute impossibilité de couverture automatisée.

Actualisez une branche locale depuis sa branche distante avec `git pull --rebase`. Résolvez les conflits en fusionnant la branche cible dans votre branche de travail. Ne réécrivez pas l’historique partagé sans accord ; annulez les modifications publiées avec `git revert`.

## Messages de commit

Chaque commit doit former une unité de travail complète, réversible indépendamment. Rédigez tout le message en anglais avec cet en-tête :

```text
<type>[(<scope>)][!]: <short summary>
```

- L’en-tête complet comporte au maximum 72 caractères et ne se termine pas par un point.
- Commencez le résumé par une minuscule et utilisez l’impératif au présent, comme `fix`, pas `fixed`.
- Types autorisés : `build`, `ci`, `docs`, `feat`, `fix`, `perf`, `refactor`, `revert`, `style`, `test`.
- Le scope facultatif désigne une partie du code, par exemple `media`.
- Séparez l’en-tête, le corps facultatif et le pied facultatif par des lignes vides. Les lignes du corps et du pied sont limitées à 100 caractères. Commencez le corps par une majuscule et expliquez la motivation à l’impératif au présent.
- Référencez un ticket associé avec `Related: project/package#123`. Utilisez `Closes: project/package#123` uniquement s’il doit être fermé à l’arrivée dans la branche par défaut ou de publication. Dans une PR GitHub, indiquez l’URL GitLab complète pour éviter les numéros ambigus.
- Un changement incompatible exige `!` avant les deux-points et un pied `BREAKING CHANGE:` expliquant le changement et l’éventuelle migration.

Les hooks du serveur GitLab vérifient les messages et peuvent refuser un push. Avant d’accepter une PR GitHub, vérifiez tous les commits entrants, y compris le message final de fusion ou de squash. Ne comptez pas sur l’import ultérieur pour corriger les messages.

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

## Style de code et vérifications

Utilisez une installation de développement QUIQQER fonctionnelle. Exécutez les commandes suivantes depuis le répertoire du paquet Core (`packages/quiqqer/core`). PHP et ses extensions doivent respecter `composer.json` ; Composer et PHIVE doivent être disponibles. `composer dev:init` installe les versions fixées des outils et les hooks Git activés. Exécutez-le lors de la préparation du dépôt, puis lancez `composer test` avant de soumettre une modification.

Le premier bloc prépare et vérifie Core ; le second présente les vérifications individuelles :

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

- PHP suit **PSR-12**, selon `phpcs.xml.dist`. Exécutez PHPStan et PHPUnit en plus du contrôle de style.
- JavaScript suit les règles JSHint de QUIQQER ou le `.jshintrc` du paquet. Respectez les conventions frontend existantes et vérifiez la console du navigateur pour les modifications d’interface.
- Échappez les sorties selon leur contexte et liez les paramètres des requêtes SQL. Exécutez les commandes externes depuis PHP via `Symfony\Component\Process\Process`.
- Actualisez la documentation et les traductions lorsque le comportement change. Respectez la structure existante des fichiers de langue.
- Pour un paquet, exécutez ses propres vérifications. Indiquez les commandes, résultats et contrôles non exécutés. Tenez compte de la CI GitLab et de la relecture avant l’intégration.

## Ouvrir une merge request ou pull request

Traitez un seul problème par MR/PR. Décrivez le problème, sa cause, la solution, les paquets affectés, les tests et les conséquences sur la compatibilité. Liez le ticket et documentez les étapes manuelles de reproduction et de test. Utilisez ce modèle en remplaçant l’URL du ticket d’exemple.

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

## Liste pour les contributeurs

- [ ] Le ticket est lié et la bonne branche de développement est ciblée.
- [ ] La modification est ciblée ; les commits sont atomiques et leurs messages respectent le standard.
- [ ] La reproduction a été vérifiée et les tests de régression ajoutés ou actualisés, ou leur absence est expliquée.
- [ ] Les contrôles pertinents de style, d’analyse statique et de tests réussissent ; résultats et limites sont documentés.
- [ ] Les étapes et résultats des tests manuels sont documentés.
- [ ] Compatibilité, permissions, données sensibles et changements d’API publiques ont été examinés.
- [ ] Documentation, traductions et notes de publication ou de migration sont actualisées si nécessaire.

## Liste pour les relecteurs

- [ ] Le ticket contient assez d’informations pour reproduire le problème.
- [ ] La correction traite la cause et reste suffisamment petite pour être relue.
- [ ] Les tests couvrent la régression et les vérifications pertinentes réussissent.
- [ ] Compatibilité, sécurité, permissions et traitement des données ont été examinés.
- [ ] La branche cible et les messages sont valides, y compris les commits GitHub de fusion ou de squash avant import.

## Publications et informations complémentaires

Les mainteneurs gèrent les branches protégées, les versions, les tags et les publications. Les contributeurs soumettent des modifications testées à la branche de développement appropriée. Les mainteneurs préparent la publication puis fusionnent dans `main` ou la branche de maintenance adaptée ; l’automatisation détermine les versions et notes à partir des messages de commit.

Décrivez les changements visibles, les dépréciations et les migrations nécessaires dans la MR/PR. Ajoutez documentation ou notes de changelog selon les exigences du dépôt. Consultez son README et sa CI avant de supposer qu’un autre paquet utilise exactement les outils du Core.

- [Documentation QUIQQER](https://www.quiqqer.com/docs/)
- [Processus de développement](https://www.quiqqer.com/docs/developer/development-workflow)
- [Standards de code](https://www.quiqqer.com/docs/developer/coding-standards)
- [Tests et qualité](https://www.quiqqer.com/docs/developer/testing-and-quality)
- [composer.json](../composer.json)
- [phpcs.xml.dist](../phpcs.xml.dist)
- [locale.xml](../locale.xml)
