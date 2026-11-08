# Teste de estresse com k6

Implementação do perfil de chegadas da [Rinha 2023 Q3](https://github.com/zanfranceschi/rinha-de-backend-2023-q3/blob/main/stress-test/user-files/simulations/rinhabackend/RinhaBackendSimulation.scala), adaptado ao [contrato social](../docs/contrato-api.md) e ao [protocolo local](../docs/teste-estresse.md). Não reproduz a aplicação de pessoas da Rinha nem constitui resultado experimental do TCC.

## Executar

Requisitos: k6 (validado com 2.3.0), Python 3.11 ou superior, Git e uma API que implemente o contrato. Sem bibliotecas Python adicionais. O backend e seu balanceador precisam estar disponíveis; este diretório não os provisiona.

**A execução grava dados. Use uma base experimental vazia.** O script verifica `/metricas` antes da preparação, cria os usuários e as relações e espera as contagens da base estabilizarem. Não apaga nem restaura bancos. Nunca apontar para uma base de uso real.

Na raiz do repositório:

```bash
python3 code/k6/run.py \
  --base-url http://127.0.0.1:9999 \
  --architecture sync
```

Para a composição assíncrona, usar `--architecture async`, a URL correspondente e a mesma configuração. O parâmetro identifica a execução; não detecta nem valida a arquitetura. Cada chamada executa uma repetição. Restaurar a base e alternar a ordem das composições entre repetições, conforme o protocolo.

Os artefatos ficam em `code/k6/results/<instante-UTC>/`. Para escolher outro diretório novo, usar `--output /caminho/execucao`. Diretórios existentes são rejeitados. O código de saída do k6 é preservado: falhas de thresholds também geram relatório e retornam código diferente de zero. Falha de inicialização pode gerar relatório incompleto; interrupção abrupta do próprio runner pode impedir a consolidação.

O endpoint deve receber a data de referência de `config.json` na sua própria configuração. O gerador registra essa data; não altera o relógio ou a configuração do backend.

## Configuração e cenários

Copie `config.json` e passe `--config caminho/config.json`. Os valores fornecidos são um ponto de partida **(assumed)** para desenvolver/calibrar o teste, não parâmetros experimentais validados.

| Campo | Função |
| --- | --- |
| `scenario` | `reference`, `fanout`, `cascade` ou `growth` |
| `users`, `seed` | População inicial e escolhas determinísticas de ações/payloads |
| `rate_scale`, `time_scale` | Multiplicadores de taxas e duração; `1` preserva o perfil original |
| `preallocated_vus`, `max_vus` | VUs por fluxo, não limite global; há três fluxos e um VU de auditoria |
| `request_timeout_ms` | Timeout por requisição |
| `feed_poll_ms`, `feed_wait_ms` | Intervalo entre varreduras do feed e espera máxima por participante |
| `setup_wait_ms` | Prazo de espera por usuário/feed e estabilização das contagens |
| `recovery_seconds` | TF programado = fim das chegadas + recuperação |
| `graceful_stop_seconds` | Prazo para concluir ações em voo; deve ser <= recuperação |
| `post_fraction` | Fração de ações sociais que publica; demais consultam feed |
| `share_fraction` | Probabilidade determinística de continuar cada etapa da cascata |
| `bucket_seconds` | Janela das séries temporais do relatório |
| `reference_date` | Data de referência para idade, a configurar igualmente nos backends |

As taxas são arredondadas para inteiros, com mínimo de uma chegada/s. Use `time_scale` para encurtar smoke tests; a escala de taxa não desativa fluxos. `post_fraction` não é usada em `growth`. Frações não prometem proporções exatas em amostras pequenas.

- `reference`: anel, com cada usuário seguindo o anterior; publicações distribuídas entre autores. Após entrega ao próximo usuário, pode haver compartilhamento.
- `fanout`: estrela, com todos seguindo o usuário central; publicações do centro e leituras de feed.
- `cascade`: cadeia de recebimento `0 → 1 → … → N-1`. Publicações do centro; cada participante espera o original no feed e compartilha. O fluxo termina quando há falha, timeout ou decisão de não continuar.
- `growth`: base em cadeia, seguida de criação de usuários e relações com o usuário central; sem publicações na faixa de crescimento.

Cada criação de post com 201 é seguida de uma única consulta após pausa determinística de 1–30 ms, validando ID, autor e conteúdo. Não há retry de escrita ou de consulta pós-criação. Feeds são percorridos por cursor dentro da espera máxima, sem presumir que o post estará na primeira página. Cada timeout HTTP conserva a incerteza de persistência.

A preparação usa polling fora da carga medida. A série de negócio exclui preparação e auditoria; o resumo bruto k6 também contém essas fases. Os logs registram a correspondência entre índices lógicos, atores e IDs retornados, que podem diferir entre arquiteturas. Não se usa UUID de uma arquitetura na outra. Consultas preparatórias de disponibilidade são apenas verificações de HTTP; não substituem o cenário A completo do protocolo.

## Perfil da Rinha

Os três fluxos são simultâneos, em modelo aberto:

| Fluxo | Fase inicial | Rampa seguinte |
| --- | --- | --- |
| Social | 2 ações/s por 10 s; 5/s por 15 s | 6 → 600 ações/s por 180 s |
| Busca válida | 2 ações/s por 25 s | 6 → 100 ações/s por 180 s |
| Busca inválida | 2 ações/s por 25 s | 6 → 40 ações/s por 180 s |

São 205 segundos de chegadas e pico agregado de 740 ações/s, sem patamar. As transições instantâneas são estágios de duração zero do k6. As proporções da carga e a aleatoriedade do Gatling não são reproduzidas literalmente: este gerador usa escolhas determinísticas pela semente e pelo índice lógico. Uma ação social pode fazer várias requisições; não confundir ações/s, requisições/s e criações lógicas no banco.

Buscas válidas alternam `/usuarios?s=Pessoa` e `/posts?c=viral`. O fluxo inválido alterna os mesmos endpoints sem termo e exige **422**, conforme o contrato local, em vez do 400 da Rinha. Esse volume intencional de erros de validação está declarado no manifesto e aparece na distribuição de status, mas não conta como falha operacional. Corpos incorretos falham nos checks mesmo com o status esperado.

`dropped_iterations > 0` invalida a aplicação do perfil. Ações iniciadas que não terminaram aparecem como interrompidas. O integral programado é uma expectativa matemática do envelope, não uma alegação de número inteiro exato de ações que o executor agendará. Falhas do sistema aparecem nos checks/thresholds e nas métricas, separadas de falhas do gerador.

## Relatório para visualização

`report.json` é um documento JSON único, com `schema_version: 1`:

| Campo | Uso |
| --- | --- |
| `manifest` | URL, composição declarada, commit, estado sujo do checkout, hash do script, versão k6 e parâmetros |
| `arrival_profile` | Segmentos programados por fluxo, para sobrepor a carga oferecida aos gráficos |
| `actions` | Integral programado, iniciadas, concluídas, interrompidas e descartadas por fluxo |
| `endpoints` | Latências min/média/p50/p90/p95/p99/max, status, falhas HTTP e códigos de erro, por fluxo/rota |
| `timeline` | Requisições/s, falhas, status, latência, ações, descartes e VUs em janelas de tempo |
| `prepared` | Mapeamento de usuários, arestas `[seguidor, seguido]` e contagens da base T0 |
| `time.cuts` | Observações T1, TA e TF com timestamps reais, contagens e deltas de T0 |
| `k6_summary` | Resumo original, checks, thresholds e métricas de espera de feed/timeouts |
| `measurement` | Estado de execução, thresholds, motivos de invalidação e validações pendentes |
| `infrastructure` | `not_measured` e valores `null` para sinais ainda não coletados |

Unidades: segundos nos eixos/janelas, milissegundos nas latências e timestamps terminados em `_ms`; taxas em requisições/s. O eixo temporal começa no `startTime` do cenário k6. Cada janela tem largura fixa, inclusive a última; janelas vazias têm zero requisições e latência `null`. Os percentis usam interpolação linear sobre amostras completas; não se calcula a média dos percentis de janelas. `routes_latency_ms` usa a chave `fluxo|rota`. `vus_max` é o máximo de VUs de todo o processo k6, incluindo o observador. `failed` indica status inesperado; validações semânticas estão nos checks do resumo.

Os pontos HTTP são datados no término da requisição, conforme a saída do k6. Portanto, o gráfico representa requisições concluídas/s, não o instante de chegada de cada requisição. `actions_started` representa chegadas efetivamente iniciadas. Erros de transporte têm status `0` e código próprio. Respostas 201 representam aceitação, não confirmação de persistência.

Também são preservados `manifest.json`, `summary.json`, `metrics.jsonl`, `actions.jsonl` e `output.log`. Os dois `.jsonl` contêm um objeto JSON por linha. O journal de ações registra payloads sintéticos, IDs retornados e resultados das requisições; IDs não são tags de métricas, evitando cardinalidade por usuário/post.

A consolidação lê os pontos em sequência e retém amostras de latência para percentis exatos. Logs e pontos podem ocupar bastante disco; dimensionar o gerador e o armazenamento antes das execuções comparativas. Não coletar esses arquivos no orçamento de CPU/RAM do backend.

## Limites da medição atual

**O relatório ainda não habilita uma comparação oficial do TCC.** `official_comparison_ready` e `TF_preserved` permanecem `false`:

- T1/TF são consultas observacionais a `/metricas`, executadas por um cenário independente; o timestamp real da resposta é preservado. Não há snapshot ou interrupção coordenada de escritas. Uma consulta pode incorporar gravações posteriores ao corte programado; não tratá-la como pontuação final válida.
- TA é observado em `teardown`, depois do encerramento dos cenários, inclusive o observador de TF. Portanto, neste gerador TA pode ser posterior a TF; não equivale ao instante em que o último fluxo de negócio terminou.
- O gerador verifica soma/componentes de `/metricas` e calcula deltas; não verifica o banco, a durabilidade, a cobertura de destinatários, duplicações concorrentes, ordenação completa ou leitura forçada pela outra instância. O cenário A completo continua pendente.
- Não há coleta de CPU, RAM, disco, filas ou traces, nem validação automática de imagens/limites efetivos do backend. Ausência é `null`, nunca zero simulado.
- A preparação gera topologias sintéticas simples. Não afirma representar uma rede social real nem mede por si só a centralidade do grafo.

O backend ainda não existe neste checkout. Esses limites dependem de implementação/integração posterior, sem necessidade de inventar endpoints adicionais ao contrato aprovado.

## Verificação local

```bash
python3 -m unittest discover -s code/k6/tests -v
```

Os testes usam k6 real com perfis curtos contra uma API em memória, exclusiva para testes: verificam os quatro cenários, os JSONs, os deltas, buscas 422 esperadas, cascata após entrega no feed e falha semântica com HTTP 200. Não medem desempenho real nem validam Laravel, Hyperf ou o corte durável.

## Fontes técnicas

- [Simulação oficial da Rinha](https://github.com/zanfranceschi/rinha-de-backend-2023-q3/blob/main/stress-test/user-files/simulations/rinhabackend/RinhaBackendSimulation.scala).
- [k6: ramping-arrival-rate](https://grafana.com/docs/k6/latest/using-k6/scenarios/executors/ramping-arrival-rate/).
- [k6: saída JSON](https://grafana.com/docs/k6/latest/results-output/real-time/json/).
- [k6: handleSummary](https://grafana.com/docs/k6/latest/results-output/end-of-test/custom-summary/).

Fontes registradas em `citations.md`; não inseridas no artigo acadêmico.
