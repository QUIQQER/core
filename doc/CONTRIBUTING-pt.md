# Contribuir para o QUIQQER

Obrigado por melhorar o QUIQQER. Este guia explica como comunicar erros, propor correções e contribuir para o Core. Para extensões, consulte também o README, as ferramentas e a configuração de CI do repositório em causa.

## Idiomas

- [🇬🇧 English](../CONTRIBUTING.md)
- [🇩🇪 Deutsch](CONTRIBUTING-de.md)
- [🇪🇸 Español](CONTRIBUTING-es.md)
- [🇫🇷 Français](CONTRIBUTING-fr.md)
- [🇮🇹 Italiano](CONTRIBUTING-it.md)
- [🇵🇱 Polski](CONTRIBUTING-pl.md)
- [🇵🇹 Português](CONTRIBUTING-pt.md)

## Onde contribuir

O [GitLab](https://dev.quiqqer.com/quiqqer/core) é o repositório de referência e a plataforma preferida para contribuir. Comunique erros do Core no respetivo [gestor de issues](https://dev.quiqqer.com/quiqqer/core/-/issues) e erros de pacotes nos seus repositórios. Procure primeiro issues existentes e associe os relatos relacionados.

Também pode abrir um pull request no [espelho do GitHub](https://github.com/QUIQQER/core). Os responsáveis pela manutenção transferem as alterações aceites para o GitLab. Esta opção não exige uma conta GitLab. As mesmas regras de commits, estilo e testes aplicam-se em ambas as plataformas. A alteração é considerada integrada quando a transferência para o GitLab estiver concluída.

## Comunicar um erro

Use um título claro e indique as versões do QUIQQER e dos pacotes, informações relevantes sobre PHP, base de dados, navegador e sistema, passos exatos para reproduzir o erro, comportamento esperado e observado e um exemplo mínimo. Acrescente registos sem dados sensíveis, stack traces, capturas e configurações quando forem úteis. Refira regressões, impacto ou soluções temporárias conhecidos.

«O Media não funciona. Corrijam depressa.» não basta: faltam a versão, a reprodução e o resultado esperado. Remova credenciais, tokens, dados pessoais e dados de clientes de todos os anexos e exemplos.

Responda às perguntas e atualize a issue com novas informações. As issues que aguardam a sua resposta são encerradas após três meses sem resposta. Os responsáveis pela manutenção gerem etiquetas e estados. Os modelos seguintes estão em inglês para facilitar a colaboração entre idiomas; substitua os dados de exemplo pelos seus.

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

## Comunicar uma vulnerabilidade

Comunique vulnerabilidades em privado para [support@pcsg.de](mailto:support@pcsg.de). Inclua versões afetadas, passos de reprodução ou uma prova de conceito mínima, pré-requisitos e impacto possível. Envie apenas as informações necessárias à investigação, sem dados sensíveis.

Não publique detalhes de vulnerabilidades em issues, PRs, MRs ou canais públicos do Discord. Por exemplo, o acesso não autorizado a ficheiros privados deve ser comunicado em privado.

## Preparar uma alteração

1. Consulte ou crie uma issue e descreva a correção prevista. Combine alterações maiores com os responsáveis pela manutenção antes de as implementar.
2. Crie um fork e um branch específico a partir do branch `next-*.x` adequado. Para Core 2.x, use `next-2.x`. Se existir uma issue GitLab, coloque o seu número no início do nome, por exemplo `123-fix-media-preview`.
3. Direcione o MR ou PR para o mesmo branch de desenvolvimento. Alterações incompatíveis pertencem à próxima versão principal ainda não publicada, por exemplo `next-3.x` enquanto 2.x for estável; confirme o destino com os responsáveis pela manutenção.
4. Reproduza o erro, corrija a causa e limite a alteração. Separe refatorizações e formatações não relacionadas. Preserve a compatibilidade com versões anteriores e documente alterações intencionais de API, descontinuações e migrações.
5. Acrescente ou atualize testes de regressão para correções e testes para novos comportamentos. Documente testes manuais. Explique expressamente qualquer impossibilidade de adicionar cobertura automatizada.

Atualize um branch local a partir do seu remoto com `git pull --rebase`. Resolva conflitos integrando o branch de destino no seu branch de trabalho através de um merge. Não reescreva o histórico partilhado sem acordo; reverta alterações publicadas com `git revert`.

## Mensagens de commit

Cada commit deve representar uma unidade de trabalho completa, que possa ser revertida de forma independente. Escreva toda a mensagem em inglês com este cabeçalho:

```text
<type>[(<scope>)][!]: <short summary>
```

- O cabeçalho completo tem no máximo 72 caracteres e não termina com ponto.
- Comece o resumo com uma letra minúscula e use o imperativo no presente, como `fix`, não `fixed`.
- Tipos permitidos: `build`, `ci`, `docs`, `feat`, `fix`, `perf`, `refactor`, `revert`, `style`, `test`.
- O scope opcional identifica uma área do código, por exemplo `media`.
- Separe cabeçalho, corpo opcional e rodapé opcional com linhas em branco. As linhas do corpo e do rodapé têm no máximo 100 caracteres. Comece o corpo com uma maiúscula e explique a motivação no imperativo e no presente.
- Associe uma issue com `Related: project/package#123`. Use `Closes: project/package#123` apenas quando a issue deva ser encerrada ao chegar ao branch predefinido ou de lançamento. Nas descrições de PRs GitHub, inclua o URL completo da issue GitLab para evitar números ambíguos.
- Alterações incompatíveis exigem `!` antes dos dois pontos e um rodapé `BREAKING CHANGE:` que explique a alteração e a eventual migração.

Os hooks do servidor GitLab validam as mensagens e podem rejeitar um push. Antes de aceitar um PR GitHub, verifique todos os commits recebidos, incluindo a mensagem final de merge ou squash. Não conte com a importação posterior para corrigir as mensagens.

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

## Estilo de código e verificações

Use uma instalação de desenvolvimento QUIQQER funcional. Execute os comandos seguintes no diretório do pacote Core (`packages/quiqqer/core`). PHP e as extensões devem cumprir `composer.json`; Composer e PHIVE devem estar disponíveis. `composer dev:init` instala as versões fixadas das ferramentas e os hooks Git ativados. Execute-o ao preparar o checkout e execute `composer test` antes de entregar uma alteração.

O primeiro bloco prepara e verifica o Core; o segundo apresenta as verificações individuais:

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

- PHP segue **PSR-12**, conforme `phpcs.xml.dist`. Execute PHPStan e PHPUnit além da verificação de estilo.
- JavaScript segue as regras JSHint do QUIQQER ou o `.jshintrc` do pacote. Respeite as convenções existentes do frontend e verifique a consola do navegador nas alterações à interface.
- Escape a saída de acordo com o contexto e use associação de parâmetros nas consultas à base de dados. Execute comandos externos a partir de PHP através de `Symfony\Component\Process\Process`.
- Atualize a documentação e as traduções quando o comportamento mudar. Siga a estrutura existente dos ficheiros de idioma.
- Para contribuir para um pacote, execute as suas verificações específicas. Registe comandos, resultados e verificações não executadas. Considere os resultados da CI GitLab e da revisão antes da integração.

## Abrir um merge request ou pull request

Trate um problema por MR/PR. Explique o problema, a causa, a solução, os pacotes afetados, os testes e as implicações para a compatibilidade. Associe a issue e documente os passos manuais de reprodução e teste. Use este modelo e substitua o URL da issue de exemplo.

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

## Lista para contribuidores

- [ ] A issue está associada e o branch de desenvolvimento correto foi escolhido.
- [ ] A alteração é focada; os commits são atómicos e as mensagens seguem o padrão.
- [ ] A reprodução foi verificada e a cobertura de regressão foi acrescentada ou atualizada, ou a ausência foi explicada.
- [ ] As verificações relevantes de estilo, análise estática e testes passaram; resultados e limitações estão registados.
- [ ] Os passos e resultados dos testes manuais estão documentados.
- [ ] Foram considerados compatibilidade, permissões, dados sensíveis e alterações a APIs públicas.
- [ ] Documentação, traduções e notas de lançamento ou migração foram atualizadas quando necessário.

## Lista para revisores

- [ ] A issue contém informações suficientes para reproduzir o problema.
- [ ] A correção resolve a causa e é suficientemente pequena para ser revista.
- [ ] Os testes cobrem a regressão e as verificações relevantes passaram.
- [ ] Compatibilidade, segurança, permissões e tratamento de dados foram revistos.
- [ ] O branch de destino e as mensagens são válidos, incluindo commits GitHub de merge/squash antes da importação.

## Lançamentos e mais informações

Os responsáveis pela manutenção gerem branches protegidos, versões, tags e publicações. Os contribuidores submetem alterações testadas ao branch de desenvolvimento adequado. Os responsáveis preparam o lançamento e fazem o merge para `main` ou para o branch de manutenção apropriado; a automatização determina versões e notas a partir das mensagens de commit.

Descreva no MR/PR as alterações visíveis, descontinuações e migrações necessárias. Acrescente documentação ou notas ao changelog quando o repositório o exigir. Consulte o README e a configuração de CI antes de assumir que outro pacote usa exatamente as ferramentas do Core.

- [Documentação do QUIQQER](https://www.quiqqer.com/docs/)
- [Fluxo de desenvolvimento](https://www.quiqqer.com/docs/developer/development-workflow)
- [Padrões de código](https://www.quiqqer.com/docs/developer/coding-standards)
- [Testes e qualidade](https://www.quiqqer.com/docs/developer/testing-and-quality)
- [composer.json](../composer.json)
- [phpcs.xml.dist](../phpcs.xml.dist)
- [locale.xml](../locale.xml)
