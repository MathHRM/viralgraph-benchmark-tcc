# Cenário de viralização: dataset Zipf, snapshot e carga k6

Roteiro de execução do teste que compara as duas composições sob compartilhamentos em cascata e picos de fan-out de celebridades. Este documento descreve o procedimento; **não contém resultados**. Os números de smoke citados abaixo servem apenas para mostrar que o fluxo funciona e não devem ser usados no artigo.

## Endpoints exercitados

| Rota | Laravel + MySQL (síncrono) | Hyperf + RabbitMQ + Neo4j (assíncrono) |
| --- | --- | --- |
| `POST /posts/:id/share` | `201`; registro + fan-out aos seguidores na mesma transação, `INSERT IGNORE` em lotes de 500 | `202` após confirmação do RabbitMQ; consumer em corrotina (prefetch 50) grava o lote com `UNWIND` numa transação |
| `GET /posts/:id/propagation-tree` | CTE recursiva (`WITH RECURSIVE`), índice `shares(post_id, source_user_id)`, `LIMIT 501` | Ancorada em `(:Post {id})` + autor; caminho variável `[:RESHARED_FROM*1..4 {post_id}]`, `ORDER BY`/`LIMIT 501` antes de montar o payload; índice `reshared_post_id` |
| `GET /feed/:usuario_id` | Feed materializado (`feed_items`) | Feed materializado (`(:User)-[:FEED]->(:Post)`) |
| `POST /posts` | Fan-out síncrono | Fila `posts` → outbox → fila `distribution` |

O feed contém posts dos seguidos e posts compartilhados pelos seguidos (segundo grau), com uma entrada por `(usuário, post)`; `origem` vale `post` ou `share` nas duas arquiteturas. A árvore retorna até 500 arestas (`truncado` indica corte). O compartilhamento valida usuário, post e origem (autor ou compartilhador), mas não exige o post no feed: no assíncrono o feed é eventualmente consistente e a exigência geraria falsos negativos sob carga. Regras completas em [contrato-api.md](contrato-api.md).

## Dataset (`code/dataset/generate.py`)

Determinístico por seed (padrão 42). Parâmetros padrão:

- 10.000 usuários comuns com número de seguidores em Zipf truncada `P(k) ∝ k^-1` para `k ∈ [1, 30]`;
- 20 celebridades com `2000 + 3000/rank` seguidores (5000 no rank 1 até 2150 no rank 20);
- seguidores sempre sorteados entre usuários comuns;
- 5 posts por celebridade, 0–3 por usuário comum; 150 posts virais (todos os das celebridades e 50 de comuns);
- cascatas por BFS a partir do autor, até 6 níveis (a árvore da API corta em 4), com probabilidade de compartilhar 3% (seguidor de celebridade), 25% (seguidor de comum) e 12% nos níveis seguintes;
- feed materializado em ordem cronológica, uma entrada por `(destinatário, post)`.

Contagens da seed 42 (de `manifest.json`): 10.020 usuários, 126.323 relações de seguir, 15.003 posts, 34.297 compartilhamentos, 532.820 entradas de feed. A distribuição observada fica em `manifest.json`.

Saídas em `code/dataset/out/` (ignorado pelo Git):

| Arquivo | Uso |
| --- | --- |
| `mysql/seed.sql.gz` | `INSERT` multi-linha, aplicado após `php artisan migrate` |
| `neo4j/*.csv.gz` + `neo4j/schema.cypher` | `neo4j-admin database import full` (offline) e, depois, constraints/índices |
| `k6/dataset.json` | IDs para a carga: comuns, celebridades, posts virais e candidatos válidos a compartilhar (usuário que tem o post no feed + quem o entregou) |

O Neo4j usa importação offline porque aplicar o mesmo volume por `UNWIND` via `cypher-shell`, com o Neo4j limitado a 0,5 CPU, levou vários minutos para poucos milhares de relações (medição informal durante o desenvolvimento).

## Snapshot de volumes

```bash
scripts/viral.sh dataset          # ~20 s
scripts/viral.sh snapshot sync    # migrations + seed.sql; salva o volume MySQL em tar
scripts/viral.sh snapshot async   # neo4j-admin import + schema; salva o volume Neo4j em tar
scripts/viral.sh restore sync     # recria o volume a partir do tar e sobe o ambiente
scripts/viral.sh restore async
```

A geração do snapshot é feita uma vez. Na máquina de desenvolvimento ⚠️ (inferred: tempos de uma única execução, variam com disco e CPU): MySQL ~10 min (1 CPU), Neo4j ~1,5 min (import em ~10 s). A restauração do volume levou 2–11 s; o ambiente fica pronto em ~35 s (sync) e ~80 s (async), tempo dominado pela inicialização do Neo4j e pelos intervalos de healthcheck. O alvo de ~3 s vale para a cópia do volume, não para a subida completa.

`restore` e `down` removem os volumes **somente** dos projetos `tcc-viral-sync` e `tcc-viral-async`. Cada `restore` volta exatamente ao estado do snapshot; execute-o antes de cada repetição.

## Carga k6 (`code/k6/viral.js`)

```bash
scripts/viral.sh obs up                       # Prometheus :9090, Grafana :3000, cAdvisor :8088
MODE=smoke scripts/viral.sh load sync         # 30 s, 50 VUs (SCENARIO padrão: mixed)
make viral-tcc VIRAL_ARCH=async SCENARIO=fanout   # restore do snapshot + warmup + MODE=tcc (3 min)
make viral-tcc-all VIRAL_ARCH=sync                # os 4 cenários, restore limpo antes de cada um
```

`SCENARIO` seleciona a mistura por iteração:

| Cenário | Mistura |
| --- | --- |
| `baseline` | 80% `GET /feed/:id` de comum; 20% escrita de comuns (metade `POST /posts`, metade `POST /posts/:id/share` com candidato válido) |
| `fanout` | 80% ações de celebridades (metade `POST /posts`, metade `POST /posts/:id/share` de post viral alheio, origem = autor); 20% `GET /feed/:id` de comum |
| `graph_read` | 90% `GET /posts/:id/propagation-tree` de post viral (Zipf `s=1,1` sobre o ranking de cascatas); 10% `GET /feed/:id` |
| `mixed` (padrão) | 70% leitura (metade feed, metade árvore); 25% escrita de comuns; 5% celebridade publica |

Celebridades não seguem ninguém no dataset, então não têm posts no feed; no `fanout` compartilham post viral de outro autor com origem = autor (a API não exige o post no feed, só origem válida), gerando fan-out para seus 2000+ seguidores. Cada par (celebridade, post) só vale uma vez (20 × ~150 pares); repetições retornam 422 e são contadas em `share_duplicates`, o que tende a crescer ao longo do `fanout`.

`MODE=tcc` dura 3 min: `VUS_MAX/2` (30 s) → `VUS_MAX` (30 s) → patamar (1m30) → 0 (30 s). Variáveis: `VUS_MAX`, `THINK_MIN_MS`/`THINK_MAX_MS` (padrão 50–250 ms), `ZIPF_S`. Resultados ficam em `code/k6/results/viral-<arch>-<mode>-<cenário>-<timestamp>/`; as séries do Prometheus recebem a tag `experiment=<cenário>`.

Métricas expostas:

- resumo do k6 (`code/k6/results/viral-*/summary.json` e `output.log`): p50/p95/p99 e taxa de erro por rota (`name`), `http_5xx`, `http_timeouts`, `share_duplicates`;
- `rabbitmq_queue_depth{queue}` (async): amostrado pelo k6 a cada 1 s no endpoint Prometheus do RabbitMQ;
- Prometheus/Grafana (dashboard "TCC - Viralização"): séries `k6_*` via remote write, `rabbitmq_queue_messages`, CPU/memória por container (cAdvisor);
- `/metricas` ao final, gravado em `metricas.json`.

O smoke reprova somente se houver 5xx. Latência alta e timeouts sob saturação são resultado do experimento, não falha do teste. Na composição assíncrona, uma consulta Neo4j acima de `NEO4J_TIMEOUT_MS` (10 s no benchmark) vira `503`, então a saturação aparece como 5xx; na síncrona aparece como latência ou timeout do k6 (30 s). Essa diferença precisa ser considerada ao comparar taxas de erro.

## Limitações conhecidas

- Execute uma arquitetura por vez: as duas compartilham a CPU do host.
- O 202 do Hyperf não garante persistência; a fila e `/metricas` ao final indicam o que foi efetivamente gravado.
- A validação do compartilhamento assíncrono usa o estado já persistido: duplicatas ainda na fila podem receber 202, e o consumer descarta a repetição.
- O cAdvisor precisa da v0.55+ com o image store do containerd no Docker; a v0.52 não identificava os containers.
