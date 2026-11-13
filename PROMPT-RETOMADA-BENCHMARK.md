# Prompt para retomar o benchmark

```text
Continue o benchmark de desempenho do repositório `/home/gabigol/viralgraph-benchmark-tcc`.

Objetivo: testar Laravel e Hyperf com o mesmo corpus de alto volume e imprimir os resultados no terminal. Não priorize relatório HTML nem crie testes auxiliares sem necessidade para a medição.

Decisões aprovadas:
- 1 milhão de usuários, 5 milhões de posts, 5 milhões de relações de seguir, 5 milhões de compartilhamentos e até 25 milhões de entradas iniciais de feed.
- Usar a mesma seed, data de referência, carga k6 e orçamento de recursos nas duas arquiteturas.
- Executar uma arquitetura por vez, com ambientes Docker exclusivos do benchmark.
- Fazer três repetições, alternando a ordem das arquiteturas.
- Mostrar por rota requisições/s, p50/p95/p99, falhas e timeouts; mostrar também tempo de carga e um fan-out controlado de um nó central.
- Imprimir a comparação no terminal. Os resultados precisam vir de execuções reais, sem inventar ou inferir dados.

Estado e contexto:
- Há alterações locais não commitadas iniciadas durante o trabalho. Primeiro inspecione `git status`, `git diff` e os arquivos alterados. Preserve essas mudanças; não faça reset, checkout ou limpeza geral.
- Foram iniciados um gerador CSV determinístico, carregadores Laravel/Neo4j, composições Docker isoladas, suporte a sequência/feed no Laravel, integração no `run.py` e um coordenador `code/k6/benchmark.py`.
- A suíte Laravel passou antes das últimas mudanças; a suíte Python passou com k6 2.3.0 em `PATH=/tmp/opencode:$PATH`. O teste do carregador Hyperf passou em container.
- O smoke anterior usou apenas 10 usuários, 50 posts, 50 relações, 50 compartilhamentos e 250 entradas de feed. Laravel carregou e completou o fan-out pequeno, mas o k6 falhou durante a preparação por timeout de 2 s. O timeout de setup foi aumentado depois, mas ainda precisa ser validado.
- Uma tentativa Hyperf foi interrompida. A composição base e o override publicavam também a porta de desenvolvimento 9999; o override foi ajustado com `!override`, mas a correção ainda não foi confirmada por uma execução concluída.
- Os artefatos inválidos estão em `/tmp/opencode/comparison-smoke/`; não os trate como resultados de desempenho.
- O binário k6 está em `/tmp/opencode/k6`.
- Configurações pequenas para smoke estão em `/tmp/opencode/config-small.json` e `/tmp/opencode/dataset-small.json`.
- Não altere o artigo acadêmico.

Como proceder:
1. Inspecione as alterações e confira a configuração Compose efetiva. Verifique também se há containers/portas ocupados, sem parar ou remover qualquer ambiente de desenvolvimento. Só opere nos projetos exatos `tcc-benchmark-sync` e `tcc-benchmark-async`.
2. Faça uma execução curta com as configurações pequenas para validar o fluxo completo nas duas arquiteturas: isolamento, carga, contagens, fan-out, k6 e impressão dos resultados. Corrija somente os bloqueios encontrados, priorizando a medição.
3. Antes de iniciar a carga completa, confira espaço disponível e informe que começará uma execução longa. Dê atualizações breves entre etapas, para que o andamento não fique silencioso.
4. Quando o smoke funcionar, execute o benchmark aprovado com o corpus completo e três repetições por arquitetura, usando a mesma base gerada e alternando a ordem.
5. No final, imprima os resultados por rota e a comparação no terminal. Separe claramente carga inicial, fan-out e carga k6. Se alguma execução for inválida, mostre os dados observados e o motivo; não apresente a comparação como conclusiva.

Não faça commit. Não execute novamente a carga completa se o smoke ainda estiver falhando.
```
