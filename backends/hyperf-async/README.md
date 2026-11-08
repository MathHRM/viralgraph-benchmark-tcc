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

Somente `127.0.0.1:9999` é publicado no host. Nginx usa round-robin, sem afinidade e com `proxy_next_upstream off`, inclusive para POST. `X-Instance` identifica a API que respondeu. APIs não consomem filas. A mesma imagem atende APIs e worker; o entrypoint da API 1 inicializa constraints idempotentes antes de liberar as demais aplicações; `composer.lock` e os digests das imagens fixam dependências. O cliente AMQP oferece confirmações do broker; o polyfill de normalização mantém NFC sem adicionar uma extensão ao runtime. O cliente Neo4j usa a API HTTP transacional oficial, por meio de cURL.

As credenciais padrão `benchmark` / `benchmark-local` (Neo4j: usuário `neo4j`) destinam-se ao ambiente local isolado. `NEO4J_PASSWORD` e `RABBITMQ_PASSWORD` podem ser definidas em `.env` antes da primeira subida. Alterar essas variáveis não migra credenciais já persistidas nos volumes. Não usar dados reais: o contrato do benchmark não tem autenticação.

## Organização do código

O namespace `App\` aponta para `app/`, seguindo o diretório do skeleton Hyperf. O bootstrap HTTP fica em `bin/hyperf.php`; `bin/init.php` e `bin/worker.php` também usam o container. As camadas abaixo são uma decisão deste projeto:

- `Controller/`: ações HTTP explícitas, status e cabeçalhos. As rotas estão em `config/routes.php`.
- `Request/`: `FormRequest` do `hyperf/validation`, com regras declarativas e dados acessados por `validated()`. O middleware valida antes dos controllers. `ApiRequest` usa o JSON já decodificado pelo Hyperf, exige objeto e normaliza NFC antes das regras; campos extras são ignorados e IDs são convertidos para minúsculas.
- `Service/`: maioridade, auto-relação, permissão para compartilhar e publicação de posts.
- `Repository/`: consultas específicas de usuários, relações, posts, compartilhamentos, feeds, tarefas e métricas. Cada chamada ao cliente Neo4j continua sendo uma transação.
- `Infrastructure/`: transporte Neo4j, inicialização do schema e conexão RabbitMQ. `BrokerFactory` abre uma conexão por publicação HTTP; conexões e canais mutáveis não são compartilhados entre corrotinas. O worker mantém sua própria conexão até a reconexão.
- `Validation/` e `Pagination/`: NFC, maioridade e cursores vinculados à consulta. A validação de UUID dos cursores usa `Str::isUuid()` do Hyperf; formato e calendário de nascimento são validados pelo FormRequest.
- `Exception/Handler/`: resposta de erro única para falhas da aplicação e do framework.
- `Worker/`: `Runner` controla coleta, sinais e reconexão; `PostBatchProcessor` confirma depois do commit; `OutboxPublisher` marca tarefas depois da confirmação do broker; `FeedDistributor` confirma somente ao concluir a distribuição.

O parsing JSON e a validação comum ficam nos componentes instalados do Hyperf. Permanecem as regras específicas de objeto JSON, NFC, maioridade e cursor. A geração local de UUID foi mantida porque `Str::uuid()` exige `ramsey/uuid`, ausente no projeto. O transporte AMQP mantém publicação com roteamento obrigatório, confirmação do broker e consumo em lotes com ACK após persistência; a troca pelo produtor padrão de `hyperf/amqp` não preserva diretamente essas garantias.

Controllers, serviços e repositórios recebem dependências pelo construtor. O loop de consumo por `basic_get`, as transações, os formatos públicos e a persistência eventual foram preservados. Combinações GET/POST que já retornavam 404 continuam explícitas nas rotas para manter compatibilidade; outros métodos continuam com 405.

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

`REFERENCE_DATE=2026-11-08` deve coincidir com o manifesto k6. `BATCH_SIZE=100` e `FLUSH_MS=5` são parâmetros iniciais **(assumed)**; o worker aceita lote de 1–1000 e janela de 1–1000 ms. O flush é a janela de coleta; não promete persistência ou resposta em 5 ms sob carga.

## Persistência e recuperação

- Usuários, relações e compartilhamentos são confirmados no Neo4j antes do 201. Constraints protegem apelidos NFC, pares direcionados e pares usuário/post contra concorrência.
- Apenas posts retornam `{id}` e `Location` após publisher confirm do RabbitMQ. O autor é validado antes. Filas duráveis e mensagens persistentes permitem recuperar pendências; indisponibilidade retorna 503. Uma falha após o broker confirmar mas antes da resposta ao cliente continua sendo ambígua, pois o contrato não tem chave de idempotência do cliente.
- O worker grava posts com `UNWIND`/`MERGE`, em transação idempotente, e só depois confirma consumo. A tarefa de distribuição é gravada na mesma transação do post ou compartilhamento: a outbox é publicada com confirmação antes de marcar `published`. Falha entre essas etapas pode duplicar a mensagem; não perde a tarefa.
- Relações sociais são nós `Follow` únicos ligados por `FOLLOWS`/`TARGET`; posts usam `AUTHORED` e compartilhamentos `SHARED`/`ORIGINAL`. O fan-out percorre seguidores no grafo em lotes. Um contador social fixa os seguidores elegíveis na transação que persiste o post ou compartilhamento; relações posteriores ficam fora dessa tarefa, mesmo com distribuição atrasada. Leituras apenas consultam entradas materializadas.
- Cursor de distribuição e entradas são confirmados juntos. Um contador transacional persistido atribui a sequência da primeira entrega; pode conter lacunas. Origem e sequência da entrada nunca mudam. Nós `Origin` guardam caminhos alternativos, sem aumentar entradas nem métricas. O contador serializa essa atribuição; é um custo desta implementação a avaliar experimentalmente.
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
python3 -m unittest discover -s tests -p 'test_*.py' -v
python3 bin/check-budget.py
```

Os testes locais de serviços e worker usam substitutos em memória para verificar regras e a ordem das confirmações, inclusive em falhas. A integração real cobre contrato, Unicode, maioridade, concorrência entre APIs, cursores, cascatas, primeira entrega, replay e recuperação entre commit e ACK. `tests/control.php` é ferramenta interna de teste, sem endpoint HTTP. Para smoke do gerador existente, provisionar projeto Compose separado com volumes vazios e executar `code/k6/run.py --architecture async` a partir da raiz, com configuração curta. Não alterar o critério: o único GET após 1–30 ms precisa retornar 200; persistência eventual pode falhar nele. Consultar o registro de validação local em `tests/VALIDATION.md`.

Fontes técnicas consultadas estão registradas em [citations.md](../../citations.md): Hyperf, Docker Compose, RabbitMQ e API transacional do Neo4j. Não foram adicionadas referências ao artigo.
