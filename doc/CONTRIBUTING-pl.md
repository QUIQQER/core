# Współtworzenie QUIQQER

Dziękujemy za rozwijanie QUIQQER. Ten przewodnik opisuje zgłaszanie błędów, poprawki i inne zmiany w Core. W przypadku rozszerzeń sprawdź również README, narzędzia i konfigurację CI danego repozytorium.

## Języki

- [🇬🇧 English](../CONTRIBUTING.md)
- [🇩🇪 Deutsch](CONTRIBUTING-de.md)
- [🇪🇸 Español](CONTRIBUTING-es.md)
- [🇫🇷 Français](CONTRIBUTING-fr.md)
- [🇮🇹 Italiano](CONTRIBUTING-it.md)
- [🇵🇱 Polski](CONTRIBUTING-pl.md)
- [🇵🇹 Português](CONTRIBUTING-pt.md)

## Gdzie zgłaszać zmiany

[GitLab](https://dev.quiqqer.com/quiqqer/core) jest głównym repozytorium i preferowanym miejscem współpracy. Błędy Core zgłaszaj w jego [systemie zgłoszeń](https://dev.quiqqer.com/quiqqer/core/-/issues), a błędy pakietów w ich repozytoriach. Najpierw wyszukaj istniejące zgłoszenia i dodaj odnośniki do powiązanych problemów.

Możesz również otworzyć pull request w [kopii na GitHubie](https://github.com/QUIQQER/core). Opiekunowie przenoszą zaakceptowane zmiany do GitLaba. Ta ścieżka nie wymaga konta w GitLabie. Na obu platformach obowiązują te same wymagania dotyczące commitów, stylu i testów. Zmiana jest zintegrowana po zakończeniu przeniesienia do GitLaba.

## Zgłaszanie błędu

Podaj jasny tytuł, wersje QUIQQER i pakietów, istotne informacje o PHP, bazie danych, przeglądarce i systemie, dokładne kroki odtworzenia, oczekiwane i rzeczywiste zachowanie oraz minimalny przykład. W razie potrzeby dołącz logi bez danych wrażliwych, stos wywołań, zrzuty ekranu i konfigurację. Wskaż znaną regresję, wpływ lub obejście problemu.

„Media nie działa. Naprawcie szybko.” nie wystarcza: brakuje wersji, kroków odtworzenia i oczekiwanego wyniku. Usuń dane dostępowe, tokeny, dane osobowe i dane klientów ze wszystkich załączników i przykładów.

Odpowiadaj na pytania i aktualizuj zgłoszenie o nowe informacje. Zgłoszenia oczekujące na Twoją odpowiedź są zamykane po trzech miesiącach bez reakcji. Opiekunowie zarządzają etykietami i statusem. Poniższe szablony są po angielsku, aby ułatwić współpracę między językami; zastąp przykładowe dane własnymi.

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

## Zgłaszanie podatności

Podatności zgłaszaj prywatnie na [support@pcsg.de](mailto:support@pcsg.de). Podaj wersje, kroki odtworzenia lub minimalny dowód koncepcji, wymagane warunki oraz możliwe skutki. Prześlij tylko informacje potrzebne do analizy, bez danych wrażliwych.

Nie publikuj szczegółów podatności w publicznych zgłoszeniach, PR-ach, MR-ach ani kanałach Discorda. Na przykład nieuprawniony dostęp do prywatnych plików wymaga zgłoszenia prywatnego.

## Przygotowanie zmiany

1. Sprawdź istniejące zgłoszenie lub utwórz nowe i opisz planowaną poprawkę. Większe zmiany uzgodnij z opiekunami przed rozpoczęciem pracy.
2. Utwórz fork i osobną gałąź z odpowiedniej gałęzi `next-*.x`. Dla Core 2.x użyj `next-2.x`. Jeśli istnieje zgłoszenie GitLaba, rozpocznij nazwę gałęzi jego numerem, np. `123-fix-media-preview`.
3. Skieruj MR lub PR do tej samej gałęzi rozwojowej. Zmiany niezgodne wstecz należą do następnej niewydanej wersji głównej, np. `next-3.x`, gdy stabilna jest 2.x. Potwierdź gałąź docelową z opiekunami.
4. Odtwórz błąd i usuń jego przyczynę niewielką zmianą. Oddziel niepowiązany refaktoring i formatowanie. Zachowaj zgodność wsteczną i opisz zamierzone zmiany API, wycofywanie funkcji i migracje.
5. Dodaj lub zaktualizuj testy regresji dla poprawek i testy nowych zachowań. Opisz testy ręczne. Wyraźnie uzasadnij brak możliwości dodania testów automatycznych.

Aktualizuj lokalną gałąź z jej zdalnego odpowiednika przez `git pull --rebase`. Konflikty rozwiązuj, scalając gałąź docelową do swojej gałęzi roboczej. Nie przepisuj wspólnej historii bez uzgodnienia; opublikowane zmiany wycofuj przez `git revert`.

## Komunikaty commitów

Każdy commit musi być kompletną jednostką pracy, którą można wycofać niezależnie. Cały komunikat napisz po angielsku z nagłówkiem:

```text
<type>[(<scope>)][!]: <short summary>
```

- Cały nagłówek ma najwyżej 72 znaki i nie kończy się kropką.
- Rozpocznij podsumowanie małą literą i użyj trybu rozkazującego w czasie teraźniejszym, np. `fix`, nie `fixed`.
- Dozwolone typy: `build`, `ci`, `docs`, `feat`, `fix`, `perf`, `refactor`, `revert`, `style`, `test`.
- Opcjonalny zakres określa obszar kodu, np. `media`.
- Oddziel nagłówek, opcjonalną treść i opcjonalną stopkę pustymi wierszami. Wiersze treści i stopki mają najwyżej 100 znaków. Treść rozpocznij wielką literą i wyjaśnij motywację w trybie rozkazującym i czasie teraźniejszym.
- Powiązane zgłoszenie wskaż przez `Related: project/package#123`. Użyj `Closes: project/package#123` tylko wtedy, gdy zgłoszenie ma zostać zamknięte po dotarciu do gałęzi domyślnej lub wydania. W opisie PR-a GitHuba umieść pełny URL zgłoszenia GitLaba, aby uniknąć niejednoznacznych numerów.
- Zmiany niezgodne wstecz wymagają zarówno `!` przed dwukropkiem, jak i stopki `BREAKING CHANGE:` z wyjaśnieniem oraz ewentualną migracją.

Hooki serwera GitLaba sprawdzają komunikaty i mogą odrzucić push. Przed zaakceptowaniem PR-a GitHuba sprawdź wszystkie przychodzące commity, w tym końcowy komunikat merge lub squash. Nie zakładaj, że późniejszy import naprawi komunikaty.

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

## Styl kodu i sprawdzanie zmian

Użyj działającej instalacji deweloperskiej QUIQQER. Poniższe polecenia uruchom w katalogu pakietu Core (`packages/quiqqer/core`). PHP i rozszerzenia muszą spełniać wymagania `composer.json`; Composer i PHIVE muszą być dostępne. `composer dev:init` instaluje przypięte wersje narzędzi i włączone hooki Git. Uruchom je podczas przygotowania repozytorium, a `composer test` przed przekazaniem zmiany.

Pierwszy blok przygotowuje i sprawdza Core; drugi pokazuje poszczególne kontrole:

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

- PHP stosuje **PSR-12** zgodnie z `phpcs.xml.dist`. Oprócz kontroli stylu uruchom PHPStan i PHPUnit.
- JavaScript stosuje reguły JSHint QUIQQER lub `.jshintrc` pakietu. Zachowaj istniejące konwencje frontendu i sprawdź konsolę przeglądarki przy zmianach interfejsu.
- Stosuj kodowanie wyjścia odpowiednie do kontekstu oraz wiązanie parametrów zapytań do bazy danych. Polecenia zewnętrzne z PHP uruchamiaj przez `Symfony\Component\Process\Process`.
- Aktualizuj dokumentację i tłumaczenia przy zmianach zachowania. Zachowaj istniejącą strukturę plików językowych.
- Dla zmian w pakiecie uruchamiaj jego własne kontrole. Zapisz polecenia, wyniki i kontrole, których nie udało się wykonać. Przed integracją uwzględnij wyniki CI GitLaba i recenzję.

## Otwieranie merge requesta lub pull requesta

Jeden MR/PR powinien dotyczyć jednego problemu. Opisz problem, przyczynę, rozwiązanie, pakiety, testy i wpływ na zgodność. Dodaj odnośnik do zgłoszenia oraz ręczne kroki odtworzenia i testowania. Użyj tego szablonu i zastąp przykładowy URL zgłoszenia.

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

## Lista dla autorów zmian

- [ ] Zgłoszenie jest podlinkowane i wybrano właściwą gałąź rozwojową.
- [ ] Zmiana ma ograniczony zakres; commity są atomowe, a komunikaty zgodne ze standardem.
- [ ] Sprawdzono odtworzenie problemu i dodano lub zaktualizowano testy regresji albo uzasadniono brak pokrycia.
- [ ] Istotne kontrole stylu, analiza statyczna i testy przeszły; wyniki i ograniczenia są opisane.
- [ ] Kroki i wyniki testów ręcznych są opisane.
- [ ] Uwzględniono zgodność, uprawnienia, dane wrażliwe i zmiany publicznych API.
- [ ] W razie potrzeby zaktualizowano dokumentację, tłumaczenia i informacje o wydaniu lub migracji.

## Lista dla recenzentów

- [ ] Zgłoszenie zawiera informacje wystarczające do odtworzenia problemu.
- [ ] Poprawka usuwa przyczynę i jest wystarczająco mała do recenzji.
- [ ] Testy obejmują regresję, a istotne kontrole przeszły.
- [ ] Sprawdzono zgodność, bezpieczeństwo, uprawnienia i przetwarzanie danych.
- [ ] Gałąź docelowa i komunikaty są poprawne, także commity merge/squash z GitHuba przed importem.

## Wydania i dalsze informacje

Opiekunowie zarządzają chronionymi gałęziami, wersjami, tagami i publikacją. Autorzy przesyłają przetestowane zmiany do odpowiedniej gałęzi rozwojowej. Opiekunowie przygotowują wydanie i scalają zmiany do `main` lub właściwej gałęzi utrzymaniowej; automatyzacja ustala wersje i informacje o wydaniu na podstawie komunikatów commitów.

W MR/PR opisz zmiany widoczne dla użytkownika, wycofywane funkcje i wymagane migracje. Dodaj dokumentację lub wpisy changelogu, jeśli wymaga tego repozytorium. Sprawdź jego README i CI, zanim założysz, że inny pakiet stosuje dokładnie narzędzia Core.

- [QUIQQER Developer Workflow](../skills/developer/quiqqer_developer_workflow.md)
- [Anleitung für Entwickler (Deutsch)](https://dev.quiqqer.com/quiqqer/stabilization/documentation/-/wikis/Anleitung-f%C3%BCr-Entwickler)
- [composer.json](../composer.json)
- [phpcs.xml.dist](../phpcs.xml.dist)
- [locale.xml](../locale.xml)
