# Contribuir a QUIQQER

Gracias por mejorar QUIQQER. Esta guía explica cómo informar de errores, corregirlos y aportar otros cambios al Core. Para las extensiones, consulta también el README, las herramientas y la configuración de CI del repositorio afectado.

## Idiomas

- [🇬🇧 English](../CONTRIBUTING.md)
- [🇩🇪 Deutsch](CONTRIBUTING-de.md)
- [🇪🇸 Español](CONTRIBUTING-es.md)
- [🇫🇷 Français](CONTRIBUTING-fr.md)
- [🇮🇹 Italiano](CONTRIBUTING-it.md)
- [🇵🇱 Polski](CONTRIBUTING-pl.md)
- [🇵🇹 Português](CONTRIBUTING-pt.md)

## Dónde contribuir

[GitLab](https://dev.quiqqer.com/quiqqer/core) es el repositorio principal y la plataforma preferida para contribuir. Informa de los errores del Core en su [gestor de incidencias](https://dev.quiqqer.com/quiqqer/core/-/issues) y de los errores de paquetes en el repositorio correspondiente. Busca incidencias existentes y enlaza los informes relacionados.

También puedes abrir un pull request en el [mirror de GitHub](https://github.com/QUIQQER/core). Los mantenedores trasladan los cambios aceptados a GitLab. Esta vía no requiere una cuenta de GitLab. Los requisitos de commits, estilo y pruebas son iguales en ambas plataformas. El cambio se considera integrado cuando se completa su incorporación a GitLab.

## Informar de un error

Usa un título claro e incluye las versiones de QUIQQER y de los paquetes, la información relevante de PHP, base de datos, navegador y sistema, los pasos exactos para reproducir el error, el comportamiento esperado y el real, y un ejemplo mínimo. Añade registros sin datos sensibles, trazas, capturas y configuraciones cuando sean útiles. Indica las regresiones, el impacto o las soluciones temporales que conozcas.

«Media no funciona. Arregladlo rápido» no basta: faltan la versión, la reproducción y el resultado esperado. Elimina credenciales, tokens, datos personales y datos de clientes de todos los adjuntos y ejemplos.

Responde a las preguntas y actualiza la incidencia con información nueva. Las incidencias que esperan tu respuesta se cierran tras tres meses sin contestación. Los mantenedores gestionan etiquetas y estados. Las siguientes plantillas están en inglés para facilitar la colaboración entre idiomas; sustituye los datos del ejemplo por los tuyos.

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

## Informar de una vulnerabilidad

Comunica las vulnerabilidades de forma privada a [support@pcsg.de](mailto:support@pcsg.de). Incluye las versiones afectadas, los pasos de reproducción o una prueba de concepto mínima, los requisitos previos y el posible impacto. Envía únicamente la información necesaria para investigar y elimina los datos sensibles.

No publiques detalles de vulnerabilidades en incidencias, PR, MR o canales de Discord públicos. Por ejemplo, el acceso no autorizado a archivos privados debe notificarse por esta vía privada.

## Preparar un cambio

1. Consulta o crea una incidencia y describe la corrección prevista. Acuerda los cambios grandes con los mantenedores antes de implementarlos.
2. Haz un fork y crea una rama específica a partir de la rama `next-*.x` adecuada. Para Core 2.x, usa `next-2.x`. Si existe una incidencia de GitLab, antepón su número al nombre, por ejemplo `123-fix-media-preview`.
3. Dirige el MR o PR a esa misma rama de desarrollo. Los cambios incompatibles pertenecen a la siguiente versión mayor aún no publicada, por ejemplo `next-3.x` mientras 2.x sea estable; confirma el destino con los mantenedores.
4. Reproduce el error, corrige su causa y limita el alcance del cambio. Separa las refactorizaciones y el formato no relacionados. Mantén la compatibilidad con versiones anteriores y documenta los cambios intencionados de API, las obsolescencias y las migraciones.
5. Añade o actualiza pruebas de regresión para las correcciones y pruebas para los comportamientos nuevos. Documenta las pruebas manuales. Explica expresamente cualquier imposibilidad de añadir cobertura automatizada.

Actualiza una rama local de trabajo desde su remoto con `git pull --rebase`. Resuelve conflictos incorporando la rama de destino a tu rama mediante un merge. No reescribas el historial compartido sin acuerdo; revierte cambios publicados con `git revert`.

## Mensajes de commit

Cada commit debe ser una unidad de trabajo completa que se pueda revertir de forma independiente. Escribe todo el mensaje en inglés con esta cabecera:

```text
<type>[(<scope>)][!]: <short summary>
```

- La cabecera completa tiene un máximo de 72 caracteres y no termina en punto.
- El resumen empieza en minúscula y usa el imperativo en presente, como `fix`, no `fixed`.
- Tipos permitidos: `build`, `ci`, `docs`, `feat`, `fix`, `perf`, `refactor`, `revert`, `style`, `test`.
- El ámbito opcional identifica un área del código, por ejemplo `media`.
- Separa la cabecera, el cuerpo opcional y el pie opcional con líneas en blanco. Las líneas del cuerpo y del pie tienen un máximo de 100 caracteres. Empieza el cuerpo con mayúscula y explica la motivación en imperativo y presente.
- Vincula la incidencia con `Related: project/package#123`. Usa `Closes: project/package#123` solo si debe cerrarse al llegar a la rama predeterminada o de publicación. Incluye la URL completa de GitLab en las descripciones de PR de GitHub para evitar números ambiguos.
- Los cambios incompatibles requieren `!` antes de los dos puntos y un pie `BREAKING CHANGE:` con la explicación y, cuando proceda, la migración.

Los hooks del servidor GitLab validan los mensajes y pueden rechazar un push. Antes de aceptar un PR de GitHub, comprueba todos los commits entrantes, incluido el mensaje final de merge o squash. No cuentes con corregirlos durante la importación posterior.

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

## Estilo de código y comprobaciones

Usa una instalación de desarrollo de QUIQQER funcional. Ejecuta estos comandos desde el directorio del paquete Core (`packages/quiqqer/core`). PHP y sus extensiones deben cumplir `composer.json`; Composer y PHIVE deben estar disponibles. `composer dev:init` instala las versiones fijadas de las herramientas y los hooks habilitados. Ejecútalo al preparar el checkout y ejecuta `composer test` antes de entregar un cambio.

El primer bloque prepara y comprueba Core; el segundo muestra las comprobaciones individuales:

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

- PHP sigue **PSR-12**, según `phpcs.xml.dist`. Ejecuta PHPStan y PHPUnit además de comprobar el estilo.
- JavaScript sigue las reglas JSHint de QUIQQER o el `.jshintrc` del paquete. Respeta las convenciones existentes del frontend y revisa la consola del navegador cuando cambie la interfaz.
- Escapa la salida según su contexto y vincula parámetros en las consultas a la base de datos. Ejecuta comandos externos desde PHP mediante `Symfony\Component\Process\Process`.
- Actualiza la documentación y las traducciones cuando cambie el comportamiento. Sigue la estructura existente de los archivos de idioma.
- Para contribuir a un paquete, ejecuta sus propias comprobaciones. Documenta los comandos, los resultados y las comprobaciones que no pudiste ejecutar. Considera los resultados de CI de GitLab y la revisión antes de integrar.

## Abrir un merge request o pull request

Resuelve un único problema por MR/PR. Explica el problema, la causa, la solución, los paquetes afectados, las pruebas y las consecuencias para la compatibilidad. Enlaza la incidencia y documenta los pasos manuales de reproducción y prueba. Usa esta plantilla y sustituye la URL de ejemplo.

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

## Lista para colaboradores

- [ ] La incidencia está enlazada y se ha elegido la rama de desarrollo correcta.
- [ ] El cambio está acotado; los commits son atómicos y sus mensajes cumplen el estándar.
- [ ] Se verificó la reproducción y se añadió o actualizó la cobertura de regresión, o se explicó su ausencia.
- [ ] Pasaron las comprobaciones relevantes de estilo, análisis estático y pruebas; se documentaron resultados y limitaciones.
- [ ] Se documentaron los pasos y los resultados de las pruebas manuales.
- [ ] Se consideraron la compatibilidad, los permisos, los datos sensibles y los cambios en API públicas.
- [ ] Se actualizaron la documentación, las traducciones y las notas de publicación o migración necesarias.

## Lista para revisores

- [ ] La incidencia contiene información suficiente para reproducir el problema.
- [ ] La corrección resuelve la causa y es lo bastante pequeña para revisarla.
- [ ] Las pruebas cubren la regresión y las comprobaciones relevantes han pasado.
- [ ] Se revisaron la compatibilidad, la seguridad, los permisos y el tratamiento de datos.
- [ ] La rama de destino y los mensajes son válidos, incluidos los commits de merge o squash de GitHub antes de importarlos.

## Publicaciones y más información

Los mantenedores gestionan las ramas protegidas, las versiones, las etiquetas y la publicación. Los colaboradores entregan cambios probados a la rama de desarrollo adecuada. Los mantenedores preparan la publicación y hacen el merge en `main` o en la rama de mantenimiento correspondiente; la automatización determina las versiones y las notas a partir de los mensajes de commit.

Describe en el MR/PR los cambios visibles, las obsolescencias y las migraciones necesarias. Añade documentación o notas al changelog cuando lo exija el repositorio. Consulta su README y su configuración de CI antes de asumir que otro paquete usa exactamente las herramientas del Core.

- [Documentación de QUIQQER](https://www.quiqqer.com/docs/)
- [Flujo de desarrollo](https://www.quiqqer.com/docs/developer/development-workflow)
- [Estándares de código](https://www.quiqqer.com/docs/developer/coding-standards)
- [Pruebas y calidad](https://www.quiqqer.com/docs/developer/testing-and-quality)
- [composer.json](../composer.json)
- [phpcs.xml.dist](../phpcs.xml.dist)
- [locale.xml](../locale.xml)
