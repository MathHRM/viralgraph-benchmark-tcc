# Contrato da API de rede social

## Estado da especificação

Contrato aprovado pelo autor, inspirado no [regulamento da Rinha de Backend 2023 Q3](https://github.com/zanfranceschi/rinha-de-backend-2023-q3/blob/main/INSTRUCOES.md). A composição assíncrona está em [backends/hyperf-async](../../backends/hyperf-async/README.md); este contrato não representa resultados experimentais. O protocolo de carga está em [teste-estresse.md](teste-estresse.md).

A versão em [OpenAPI 3.1.1](openapi.yaml) descreve o contrato externo comum às duas composições, incluindo schemas, validações, respostas, paginação e regras de negócio. As suposições de nomenclatura estão marcadas no arquivo; as regras de domínio descritas em texto também precisam ser verificadas pela implementação e pela auditoria.

**Decisões aprovadas:** endpoints abaixo; limites de nome, apelido e conteúdo; idade mínima; seguidores direcionados; um feed por usuário; fan-out na escrita de posts; unicidade de compartilhamentos por usuário/post; respostas 201 e 422; uma entrada por usuário/post no feed; compartilhamento válido exige presença no feed verificada no corte final; sem estados intermediários nos recursos; autor não recebe nem compartilha o próprio post; consulta após criação de post; apelidos distinguem maiúsculas e acentos; compartilhamento distribui aos seguidores; duas instâncias com Nginx e recursos limitados.

Os complementos de identificação, validação, respostas, consultas, contagem e auditoria foram aprovados pelo autor. Este documento especifica comportamento; não modifica o artigo acadêmico.

## Ambiente e objetivo

As duas composições arquiteturais devem expor o mesmo contrato externo: Laravel/PHP-FPM/MySQL, com fluxo síncrono e persistência linha a linha; Hyperf/Swoole/RabbitMQ/Neo4j, com processamento assíncrono e ingestão em lotes por workers. Compara-se a composição completa, não o efeito isolado de uma tecnologia.

Cada composição terá duas instâncias HTTP atrás de Nginx em Docker Compose. A carga entra apenas pelo balanceador. O teto total por composição é de 1,5 CPU e 3 GB de memória, como na Rinha. A distribuição por componente é configurável; ver o protocolo de estresse.

## Convenções comuns

- Entrada e saída em JSON, com `Content-Type: application/json`, exceto o corpo livre e opcional da resposta de criação de post.
- IDs são UUIDs representados como strings. IDs produzidos pelo sistema não dependem do banco utilizado.
- Datas de nascimento em `AAAA-MM-DD`; instantes em UTC, formato RFC 3339.
- Data de referência para idade registrada no manifesto da execução, igual nas duas arquiteturas. Idade calculada por calendário, não por divisão de dias por 365; para nascidos em 29/02, considerar 01/03 nos anos não bissextos.
- Campos obrigatórios rejeitam ausência, `null`, tipos incorretos e strings vazias ou compostas apenas por espaços. Campos desconhecidos são ignorados e não integram os dados validados. Não converter números em strings.
- Limites de strings medidos em pontos de código Unicode. Sem corte silencioso. Conteúdo de posts preservado; remover espaços externos de nome/apelido antes de validar e persistir; normalizar texto em Unicode NFC, preservando maiúsculas e acentos.
- Sem edição, exclusão, deixar de seguir ou desfazer compartilhamento nesta versão.
- Identificação do ator por `usuario_id`/`seguidor_id` no corpo. Não haverá autenticação no benchmark.
- A unicidade precisa resistir a requisições concorrentes nas duas instâncias e a reentregas de mensagens.

## Endpoints

| Método e rota | Finalidade | Corpo obrigatório |
| --- | --- | --- |
| `POST /usuarios` | Criar usuário e seu único feed | `nome`, `apelido`, `nascimento` |
| `GET /usuarios/:id` | Consultar usuário | — |
| `POST /seguir-usuario` | Seguir outro usuário | `seguidor_id`, `seguido_id` |
| `GET /usuarios?s=:termo` | Buscar usuários | — |
| `POST /posts` | Criar post de um usuário | `usuario_id`, `conteudo` |
| `POST /compartilhar-post` | Compartilhar post original | `usuario_id`, `post_id` |
| `GET /posts?c=:termo` | Buscar posts por conteúdo | — |
| `GET /posts/:id` | Consultar post original | — |
| `GET /feed/:usuario_id` | Consultar feed do usuário | — |
| `GET /metricas` | Auditar persistência após a carga | — |

A rota de criação foi uniformizada como `/posts`, conforme a lista de endpoints; `/post` não é um alias. Os corpos da tabela fazem parte do contrato.

## Criação de usuário

`POST /usuarios`

| Campo | Validação definida pelo autor |
| --- | --- |
| `nome` | Obrigatório, string, máximo de 32 caracteres |
| `apelido` | Obrigatório, string, máximo de 100 caracteres, único |
| `nascimento` | Obrigatório, data válida, não futura, idade mínima de 18 anos |

Os limites foram preservados exatamente como solicitados, embora sejam diferentes dos da Rinha. Apelidos distinguem maiúsculas e acentos: `Ana`, `ana` e `aná` são distintos. Aplicar Unicode NFC e remover espaços externos, mantendo essa distinção.

```json
{"nome":"Ana Souza","apelido":"ana","nascimento":"2000-01-15"}
```

Resposta: `201`, `Location: /usuarios/<id>` e corpo:

```json
{"id":"ec82e490-492a-4aeb-b7bc-77640f5c2f90","nome":"Ana Souza","apelido":"ana","nascimento":"2000-01-15"}
```

O usuário tem exatamente um feed, criado junto com ele. Regra: quando o usuário se tornar consultável, seu feed já deve existir logicamente. O feed pode usar o próprio ID do usuário como identidade externa; não exige uma tabela ou nó adicional só para contabilizar inserts.

## Seguir usuário

`POST /seguir-usuario`

```json
{"seguidor_id":"ec82e490-492a-4aeb-b7bc-77640f5c2f90","seguido_id":"3f940884-7050-4ab5-891f-3398b29a8528"}
```

Um usuário tem vários seguidores e pode seguir vários usuários. A relação é direcionada: Ana seguir Bruno não significa Bruno seguir Ana.

Regra: ambos devem existir; proibir seguir a si próprio; uma relação por par ordenado; repetição retorna 422 e não aumenta contagens. Seguir alguém não copia posts antigos. Durante a carga principal, o grafo permanece fixo para evitar ambiguidade sobre seguidores adicionados no meio do fan-out. Um cenário separado pode medir criação de relações.

## Criação de post

`POST /posts`

```json
{"usuario_id":"ec82e490-492a-4aeb-b7bc-77640f5c2f90","conteudo":"Meu primeiro post."}
```

`conteudo`: obrigatório, string, máximo de 500 caracteres. O vínculo com o autor exige `usuario_id`; usuário inexistente retorna 404.

Resposta: `201` e cabeçalho obrigatório `Location: /posts/<id>`, com o caminho e o UUID do recurso criado. O corpo da resposta é livre e pode estar ausente; o gerador obtém o ID pelo `Location`.

Fan-out na escrita significa materializar uma associação entre o post e o feed de cada seguidor do autor. Essa distribuição é parte do trabalho medido, inclusive quando feita por workers. Não basta persistir apenas o post e declarar toda a operação concluída.

**Definido pelo autor:** o autor não recebe o próprio post no feed, inclusive quando um compartilhamento retorna a ele por outro caminho. Depois de cada criação com 201, o gerador consulta o post pelo `Location`, através do Nginx, como no fluxo de criação/consulta da Rinha. Essa consulta não depende de o post estar no feed do autor.

## Compartilhamento e cascata

`POST /compartilhar-post`

```json
{"usuario_id":"3f940884-7050-4ab5-891f-3398b29a8528","post_id":"184f6fa5-cb20-4c8c-925b-2d6de13b58bf"}
```

Um post pode ser compartilhado por vários usuários, mas cada par `(usuario_id, post_id)` só pode ser registrado uma vez. Compartilhar não cria outro post original nem duplica seu conteúdo.

**Definido pelo autor:** distribuir o original aos seguidores de quem compartilhou, permitindo a cascata: Ana publica → Bruno recebe → Bruno compartilha → Carla recebe.

**Definido pelo autor:** Bruno espera o post aparecer no feed e envia `POST /compartilhar-post`. O autor não pode compartilhar o próprio post.

A validade é verificada no corte final da execução: o compartilhamento precisa estar persistido e ter o post no feed do usuário.

Compartilhamentos sem presença válida no feed no corte não entram na contagem válida. Entregas e desdobramentos cuja única origem seja esse compartilhamento inválido também não entram; uma entrega com outro caminho válido continua contando uma única vez. Essa verificação não exige criar estados intermediários nos recursos.

**Definido pelo autor:** no máximo uma entrada por `(destinatario_id, post_id)` no feed, mesmo quando o destinatário recebe por múltiplos caminhos. Novo caminho não cria uma segunda entrada. Procedência e posição são fixadas pela primeira entrega gravada no banco. Uma sequência persistida ordena as entregas; caminhos posteriores preservam origem e posição e ficam registrados apenas como evidência para auditoria.

## Consultas, buscas e feed

- Consultas por ID retornam 200 com o recurso completo ou 404 se não existir. UUID malformado retorna 422.
- Busca de usuários procura substring em `nome` ou `apelido`; busca de posts procura substring em `conteudo`. As buscas distinguem maiúsculas e acentos, usando texto normalizado em NFC nas duas arquiteturas.
- Termo ausente, vazio ou só com espaços retorna 422; nenhuma correspondência retorna 200 com lista vazia. Limite de 100 caracteres para termo de usuários e 500 para posts.
- Buscas e feed usam `{data: [...], proximo_cursor: null|string}`, com até 50 itens por página. Cursor inválido retorna 422. O cursor mantém a posição pela chave de ordenação; novas entradas anteriores ao cursor aparecem ao reiniciar a consulta.
- Ordenação: buscas por ID crescente; feed por sequência persistida da primeira entrega decrescente, com ID como desempate. Não ordenar aleatoriamente ou depender de ordem implícita do banco.
- Cada item do feed contém `post`, `origem` (`publicacao` ou `compartilhamento`), `autor_evento_id` e `evento_id`. O cursor codifica a sequência e o ID do último item retornado, sem depender de offsets.
- Feed de usuário existente sem entradas retorna 200 com lista vazia; usuário inexistente retorna 404.
- Consultar feed não deve criar associações de distribuição: o experimento solicitado é de fan-out na escrita.

## Respostas e consistência

Definido: `201` para conteúdo criado e `422` para conteúdo inválido.

Códigos complementares: `200` para leitura, `400` para JSON malformado, `404` para referência inexistente e `415` para tipo de mídia não aceito. `5xx`, timeouts e rejeições por saturação são falhas operacionais, não conteúdo inválido.

Formato de erro:

```json
{"erro":{"codigo":"validacao","mensagem":"Dados inválidos.","campos":{"conteudo":["Informe até 500 caracteres."]}}}
```

Para relações e compartilhamentos, resposta 201: representação da associação criada com ID, referências e instante. Somente usuários/posts possuem consulta individual definida nesta versão; não anunciar `Location` para rotas inexistentes.

**Critério do teste de estresse:** a pausa de 1–30 ms e a consulta após a criação pertencem ao protocolo de validação do teste, não ao contrato HTTP da API. O contrato define a resposta 201 com `Location` e a consulta individual do post. Para considerar o post válido no teste, o gerador exige uma única leitura com 200 e ID, autor e conteúdo corretos, conforme [teste-estresse.md](teste-estresse.md). Esse critério avalia a visibilidade após a criação, inclusive entre instâncias; 1–30 ms é a pausa antes do GET, não um limite de duração da resposta.

A implementação deve garantir que aceitações concorrentes não prometam criações incompatíveis com a unicidade. Não responder 201 e posteriormente descartar uma criação por apelido duplicado ou dados inválidos. O gerador registra os IDs retornados (pelo `Location` na criação de posts), atores e ações para correlacionar aceitações e persistência na auditoria. A implementação deve garantir leitura entre instâncias e unicidade, sem impor um componente adicional neste contrato. O prazo de processamento é um parâmetro de execução, igual nas duas arquiteturas, e não uma decisão pendente do contrato.

## Endpoint especial de auditoria

`GET /metricas`

Os indicadores pedidos são número de inserts e posts. Interpretar inserts como criações lógicas persistidas, e publicar seus componentes para evitar uma comparação dependente da quantidade de tabelas, nós ou comandos SQL/Cypher.

```json
{
  "usuarios": 0,
  "feeds": 0,
  "relacoes_seguir": 0,
  "posts_criados": 0,
  "compartilhamentos": 0,
  "entradas_feed": 0,
  "inserts_logicos": 0
}
```

Todos os números acima são exemplos de formato, não resultados. Definição:

`inserts_logicos = usuarios + feeds + relacoes_seguir + posts_criados + compartilhamentos + entradas_feed`

`feeds` conta a existência lógica de um feed por usuário, mesmo sem estrutura física própria. Não contam atualizações, tentativas inválidas, duplicatas, retries, mensagens no RabbitMQ ou logs técnicos. Uma operação em lote conta pelos objetos lógicos persistidos, não como uma única escrita; um objeto repartido entre estruturas físicas não conta várias vezes.

### Regra do corte final — definida pelo autor

Não haverá campos ou estados intermediários de negócio como `pending`, `processing` ou `failed` em posts ou compartilhamentos. Trabalho ainda não executado permanece na fila; não exige um recurso provisório persistido para representar sua espera. Isso não altera os mecanismos técnicos de entrega da fila.

No corte final configurado, conta apenas o que foi persistido em armazenamento durável dentro do prazo e atende às regras do contrato. O que ficou na fila, foi salvo depois do corte ou depende exclusivamente de compartilhamento inválido não conta. Um 201 isolado não prova persistência nem aumenta a pontuação. Não estender o prazo até a fila esvaziar.

Os componentes de `/metricas` e o agregado representam somente criações válidas no corte. Registros físicos inválidos não inflam `compartilhamentos`, `entradas_feed` nem `inserts_logicos`. Entradas válidas já materializadas contam individualmente, mesmo que a distribuição não tenha alcançado todos os destinatários; não declarar o fan-out inteiro concluído nesse caso.

O endpoint não participa da carga de negócio e não substitui métricas de máquina, filas e rastreamento. Backlog é observabilidade da infraestrutura, não status de post. O procedimento de auditoria deve preservar o estado do corte, sem incorporar gravações tardias; ver o protocolo de estresse.

Relatar deltas contra a base preparada. Número de registros físicos, respostas HTTP e contagens válidas são grandezas distintas; o total agregado não substitui os indicadores por categoria nem a observabilidade do TCC.

## Implementação e configuração

Não há aprovações pendentes deste contrato. Parâmetros de carga e prazo são configuráveis e registrados por execução. A implementação assíncrona e suas verificações locais estão documentadas em [backends/hyperf-async](../../backends/hyperf-async/README.md); a comparação experimental e a auditoria do corte final permanecem pendentes.

## Aceitação assíncrona de posts

Na composição Hyperf, apenas `POST /posts` responde `201`, `{ "id": "<uuid>" }` e `Location` após confirmação da publicação persistente pelo RabbitMQ, antes da persistência do recurso no Neo4j. Durante esse intervalo, `GET /posts/:id` retorna `404`. Trata-se da decisão explícita deste benchmark, sem substituir 201 por 202. O autor é validado antes da publicação; falha operacional retorna 5xx. Usuários, relações e compartilhamentos são validados e persistidos antes do 201.

O critério do k6 permanece inalterado: uma única leitura após 1–30 ms deve retornar 200. A aceitação assíncrona pode falhar nesse critério. Compartilhamentos exigem entrada já materializada no feed; a auditoria do corte final continua obrigatória e pendente de integração.
