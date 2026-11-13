# Backend assíncrono

Hyperf 3.2 / Swoole, duas APIs HTTP, Nginx, RabbitMQ e Neo4j. Implementa o [contrato social](../../code/docs/contrato-api.md), sem prefixo `/api`. Esta entrega não altera o artigo nem constitui execução experimental oficial.

## Executar

Requisitos: Docker com Compose v2; Python 3 para verificar orçamento e executar integração. A porta 9999 deve estar livre.

```bash
cd backends/hyperf-async
docker compose build
python3 bin/check-budget.py --config-only
docker compose up -d --wait --wait-timeout 300
python3 bin/check-budget.py
curl http://127.0.0.1:9999/metricas
```

Somente `127.0.0.1:9999` é publicado no host. Nginx usa round-robin, sem afinidade e com `proxy_next_upstream off`, inclusive para POST. `X-Instance` identifica a API que respondeu. APIs não consomem filas. A mesma imagem atende APIs e worker; o entrypoint da API 1 inicializa constraints idempotentes antes de liberar as demais aplicações; `composer.lock` e os digests das imagens fixam dependências. O cliente AMQP oferece confirmações do broker. O cliente Neo4j usa a API HTTP transacional oficial, por meio de cURL, reaproveitando handles ociosos por processo (keep-alive).

As credenciais padrão `benchmark` / `benchmark-local` (Neo4j: usuário `neo4j`) destinam-se ao ambiente local isolado. `NEO4J_PASSWORD` e `RABBITMQ_PASSWORD` podem ser definidas em `.env` antes da primeira subida. Alterar essas variáveis não migra credenciais já persistidas nos volumes. Não usar dados reais: o contrato do benchmark não tem autenticação.

## Organização do código

O namespace `App\` aponta para `app/`, seguindo o diretório do skeleton Hyperf. O bootstrap HTTP fica em `bin/hyperf.php`; `bin/init.php` e `bin/worker.php` também usam o container. As camadas abaixo são uma decisão deste projeto:

- `Controller/`: ações HTTP explícitas, status e cabeçalhos. As rotas estão em `config/routes.php`.
- `Request/`: `FormRequest` do `hyperf/validation`, com regras declarativas e dados acessados por `validated()`. O middleware valida antes dos controllers. Os requests estendem diretamente `FormRequest`; campos extras são ignorados e IDs são preservados como recebidos.
- `Service/`: maioridade, auto-relação, permissão para compartilhar e publicação de posts.
- `Repository/`: consultas específicas de usuários, relações, posts, compartilhamentos, feeds, tarefas e métricas. Cada chamada ao cliente Neo4j continua sendo uma transação.
- `Infrastructure/`: transporte Neo4j, inicialização do schema e conexão RabbitMQ. `BrokerFactory` obtém conexões de `Hyperf\Amqp\ConnectionFactory`, com uma conexão por processo e canais exclusivos por operação. Fechar um adaptador fecha somente seu canal, preservando a conexão compartilhada. O worker tem sua própria conexão e executa em contexto de corrotina.
- `Validation/` e `Pagination/`: maioridade e cursores vinculados à consulta. A validação de UUID dos cursores usa `Str::isUuid()` do Hyperf; formato e calendário de nascimento são validados pelo FormRequest.
- `Exception/Handler/`: resposta de erro única para falhas da aplicação e do framework.
- `Worker/`: `Runner` controla coleta, sinais e reconexão; `PostBatchProcessor` confirma depois do commit; `OutboxPublisher` marca tarefas depois da confirmação do broker; `FeedDistributor` confirma somente ao concluir a distribuição.

Textos são validados e persistidos como recebidos, sem normalização Unicode ou remoção de espaços externos, conforme a decisão de remover o normalizador. Esta implementação difere da exigência de NFC e remoção de espaços ainda descrita no contrato comum; esses comportamentos não devem ser considerados equivalentes em uma comparação entre as arquiteturas. O parsing JSON e a validação comum ficam nos componentes instalados do Hyperf. Permanecem as regras específicas de maioridade e cursor. A geração local de UUID foi mantida porque `Str::uuid()` exige `ramsey/uuid`, ausente no projeto. `hyperf/amqp` gerencia conexões, transporte Swoole, heartbeat e recuperação de conexões desconectadas. O adaptador local mantém publicação com roteamento obrigatório, confirmação do broker e consumo em lotes com ACK após persistência; o produtor padrão da versão instalada não envia com `mandatory`, e o consumidor padrão não implementa os lotes com commit único utilizados aqui. Por isso, `Broker` e o ciclo de consumo continuam específicos da aplicação. `config/autoload/amqp.php` desativa consumidores automáticos e mantém as variáveis `RABBITMQ_*` existentes.

Controllers, serviços e repositórios recebem dependências pelo construtor. O loop de consumo por `basic_get`, as transações, os formatos públicos e a persistência eventual foram preservados. Combinações GET/POST que já retornavam 404 continuam explícitas nas rotas para manter compatibilidade; outros métodos continuam com 405.

## Concorrência: pool Neo4j e paralelismo de leitura

O cliente Neo4j passa a limitar a concorrência de transações em voo por processo com um pool de permissões (`app/Infrastructure/Neo4j/Neo4jPool.php`), um `Hyperf\Engine\Channel` com `NEO4J_POOL_SIZE` tokens (padrão 32). Cada chamada a `Neo4jClient::run()` adquire uma permissão antes da transação e a devolve ao final, inclusive nas novas tentativas de deadlock. Com o pool cheio, a corrotina aguarda no canal — backpressure natural — em vez de abrir handles ilimitados. O padrão 32 espelha `server.threads.worker_count` do Neo4j (`NEO4J_HTTP_THREADS`): não adianta enviar mais transações simultâneas do que o Jetty da API HTTP tem threads para atender; o excesso degradaria o banco. O pool substitui a função de controle de concorrência que `POOL_LIMIT` não exercia (ele limita apenas handles keep-alive ociosos, que continuam reaproveitados por processo). Fora de corrotina (container em boot, `bin/init.php`, testes CLI), o pool é transparente: `acquire()`/`release()` são no-op, pois não há escalonador para suspender, e o canal é criado preguiçosamente apenas no primeiro uso em corrotina. `config/autoload/neo4j.php` centraliza `url`, `timeout_ms` e `pool_size`, todos derivados do ambiente.

Duas leituras usam `Hyperf\Coroutine\Parallel` para disparar consultas independentes em corrotinas simultâneas, reduzindo a latência de "soma" para "máximo" dos round-trips:

- `ShareService` valida a existência do usuário e do post em paralelo. A semântica é idêntica: a precedência do erro é preservada (se ambos faltarem, prevalece o 404 do usuário), e a regra do próprio post e a unicidade seguem inalteradas.
- `FeedRepository` divide a leitura do feed em (a) verificação de existência do usuário e (b) página de entradas, atrás da flag `FEED_PARALLEL` (padrão `false`, mantendo o baseline na consulta única original). A resposta é idêntica ao contrato (`{data, proximo_cursor}`, 404 para usuário inexistente, mesma ordenação e cursor). O trade-off é explícito: o caminho paralelo dobra os round-trips de leitura; o ganho aparece quando há capacidade ociosa no Neo4j. A flag permite ligar/desligar o paralelismo entre rodadas de carga para medir o trade-off empiricamente.

Ambos os caminhos caem na execução sequencial quando não há corrotina ativa, preservando o comportamento em contextos CLI e de setup. `FollowService` já resolve validação e escrita em uma única consulta Cypher e não foi alterado. Não há mudança de contrato: nenhum campo novo é adicionado ao corpo do feed.

Observabilidade com OpenTelemetry fica deliberadamente fora desta entrega, por escopo e orçamento; permanecem `/metricas` e a coleta por `docker stats`. Essa limitação é intencional e deve ser tratada no texto do TCC, não constituindo equivalência com arquiteturas instrumentadas.

## Recursos e configuração

Distribuição inicial **(assumed)**, ainda sem calibração experimental:

| Serviço | CPU | Memória (MiB) |
| --- | ---: | ---: |
| Nginx | 0,10 | 64 |
| API 1 | 0,20 | 320 |
| API 2 | 0,20 | 320 |
| Worker | 0,30 | 384 |
| RabbitMQ | 0,20 | 512 |
| Neo4j | 0,50 | 1400 |
| Total | 1,50 | 3000 |

`cpus`, `mem_limit` e `memswap_limit` são aplicados por serviço; swap adicional é zero. Os 3000 MiB explicitam a soma aprovada no plano, sem pressupor equivalência com 3 GB decimais. A inicialização das constraints usa os limites dos próprios processos da aplicação, sem um container adicional que extrapole o agregado em reinícios. `bin/check-budget.py` verifica configuração, teto agregado e limites efetivos via Docker; recusa orçamento excedido. Ao alterar os limites, executar essa verificação antes da carga.

Variáveis: `NGINX_CPUS`, `NGINX_MEMORY`, `API_CPUS`, `API_MEMORY` (cada API), `WORKER_CPUS`, `WORKER_MEMORY`, `RABBITMQ_CPUS`, `RABBITMQ_MEMORY`, `NEO4J_CPUS`, `NEO4J_MEMORY`. Memória usa unidades aceitas pelo Compose, por exemplo `320m`. Neo4j tem heap inicial 256 MiB, máximo 512 MiB e page cache 256 MiB. A máquina host precisa de recursos adicionais para Docker e gerador de carga.

`REFERENCE_DATE=2026-11-08` deve coincidir com o manifesto k6. `BATCH_SIZE=100` e `FLUSH_MS=5` são parâmetros iniciais **(assumed)**; o worker aceita lote de 1–1000 e janela de 1–1000 ms. O flush é a janela de coleta; não promete persistência ou resposta em 5 ms sob carga. `DISTRIBUTION_SLICE_MS=50` **(assumed)** limita o tempo contínuo de fan-out por tarefa: ao fim da fatia, o cursor já confirmado é mantido e a mensagem volta à fila (`nack` com requeue), para não bloquear a ingestão de posts. A outbox é consultada após novos posts, quando a página anterior veio cheia ou a cada 50 ms. `NEO4J_HTTP_THREADS=32` **(assumed)** define `server.threads.worker_count`; sem ele, `ActiveProcessorCount=1` reduz o Jetty da API HTTP a uma thread. `NEO4J_POOL_SIZE=32` casa com esse valor e limita as transações simultâneas por processo (backpressure); `FEED_PARALLEL=false` mantém a leitura do feed na consulta única e pode ser ligada por rodada de carga para medir o paralelismo.

## Persistência e recuperação

- Usuários, relações e compartilhamentos são confirmados no Neo4j antes do 201. Constraints protegem apelidos, pares direcionados e pares usuário/post contra concorrência.
- Apenas posts retornam `{id}` e `Location` após publisher confirm do RabbitMQ. O autor é validado antes. Filas duráveis e mensagens persistentes permitem recuperar pendências; indisponibilidade retorna 503. Uma falha após o broker confirmar mas antes da resposta ao cliente continua sendo ambígua, pois o contrato não tem chave de idempotência do cliente.
- O worker grava posts com `UNWIND`/`MERGE`, em transação idempotente, e só depois confirma consumo. A tarefa de distribuição é gravada na mesma transação do post ou compartilhamento: a outbox é publicada com confirmação antes de marcar `published`. Falha entre essas etapas pode duplicar a mensagem; não perde a tarefa.
- Modelo nativo de grafo, detalhado em [docs/modelo-grafo.md](docs/modelo-grafo.md): `(User)-[:FOLLOWS]->(User)`, `(User)-[:AUTHORED]->(Post)`, `(User)-[:SHARED]->(Post)`, `(User)-[:FEED]->(Post)` e `(User)-[:VIA]->(Post)`. Constraints de unicidade em relações (`FOLLOWS.key`, `SHARED.key`, `FEED.key`, `VIA.key`) protegem pares contra concorrência. O fan-out busca seguidores em lotes pelo índice de relação `follows_target_follower`, em ordem de cursor; a outbox pendente usa o índice `Task(published, done)`. A tarefa guarda `follow_cutoff = timestamp()` do Neo4j; seguidores com `at` posterior ficam fora, mesmo com distribuição atrasada. Leituras de feed percorrem só as relações `FEED` do usuário.
- Cursor de distribuição e entregas são confirmados juntos. A sequência da primeira entrega é um contador por destinatário (`User.feed_seq`), sem contador global; pode conter lacunas. Origem e sequência da entrada nunca mudam. Relações `VIA` guardam cada caminho de entrega, sem aumentar entradas nem métricas. A mudança de schema exige volumes Neo4j novos.
- Não há autoentrega, compartilhamento pelo autor, retroalimentação de posts antigos ao seguir, nem mais de uma entrada por usuário/post. Compartilhamento exige entrada já materializada no feed; sua outbox continua válida mesmo se o broker estiver temporariamente fora.
- `/metricas` conta usuários, feeds lógicos, relações, posts, compartilhamentos e entradas persistidas. Nós técnicos, relações físicas, confirmações e mensagens não entram nos totais. Leituras de métricas são observacionais: a preservação do corte e auditoria de integridade final permanecem pendentes de integração.

```bash
docker compose stop worker   # posts podem ser aceitos e retornar 404 no GET
docker compose start worker # retoma mensagens e outbox
docker compose logs --tail 100 worker api1 api2
docker compose down         # preserva os volumes; próxima subida retoma o estado
```

Não apagar volumes para recuperar falhas. O hostname fixo `rabbitmq` mantém a identidade do nó e seu diretório persistente ao recriar o container. ACK só ocorre após commit; uma queda antes do ACK reentrega a mensagem. Uma falha operacional mantém pendências e é registrada no stderr, com reconexão. Mensagem interna inválida exige diagnóstico operacional; não há descarte automático nem fila de mensagens mortas nesta entrega.

## Verificação

A integração grava dados, interrompe/reinicia o worker e o RabbitMQ e recria o container do broker preservando o volume do projeto Compose `tcc-hyperf-async`. Usar somente a base local de desenvolvimento; não executar junto de uma carga.

```bash
docker compose exec -T api1 php tests/input.php
docker compose exec -T api1 php tests/services.php
docker compose exec -T api1 php tests/worker.php
docker compose exec -T api1 php tests/parallel.php
docker compose exec -T api1 php tests/amqp.php
python3 -m unittest discover -s tests -p 'test_*.py' -v
python3 bin/check-budget.py
```

Os testes locais de serviços e worker usam substitutos em memória para verificar regras e a ordem das confirmações, inclusive em falhas. `tests/parallel.php` executa em runtime de corrotina (`Swoole\Coroutine\run`) e prova, com clientes-substitutos que contam chamadas simultâneas, o backpressure do pool no limite configurado, o paralelismo das duas validações do `ShareService` (preservando a precedência do 404) e o split do `FeedRepository` sob `FEED_PARALLEL`. `tests/amqp.php` usa o broker real para verificar conexão compartilhada, canais isolados e rejeição de publicação sem rota. A integração real cobre contrato, Unicode, maioridade, concorrência entre APIs, cursores, cascatas, primeira entrega, replay e recuperação entre commit e ACK. `tests/control.php` é ferramenta interna de teste, sem endpoint HTTP. Para smoke do gerador existente, provisionar projeto Compose separado com volumes vazios e executar `code/k6/run.py --architecture async` a partir da raiz, com configuração curta. Não alterar o critério: o único GET após 1–30 ms precisa retornar 200; persistência eventual pode falhar nele. Consultar o registro de validação local em `tests/VALIDATION.md`.

Fontes técnicas consultadas estão registradas em [citations.md](../../citations.md): Hyperf, Docker Compose, RabbitMQ, API transacional e Cypher Manual do Neo4j. Não foram adicionadas referências ao artigo.
