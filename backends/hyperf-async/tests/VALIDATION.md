# Validação local — 8 nov. 2026

Esta verificação é de implementação, não uma execução experimental oficial nem resultado comparativo do TCC. Usa os limites iniciais **(assumed)** do Compose.

## Verificado

- Build da imagem com Composer lock, PHP 8.4.16, Swoole 6.2.2 e dependências Hyperf 3.2. `composer validate --strict`, `check-platform-reqs` e `audit` passaram; nenhum aviso de vulnerabilidade na consulta.
- Compose saudável com duas APIs, worker, Nginx, RabbitMQ e Neo4j. Em volumes novos, a API 1 inicializou as constraints antes da liberação da API 2 e do worker, sem disputa de schema. Seis consultas consecutivas ao Nginx alternaram as duas APIs, identificadas por `X-Instance`.
- `bin/check-budget.py`: limites efetivos de 1,50 CPU/3000 MiB e zero swap adicional, conferidos com `docker inspect`. A configuração com `API_CPUS=0.30` foi rejeitada por exceder 1,50 CPU.
- `tests/input.php`: calendário, aniversário em 29/02, maioridade, NFC, pontos de código e rejeição de cursores malformados.
- Suíte real de integração: sete testes passaram em 122,295 s com o bootstrap e Compose finais. Inclui todas as rotas, tipos/campos desconhecidos, limites, erros HTTP/JSON, leitura entre APIs, apelido NFC concorrente, relações e compartilhamentos concorrentes, buscas e feed em duas páginas, cascata, exclusão do autor, primeira entrega imutável, origem alternativa, ausência de histórico para novos seguidores, distribuição atrasada e fan-out de 101 seguidores em lotes de 100.
- Recuperação real: worker parado aceita post com 201 e leitura 404; commit sem ACK torna o recurso consultável pela outra API; reinício e replay não duplicam contagens. RabbitMQ parado produz 503 na criação de post; compartilhamento persiste sua outbox e alcança o seguidor após retomada do broker. Recriar o container RabbitMQ, com hostname fixo e o mesmo volume, preservou um post confirmado ainda pendente; após iniciar o worker, a API 2 retornou 200.
- Cinco testes existentes em `code/k6/tests/test_stress.py` passaram, incluindo k6 real contra a fixture do gerador. Os arquivos do gerador não foram alterados.
- Sintaxe PHP, Python e shell e leitura do OpenAPI YAML verificadas; `git diff --check` sem erros. Não existem `resources/views` ou `app/Livewire` neste checkout.

## Refatoração para `app/`

O red/green começou com `tests/worker.php` falhando porque os componentes separados ainda não existiam. Depois da extração, os testes locais passaram, cobrindo falha de commit sem ACK, JSON inválido sem ACK, publicação recusada sem marcar outbox e encerramento de distribuição sem confirmar tarefa incompleta. `tests/services.php` verifica regras, precedência de recursos inexistentes, maioridade e conexão RabbitMQ independente por operação, fechada também em falhas.

A primeira tentativa de estabelecer a referência encontrou uma imagem antiga em execução: nela, POST de post gravava diretamente no Neo4j, divergindo do código local com RabbitMQ. Essa tentativa teve três falhas e um erro. Após rebuild e recriação apenas dos containers da aplicação, preservando os volumes, os sete testes originais passaram em 97,880 s, antes de executar a versão refatorada. O teste adicional de compatibilidade de roteamento passou também na versão anterior.

O código foi migrado para `app/` com PSR-4, bootstrap `bin/hyperf.php`, controllers explícitos, dependências por construtor, serviços, repositórios específicos, infraestrutura e handler único. O worker mantém o mecanismo de coleta e reconexão anterior, com responsabilidades extraídas. Nenhuma dependência foi adicionada ou atualizada.

Na versão refatorada, os oito testes de integração (sete originais e compatibilidade de roteamento) passaram em 263,724 s. Após o build final e recriação das APIs e do worker, o teste adicional de publicação concorrente passou em 3,566 s: doze posts publicados por seis clientes concorrentes, com IDs distintos e conteúdo persistido conferido. Os três testes PHP (`input.php`, `services.php` e `worker.php`) passaram também na imagem final. Esses tempos são duração da verificação, não medição de desempenho.

Sintaxe PHP, autoload otimizado, `composer validate --strict`, requisitos de plataforma e `git diff --check` passaram. A comparação das consultas extraídas confirmou preservação das transações de schema, persistência, distribuição e métricas. Os seis serviços ficaram saudáveis e `bin/check-budget.py` confirmou 1,50 CPU/3000 MiB, sem swap adicional. Os volumes foram preservados. O smoke k6 abaixo não foi repetido nesta refatoração; nenhuma melhoria de desempenho foi medida.

## Stress completo após a refatoração

Executado com `code/k6/run.py --architecture async`, configuração padrão sem alterações: cenário `cascade`, 12 usuários, seed 42, 205 s de chegadas, pico programado de 740 ações/s, timeout HTTP de 2000 ms, recuperação de 10 s e até 500 VUs por fluxo. Projeto isolado `tcc-hyperf-stress-20261108-1947`, volumes novos e `/metricas` inicialmente zerado. O ambiente de desenvolvimento foi pausado durante a carga; seus volumes foram preservados. Inventário da imagem e limites efetivos registrados: 1,50 CPU/3000 MiB, sem swap adicional. Outros serviços do host permaneceram ativos; não houve isolamento exclusivo da máquina.

O k6 terminou com código 99. Foram 70.530 requisições de negócio, com 66.157 status inesperados (93,80%). Os 422 esperados das buscas inválidas não entram nessa falha. Os thresholds de checks e de ações descartadas falharam: 468 descartes sociais, 59 de busca válida e três de busca inválida, além de duas ações sociais interrompidas. Portanto, o relatório está `invalid`: o envelope programado não foi aplicado integralmente. O pico observado de 913 respostas/s inclui falhas e não representa capacidade útil.

| Rota/fluxo | Requisições | Respostas esperadas | p95 HTTP (ms) |
| --- | ---: | ---: | ---: |
| POST de post | 27.061 | 755 com 201 | 2000,89 |
| Feed | 28.990 | 1.573 com 200 | 2000,91 |
| Busca válida de posts | 4.765 | 283 com 200 | 2000,89 |
| Busca válida de usuários | 4.765 | 277 com 200 | 2000,86 |
| GET após criação | 755 | zero com 200 | 2000,84 |

Nas consultas pós-criação houve 533 respostas 404, 124 falhas de transporte, 97 respostas 503 e uma 502. Predominaram 503 e timeouts nas demais rotas principais. Esses percentis incluem respostas com falha e timeouts; não são latência de operações bem-sucedidas.

Coleta externa por `docker stats`, com timestamp e seleção dos seis containers: Neo4j consumiu em média 0,510 CPU (aproximadamente toda a quota de 0,50) e atingiu 643,3 MiB. Nas 410 amostras durante as chegadas, ficou acima de 90% da quota. As APIs chegaram a 81,17/94,27 MiB, RabbitMQ a 93,31 MiB e worker a 28,66 MiB. Pequenos excessos instantâneos de quota no Docker são amostragem, não alteração dos limites. Os logs das duas APIs registraram `exceed max number of coroutine 256`; Neo4j registrou conexões de resposta encerradas. Ao fim da observação, os seis containers estavam saudáveis, sem OOM ou reinícios. **(inferred)** Há saturação de CPU/acesso ao Neo4j associada ao acúmulo de requisições e esgotamento de corrotinas; esta execução não isolou a consulta ou transporte responsável.

Trinta segundos após o encerramento do runner, havia 3.452 posts persistidos, 133 entradas de feed, 2.721 posts e 3.346 tarefas de distribuição prontas nas filas. Uma consulta posterior ao RabbitMQ encontrou zero posts pendentes e 5.739 tarefas de distribuição prontas, mais uma sem ACK. Antes de encerrar o projeto, `/metricas` registrou 6.229 posts e 1.203 entradas. As consultas são sequenciais e observacionais, sem corte atômico. Posts persistidos superam os 201 observados: timeout do cliente não garante cancelamento da escrita, e não se deve inferir perda ou durabilidade integral dessas contagens. O processamento retomou, mas a recuperação completa da distribuição não foi aguardada. O projeto de stress foi encerrado preservando os volumes e suas pendências; o ambiente padrão foi restaurado.

Artefatos ignorados pelo Git em `tests/results/stress-refactor-20261108/`: relatório k6 e dados brutos em `run/`, `inventory.json`, `docker-stats.jsonl`, amostras de cgroup, `generator-time.txt`, `recovery.jsonl`, `queues-final.txt`, logs completos, `analysis.json` e gráfico `desempenho.png`. `/usr/bin/time` registrou 3 min 46,84 s de runner, máximo RSS de 308.156 KiB e nenhum swap; isso não mede o pico agregado de todos os processos do host. A coleta externa não altera os campos `not_measured` do relatório original. Sem referência anterior equivalente, repetições ou auditoria de integridade, não há conclusão sobre regressão da refatoração, capacidade máxima ou comparação oficial do TCC. Nenhum ajuste de código ou configuração foi feito para melhorar o resultado.

## Smoke do k6 existente

Executado contra o projeto separado `tcc-hyperf-smoke-final-20261108`, com volumes novos e os mesmos limites efetivos conferidos. Configuração curta: cenário `cascade`, seed 42, três usuários, data de referência 2026-11-08, escalas de taxa e tempo 0,02, três VUs pré-alocados, máximo 20, timeout 2000 ms, consulta de feed a cada 100 ms por até 3000 ms, setup 10000 ms, recuperação e graceful stop de um segundo, frações de post e compartilhamento 1 e buckets de um segundo.

O processo terminou com código 99 e relatório `invalid`: 13 criações de post responderam 201, e as dez consultas imediatas de post realizadas retornaram 404. Houve dez iterações descartadas e 13 ações sociais interrompidas. Os thresholds `checks: rate==1` e `dropped_iterations: count==0` falharam. O gerador e seu critério de consulta única após 1–30 ms foram preservados; este smoke não demonstra aprovação nesse critério nem capacidade do backend.

Artefatos locais, ignorados pelo Git: `tests/results/k6-smoke-final-20261108/`, incluindo configuração no manifesto, requisições, saída e `report.json`. O manifesto automático não incorpora a conferência externa de limites feita com `bin/check-budget.py`; tampouco mede recursos do gerador. O projeto de smoke foi encerrado preservando seus volumes, e o projeto padrão foi restaurado.

## Validação com FormRequest

Adicionado `hyperf/validation` 3.2.5, com 13 dependências novas no lock e sem atualizar versões das dependências existentes. Os controllers utilizam FormRequests e dados validados; permanecem verificações específicas de JSON, campos extras, normalização NFC, maioridade e cursores. O handler preserva o corpo de erro 422 do contrato.

Os dez testes de integração passaram em 117,027 s, incluindo recuperação do broker, processamento assíncrono e o novo teste de validação. Após simplificar as verificações manuais de campos obrigatórios e tipo do cursor, a imagem foi reconstruída e os testes de validação e FormRequests passaram novamente. O último inclui doze consultas concorrentes à mesma API e classe de request, alternando entradas válidas e inválidas para conferir o isolamento por corrotina. Uma tentativa durante a recriação dos containers falhou ao interpretar uma resposta sem JSON; a repetição após todos os serviços estarem saudáveis passou em 4,818 s.

Os três testes PHP passaram na imagem final. Build, sintaxe PHP, `composer validate --strict`, requisitos de plataforma, auditoria de dependências, orçamento do Compose e `git diff --check` passaram durante a migração. Não foi executada carga de desempenho nesta alteração; os resultados anteriores de stress não validam o desempenho com o novo componente. Artigo e template não foram alterados.

## Limites

A auditoria precisa integrar preservação exata do corte final e reconstrução de validade. `/metricas` fornece contagens observacionais persistidas, sem substituir essa auditoria. Não foram medidos consumo efetivo, capacidade máxima, desempenho comparativo ou resiliência experimental. Artigo e template LaTeX não foram alterados.

## Campos extras ignorados

Por decisão dos autores, campos desconhecidos no JSON ou na query passam a ser ignorados. O contrato Markdown e o OpenAPI foram atualizados. Removida a rejeição manual e o wrapper `RequestInput`, sem outros usos; os controllers seguem consumindo `validated()`, e o ID da rota prevalece sobre um eventual `id` da query.

Após rebuild e recriação das APIs e worker, os dois testes de validação passaram em 6,931 s. Incluem criação com campo extra de objeto, query extra no POST, descarte do campo na leitura, query extra em busca, recurso, feed e métricas, além das regras obrigatórias e isolamento concorrente. `tests/input.php`, sintaxe dos três arquivos PHP afetados, sintaxe Python, leitura do OpenAPI YAML e `git diff --check` passaram. A suíte completa e carga de desempenho não foram repetidas nesta alteração.

## Remoção de validações e parsing redundantes

O corpo POST passa a usar `getParsedBody()` do Hyperf, eliminando a segunda decodificação JSON e `Input::body()`. Permanecem as exigências do contrato para mídia, corpo vazio e objeto JSON. Removidos `Input::uuid()` em favor de `Str::isUuid()`, regex de conteúdo nos nomes já normalizados, verificações de tipo/formato/calendário de nascimento já cobertas pelo FormRequest, comparação de nascimento futuro já implicada pela maioridade e parâmetros de rota sem uso nos controllers. Mantidos o tratamento de 29 de fevereiro, a geração local de UUID e o transporte AMQP com garantias específicas de publicação e persistência. Nenhuma dependência foi adicionada.

Após rebuild das APIs e worker, os três testes PHP passaram. A suíte de integração ampliada passou com dez testes em 151,776 s, incluindo entradas JSON vazias e não objetuais, espaços Unicode, isolamento concorrente, paginação, ACK após persistência, indisponibilidade e recriação do broker. A primeira execução teve cinco erros ao interpretar respostas sem JSON: o Nginx ainda apontava para um endereço antigo após recriar as APIs, conforme os logs de conexão recusada e timeout. Após reiniciar o Nginx e aguardar todos os serviços saudáveis, a repetição completa passou. Volumes foram preservados.

Sintaxe PHP de `app/`, sintaxe Python, limites efetivos de CPU/memória/swap e `git diff --check` passaram. Verificados os usos dos métodos e parâmetros antes da remoção; o backend não possui `resources/views` ou `app/Livewire`. Não foi repetida carga de desempenho. Artigo, template e arquitetura não foram alterados.

## Remoção do normalizador

Por decisão do autor, removidos `Input::normalize()` e seus usos nos requests. Textos passam a ser validados e persistidos como recebidos, sem NFC nem remoção de espaços externos. Mantida a rejeição de nomes e apelidos compostos apenas por espaços por regra declarativa do FormRequest. Os testes foram ajustados para verificar preservação literal de Unicode e espaços, além de contabilizar o tamanho da entrada sem normalização.

Removida a dependência direta `symfony/polyfill-intl-normalizer`; ela permanece no lock porque `symfony/string` e `symfony/polyfill-intl-idn` a exigem. A classe `Input` permanece utilizada para JSON, UUIDs, maioridade, cursores e instantes. `ApiRequest` permanece removida, com os oito requests estendendo `FormRequest` diretamente.

Build e recriação das APIs e worker passaram, preservando volumes. Os quatro testes de integração de validação, FormRequests, Unicode/unicidade concorrente e roteamento passaram em 10,005 s. Os três testes PHP, sintaxe de `app/`, sintaxe Python, `composer validate --strict` e `git diff --check` passaram. Não foram repetidas a suíte completa nem a carga de desempenho. Não existem `resources/views` ou `app/Livewire` neste checkout. A divergência em relação à normalização ainda exigida pelo contrato comum está registrada no README; esta alteração ficou restrita ao backend Hyperf.
