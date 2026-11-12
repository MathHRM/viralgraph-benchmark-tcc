# Modelo de grafo do backend assíncrono

Este documento explica a remodelagem do Neo4j no `hyperf-async`: o que mudou, por que mudou e como conferir cada efeito por conta própria. Não traz resultados experimentais. Nenhuma medição de desempenho do k6 foi feita com esta versão.

## Resumo

A versão anterior usava o Neo4j como se fosse um banco relacional: cada conceito virava um **nó** (`Follow`, `Entry`, `Origin`, `Share`, `Counter`), e os nós eram ligados por **propriedades de texto** (`e.post_id = p.id`), como chaves estrangeiras. Assim o Neo4j perdia sua principal vantagem, que é andar pelas relações sem procurar nada em índice. Além disso, dois nós `Counter` globais eram atualizados em quase toda escrita, o que **serializava** o sistema inteiro.

Na versão nova, os vínculos sociais são **relações** do grafo:

```
ANTES                                            DEPOIS
(User)-[:FOLLOWS]->(Follow)-[:TARGET]->(User)    (User)-[:FOLLOWS]->(User)
(User)-[:SHARED]->(Share)-[:ORIGINAL]->(Post)    (User)-[:SHARED]->(Post)
(Entry {usuario_id, post_id})  (sem ligação)     (User)-[:FEED {seq}]->(Post)
(Origin {usuario_id, post_id}) (sem ligação)     (User)-[:VIA {evento_id}]->(Post)
(Counter {id:"feed"}), (Counter {id:"social"})   (removidos)
(User)-[:AUTHORED]->(Post)                       (igual)
(Task)                                           (igual: outbox)
```

O contrato HTTP (rotas, corpos, status, ordenação e métricas) **não mudou**.

## Conceitos em 5 minutos

- **Nó e relação.** Nó é uma "coisa" (usuário, post). Relação é uma ligação com direção e tipo (`SEGUE`, `RECEBEU`). No Neo4j a relação é armazenada fisicamente junto aos dois nós, com propriedades próprias.
- **Index-free adjacency.** Depois de achar um nó, o Neo4j chega aos vizinhos seguindo ponteiros, sem consultar índice. O custo é proporcional ao **número de vizinhos daquele nó**, não ao tamanho do banco. É isso que um banco de grafos faz melhor que um relacional.
- **Índice.** É uma estrutura ordenada que leva direto aos registros de um valor (`id = X`). Sem índice, o banco faz **scan**: lê todos os nós do label e filtra.
- **Constraint de unicidade.** Índice que também recusa valores duplicados, mesmo com transações concorrentes. É o que impede dois `201` para a mesma relação vindos das duas APIs.
- **Lock e hotspot.** Quem escreve num nó trava esse nó até o commit. Se **toda** transação escreve no **mesmo** nó, elas fazem fila (hotspot). Com um worker em lotes, isso anula o paralelismo.
- **Count store.** O Neo4j guarda contadores de nós por label e de relações por tipo. `MATCH ()-[:FEED]->() RETURN count(*)` responde sem ler dados.
- **PROFILE.** Prefixo que executa a consulta e mostra o plano com `db hits` (acessos ao armazenamento). É a ferramenta para provar se uma consulta usa índice, scan ou travessia.

## O que mudou e por quê

| Problema anterior | Mudança | Conceito |
| --- | --- | --- |
| `GET /feed` buscava `Entry {usuario_id}` **sem índice** e ligava ao post por `post_id`: scan de todas as entradas do banco a cada leitura | Feed virou a relação `(User)-[:FEED]->(Post)`, e a leitura parte do usuário | Index-free adjacency: o custo depende do feed do usuário |
| Fan-out precisava de 2 saltos (`User→Follow→User`) e criava 1 nó + 2 relações por seguir | `FOLLOWS` direto entre usuários | Menos registros, travessia de 1 salto |
| `Counter {id:"feed"}` era incrementado em toda fatia de distribuição, e `Counter {id:"social"}` em todo seguir, lote de posts e compartilhamento | `seq` passou a ser contador **por destinatário** (`User.feed_seq`). O corte de seguidores usa o relógio do Neo4j (`timestamp()`) | Elimina o hotspot: cada escrita trava só o nó do destinatário |
| Fan-out de um hub lia **todos** os seguidores a cada fatia para ordenar | Índice de relação `follows_target_follower (seguido_id, seguidor_id)` + `ORDER BY` nas duas chaves | O índice já entrega ordenado e o `LIMIT` corta cedo: cada fatia lê cerca de `BATCH_SIZE` relações |
| Unicidade por nós `Follow.key`, `Share.key`, `Entry.key`, `Origin.key` | Constraints de unicidade **em relações**: `FOLLOWS.key`, `SHARED.key`, `FEED.key`, `VIA.key` | Mesma garantia contra concorrência; existe na Community 5.26 para uma propriedade só (testado). Constraint composta em relação é recurso Enterprise |
| `GET /usuarios/:id` devolvia `properties(n)` | Projeção explícita `n{.id, .nome, .apelido, .nascimento}` | Evita vazar o campo técnico `feed_seq` |
| Métricas contavam nós `Follow`, `Share` e `Entry` | Contam relações `FOLLOWS`, `SHARED` e `FEED` | Count store, sem scan |

Também entraram, vindas do WIP da branch `perf/hyperf-neo4j`: keep-alive do cURL com pool de handles, índice `task_pending` da outbox, outbox marcada em lote, fatia de tempo da distribuição com `nack(requeue)` e threads HTTP do Neo4j. A mensagem de erro do cliente agora inclui o status HTTP e o erro do cURL.

### Consulta do fan-out, linha a linha

```cypher
MATCH (t:Task {id:$id}) WHERE t.done=false          // tarefa da outbox
MATCH (p:Post {id:t.post_id})
CALL (t,p) {                                         // próximos $size seguidores após o cursor
  MATCH (r:User)-[f:FOLLOWS]->() USING INDEX f:FOLLOWS(seguido_id, seguidor_id)
  WHERE f.seguido_id = t.actor AND f.seguidor_id > t.cursor
    AND f.seguidor_id <> p.usuario_id               // autor não recebe o próprio post
    AND f.at <= t.follow_cutoff                     // seguidor posterior ao evento fica fora
  RETURN r ORDER BY f.seguido_id, f.seguidor_id LIMIT $size
}
SET r.feed_seq = coalesce(r.feed_seq, 0) + 1         // trava SÓ o destinatário e reserva a seq
MERGE (r)-[e:FEED {key: r.id + ":" + p.id}]->(p)     // 1 entrada por (destinatário, post)
ON CREATE SET e.seq=r.feed_seq, e.origem=t.kind, ...  // primeira entrega fixa seq e origem
MERGE (r)-[v:VIA {key: r.id + ":" + p.id + ":" + t.id}]->(p)  // cada caminho, para auditoria
WITH t, collect(r.id) AS recipients
SET t.cursor=last(recipients), t.done=size(recipients)<$size  // cursor no mesmo commit
```

## Como conferir por conta própria

Suba o stack e abra o shell do Neo4j:

```bash
docker compose exec neo4j cypher-shell -u neo4j -p benchmark-local
```

1. **Schema criado:** `SHOW CONSTRAINTS; SHOW INDEXES;`
2. **Feed por travessia:** `PROFILE MATCH (u:User {id:'<id>'})-[e:FEED]->(p) RETURN p.id ORDER BY e.seq DESC LIMIT 51;`. Plano observado: `NodeUniqueIndexSeek` → `Expand(All)` → `Top`, sem `NodeByLabelScan`. O `Top` ordena só as entradas daquele usuário.
3. **Fan-out com índice:** rode a consulta acima com `PROFILE`. Deve aparecer `DirectedRelationshipIndexSeek` seguido de `Limit`, **sem** `Top`/`Sort`, e as linhas do seek devem ficar perto de `$size`. Teste trocando o `ORDER BY` para só `f.seguidor_id`: aparece `Top` e o seek passa a ler todos os seguidores. Esse é o efeito do índice composto.
4. **Comparar com a versão antiga:** num banco populado pela versão do `main`, rode `PROFILE` no feed antigo (`MATCH (e:Entry {usuario_id:$uid}) ...`). O esperado é `NodeByLabelScan` em `Entry`, porque não havia índice em `usuario_id` ⚠️ (inferred, não executado nesta validação).

## Limitações e escolhas conscientes

- **Volumes novos obrigatórios.** O schema mudou, então dados gravados pela versão anterior não são migrados. Use volumes vazios (`code/k6/run.py --clean` ou `docker compose down -v`, que é **destrutivo**).
- **Corte por relógio em vez de contador.** O contador global ordenava seguir e publicar de forma estrita. Com `timestamp()` há uma janela de milissegundos: um seguir que ainda não fez commit, com `at` anterior ao corte, pode ficar fora da tarefa ⚠️ (inferred, não reproduzido). O protocolo mantém o grafo fixo durante a carga principal, então o efeito esperado é nulo ali. Pesa só no cenário separado de criação de relações.
- **`seq` com lacunas.** A sequência de cada usuário avança mesmo quando a entrada já existia. Lacunas eram permitidas antes e continuam permitidas.
- **`VIA` duplica a primeira entrega.** Grava um registro por caminho, inclusive o primeiro, para manter a auditoria igual à anterior. Gravar `VIA` só nos caminhos alternativos economizaria escrita, mas mudaria o que `tests/control.php origins` conta.
- **Um worker só.** A seq por usuário é protegida por lock, e `FEED.key` impede duplicata. Escalar workers continua correto ⚠️ (inferred, não testado com mais de um worker).
- **Partida a frio.** Na primeira subida, `bin/init.php` falhou algumas vezes com "Neo4j indisponível" até o banco aceitar transações. O `restart` resolveu. ⚠️ Inferred: o comportamento é anterior a esta mudança (o healthcheck do Neo4j responde antes do banco aceitar transações), mas isso não foi verificado no `main`.

## Impacto no Laravel (`laravel-sync`)

**Nenhuma mudança obrigatória.** O contrato externo é o mesmo. Pontos para manter as duas composições equivalentes:

1. **Sequência do feed.** O contrato pede "sequência persistida da primeira entrega". O Hyperf agora usa sequência **por usuário**. No Laravel, `AUTO_INCREMENT` global numa tabela `feed_entries` também atende, já que a ordem é avaliada dentro de cada feed. Não é preciso copiar o modelo.
2. **Unicidade (destinatário, post).** O Hyperf garante com constraint. No MySQL, o equivalente é `UNIQUE (usuario_id, post_id)` em `feed_entries`, com `INSERT IGNORE` ou `ON DUPLICATE KEY` para reentregas.
3. **Índices equivalentes, para a comparação ser justa:**
   - feed: índice `(usuario_id, seq)` em `feed_entries`;
   - fan-out: índice `(seguido_id, seguidor_id)` em `follows` (mesma ideia do índice composto do Neo4j);
   - buscas `CONTAINS` / `LIKE '%x%'`: **sem** índice nos dois lados, ou com índice de texto nos dois. Hoje o Hyperf não tem TEXT index.
4. **Seguidores posteriores ao evento** não recebem o post. No Laravel síncrono isso é natural, porque o fan-out roda na própria requisição.
5. **Métricas.** `relacoes_seguir`, `compartilhamentos` e `entradas_feed` contam objetos lógicos. Mudar tabela/nó/relação não muda a definição.

## O que ler (do básico ao avançado)

1. **Neo4j Fundamentals** (GraphAcademy, grátis, cerca de 1h): nó, relação, propriedade, por que grafo. https://graphacademy.neo4j.com/courses/neo4j-fundamentals/
2. **Cypher Fundamentals** (GraphAcademy): `MATCH`, `MERGE`, `CREATE`, `SET`, `WITH`. https://graphacademy.neo4j.com/courses/cypher-fundamentals/
3. **Graph Data Modeling Fundamentals** (GraphAcademy): quando algo deve ser nó e quando deve ser relação. É o centro desta mudança. https://graphacademy.neo4j.com/courses/modeling-fundamentals/
4. **Cypher Indexes and Constraints** (GraphAcademy): índices, constraints e `PROFILE`. https://graphacademy.neo4j.com/courses/cypher-indexes-constraints/
5. **Cypher Cheat Sheet**: consulta rápida, marca o que é só Enterprise. https://neo4j.com/docs/cypher-cheat-sheet/current/
6. **Planning and tuning** (Cypher Manual): ler planos, `db hits`, operadores. https://neo4j.com/docs/cypher-manual/current/planning-and-tuning/
7. **Constraints** (Cypher Manual). https://neo4j.com/docs/cypher-manual/current/constraints/
8. **Search-performance indexes** (Cypher Manual). https://neo4j.com/docs/cypher-manual/current/indexes/search-performance-indexes/overview/
9. **Locks and deadlocks** (Operations Manual): por que o contador global serializava e por que há retry de deadlock. https://neo4j.com/docs/operations-manual/current/database-internals/locks-deadlocks/
10. **Transactional Outbox** (microservices.io): o padrão do `Task` + RabbitMQ. https://microservices.io/patterns/data/transactional-outbox.html
11. **Degree Centrality** (GDS): ponte com o tema do TCC (nós de alta centralidade de grau). Ainda não usado no código. https://neo4j.com/docs/graph-data-science/current/algorithms/degree-centrality/

Os itens 1 a 4 bastam para entender todo este documento.

## Exercícios curtos

1. Crie 3 usuários e faça A seguir B. Rode `MATCH (a)-[f:FOLLOWS]->(b) RETURN a.id, f, b.id` e veja que a relação tem propriedades.
2. Tente criar a mesma relação duas vezes com `CREATE` e o mesmo `key`. Leia o erro da constraint: é ele que vira o `422`.
3. Rode o `PROFILE` do fan-out com e sem `f.seguido_id` no `ORDER BY` e compare os `db hits`.
4. Escreva uma consulta que conte, para um post, quantos usuários o receberam por compartilhamento: `MATCH (:Post {id:$p})<-[e:FEED {origem:'compartilhamento'}]-(u) RETURN count(u)`.
