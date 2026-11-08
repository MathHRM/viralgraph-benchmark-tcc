# Protocolo do teste de estresse

## Estado e relação com o contrato

Protocolo aprovado pelo autor, com implementação parcial do gerador em [../k6/](../k6/README.md). O [contrato da API](contrato-api.md) define as regras de negócio e contagem. Os complementos deste protocolo também foram aprovados; parâmetros numéricos permanecem configuráveis. Não representa resultados nem alteração do artigo acadêmico.

A Rinha 2023 inspira a infraestrutura limitada, a carga progressiva e a auditoria final no banco. O teste deste projeto precisa também reproduzir crescimento de relações e cascatas entre seguidores, conforme o tema atual do TCC. Os compartilhamentos distribuem o post original aos seguidores.

## Topologia e orçamento de recursos

```text
Gerador de carga → Nginx → API 1 / API 2
                          ├─ síncrona: MySQL
                          └─ assíncrona: RabbitMQ → workers → Neo4j
```

O desenho representa os componentes, não proíbe conexões de leitura das APIs ao banco. Cada composição é executada separadamente, no mesmo ambiente de referência.

- **Definido pelo autor:** teto total de 1,5 CPU e 3 GB de memória por composição, como na Rinha, contando Nginx, APIs, banco, broker, workers e qualquer cache adicionado. Não é um limite por contêiner.
- Mesma capacidade/classe de disco, política de persistência e condições de rede documentadas. Não desligar durabilidade em apenas uma composição.
- Recursos do gerador de carga e coletores separados do orçamento do sistema testado; evitar contenção com o backend e registrar o ambiente físico.
- Distribuição do orçamento por componente pode diferir, mas deve ser registrada e congelada antes das execuções comparativas. Número de workers, tamanho de lote e flush por tempo precisam ser fixados.
- Nginx com configuração comparável nas duas composições; round-robin sem afinidade de sessão. Configurar e registrar timeouts, conexões, filas e limites de corpo.
- Verificar limites efetivos em execução, não apenas a presença de campos no YAML. A especificação de deploy do Compose é opcional: [Docker, documentação oficial](https://docs.docker.com/reference/compose-file/deploy/).
- Política de reinício, swap e comportamento em falta de memória documentados e iguais quando aplicáveis.

Nenhum Compose ou serviço novo é criado por esta documentação.

## Unidade de comparação

Não usar quantidade de comandos SQL/Cypher como medida principal: um lote e uma escrita linha a linha podem representar quantidades diferentes de trabalho lógico. Regra: contar os objetos e relações persistidos definidos em `/metricas`, além do total agregado `inserts_logicos`.

Relatar separadamente:

1. Criações lógicas durante a carga, por categoria e no total, descontando a base inicial.
2. Criações adicionais após as chegadas, respeitando o corte final configurado, e backlog observado na infraestrutura.
3. Posts originais, compartilhamentos e entradas de feed.
4. Entregas válidas de feed e sua cobertura dos destinatários esperados; entregas parciais contam por entrada válida, sem representar distribuição integral.
5. Respostas HTTP, latências p50/p95/p99 por endpoint, timeouts, falhas e chegada efetivamente aplicada.
6. CPU, memória, disco, backlog, reentregas/falhas de fila e atraso de distribuição do feed.

Não somar mensagens aceitas pelo RabbitMQ como se fossem associações já persistidas no Neo4j. Retries, duplicatas e atualizações não aumentam o total de criações. Mais inserts não compensa corrupção, fan-out indevido ou duplicatas.

## Preparação reproduzível

Produzir um manifesto por cenário, antes de medir:

| Parâmetro    | O que precisa ser fixado                                                             |
| ------------ | ------------------------------------------------------------------------------------ |
| Identidade   | Commit, imagens por digest, versões e configuração de cada componente                |
| Ambiente     | CPU, RAM, disco, sistema, Docker/Compose e rede                                      |
| Recursos     | Teto global, distribuição, swap, workers, lotes e timeouts                           |
| Base inicial | Quantidade de usuários, relações, posts e entradas já existentes                     |
| Grafo        | Algoritmo/arquivo de geração, semente, graus de entrada/saída e nós centrais         |
| Estímulo     | Sequência, IDs lógicos, atores, alvos, payloads e instantes de chegada               |
| Carga        | Taxas por cenário, durações, pico, limites do gerador e prazo de recuperação         |
| Validação    | Regras de integridade, deduplicação, erros esperados e política de retries           |
| Tempo        | Data de referência para idade e sincronização dos relógios para latência distribuída |

Preparar bancos limpos antes de cada repetição. Seed e dados de aquecimento não podem inflar os deltas da carga medida; preferir aquecimento de leitura sobre a base pronta. Se houver escrita de aquecimento, estabilizar e registrar a nova base antes da medição.

Usar dados sintéticos. O mesmo manifesto deve gerar IDs válidos em cada implementação mediante mapeamento, ou aceitar IDs determinísticos numa preparação fora da API pública. Não reutilizar UUIDs retornados por uma arquitetura como se existissem na outra.

## Cenários

### A. Correção e concorrência, antes do estresse

Validar o contrato com carga pequena antes de aumentar taxas:

- Criar usuário e verificar a existência de seu feed único; após 201 de post, consultar `Location` com pausa de 1–30 ms e verificar leitura pela outra instância, conforme o contrato.
- Validar strings, tipos, idade, datas, referências inexistentes e buscas; apelidos `Ana`, `ana` e `aná` devem poder coexistir.
- Disparar simultaneamente duplicatas de seguir e compartilhar pelas duas instâncias: apenas uma criação persistida por chave lógica.
- Validar a regra para seguir a si próprio; autor não recebe nem compartilha o próprio post. No corte final, desconsiderar compartilhamento sem entrada válida no feed.
- Verificar que criação de post e compartilhamento alcançam exatamente os destinatários esperados, sem entregas extras.
- Verificar convergência após atraso de workers e ausência de aumento por reentrega; fault injection adicional não faz parte do teste principal sem definição própria.
- Validar conteúdo das respostas, autoria, unicidade e ordenação do feed, não apenas códigos HTTP.

Não misturar porcentagens indefinidas de erros intencionais ao teste principal. Se usados, declarar volume e erros esperados por cenário.

### B. Referência sem concentração

Preparar grafo com graus mais distribuídos e executar criação de posts, leituras, buscas e compartilhamentos. Registrar suas distribuições efetivas, sem chamar o grafo de representativo de redes reais sem fundamentação adicional.

### C. Fan-out de usuário central

Manter o grafo fixo e publicar por um nó com muitos seguidores. Sobrepor leituras e buscas com taxas declaradas. Esse cenário mede a amplificação de escrita pela distribuição de um post.

### D. Cascata de compartilhamentos

Preparar caminhos como Ana → Bruno → Carla em termos de recebimento: Bruno segue Ana; Carla segue Bruno. Ana publica, Bruno recebe e compartilha, Carla recebe e pode compartilhar. Um único post pode provocar múltiplos fan-outs, com uma única entrada por destinatário/post, inclusive quando múltiplos caminhos se encontram. O autor original é excluído das entregas.

**Definido pelo autor:** a cascata depende da entrega real. Bruno espera o post aparecer em seu feed e compartilha. Carla repete esse percurso quando receber o original. Não programar compartilhamentos independentes dessas etapas.

As duas arquiteturas recebem os mesmos usuários, grafo, posts de origem e regras de comportamento. A quantidade e os horários efetivos dos compartilhamentos podem divergir porque dependem das entregas. Registrar essa diferença como parte do resultado; não afirmar que ambas receberam exatamente a mesma sequência de requisições derivadas. Separar chegadas externas programadas das ações geradas pela cascata.

Procedimento: verificar o feed periodicamente, com intervalo e limite de espera configurados igualmente nas duas arquiteturas. Essas consultas entram na carga medida. Depois de encontrar o post, enviar o compartilhamento. Registrar espera, timeout e falhas em cada etapa. A auditoria final verifica as dependências persistidas; não criar estados intermediários nos recursos nem uma etapa de confirmação para Bruno.

### E. Crescimento de relações

Criar novos usuários e relações em uma faixa de carga separada. Na faixa conjunta com viralização, cada fan-out considera os seguidores existentes quando seu evento de publicação ou compartilhamento é persistido. Seguidores posteriores não recebem entregas retroativas desse evento.

## Perfil temporal da carga

**Definido pelo autor:** reutilizar orçamento e perfil temporal da Rinha. O arquivo de referência é a [simulação oficial em `stress-test/`](https://github.com/zanfranceschi/rinha-de-backend-2023-q3/blob/main/stress-test/user-files/simulations/rinhabackend/RinhaBackendSimulation.scala), que possui estes três fluxos simultâneos:

| Fluxo original | Primeiros 25 segundos | Próximos 180 segundos |
| --- | --- | --- |
| Criação e eventual consulta | 2 chegadas/s por 10 s; 5/s por 15 s | Rampa de 6 para 600 chegadas/s |
| Busca válida | 2 chegadas/s | Rampa de 6 para 100 chegadas/s |
| Busca inválida | 2 chegadas/s | Rampa de 6 para 40 chegadas/s |

São 205 segundos de chegadas e pico agregado de 740 chegadas/s ao final da rampa, sem patamar sustentado no pico. Consultas após criação acrescentam requisições. Os primeiros 25 segundos fazem parte da simulação; não excluir suas escritas do resultado ao reproduzir esse perfil. Aquecimento externo à medição, se utilizado, deve ser identificado separadamente.

**Adaptação para a rede social:** preservar os três envelopes de chegada, distribuindo ações sociais no primeiro e buscas nos demais. As proporções entre ações são parâmetros configuráveis do cenário, registrados antes de cada comparação. Aplicar 600 chegadas/s individualmente a cada rota não reproduz o orçamento de carga original. Leituras que antecedem compartilhamentos também devem aparecer no total de requisições.

A Rinha não define tamanho de grafo social, pois não tem seguidores nem cascatas. Tamanho, graus, centralidade e quantidade de posts iniciais são parâmetros configuráveis do cenário; a quantidade de pessoas criada ao final do torneio não equivale a um tamanho de grafo inicial.

Nos [scripts da primeira fase](https://github.com/zanfranceschi/rinha-de-backend-2023-q3/blob/main/rinha-primeira-fase.sh), a contagem é solicitada após o comando de carga terminar, sem espera adicional explícita para recuperação. Preservar uma medição de referência nesse instante; a validade final do nosso teste é determinada pelo corte configurado abaixo. Isso não equivale a um prazo fixo de recuperação: requisições em voo e o tempo de encerramento da ferramenta podem prolongar a execução. As esperas de inicialização dos contêineres não são janelas de recuperação.

O prazo adicional de processamento é configurável e igual nas duas arquiteturas. Ao encerrá-lo, aplicar a regra do corte final. Não atribuir esse prazo à Rinha nem esperar indefinidamente por fila vazia.

A taxa é de chegada de ações/iterações, não número de usuários simultâneos. Cada ação pode provocar várias requisições e muitas escritas internas; registrar as três grandezas separadamente.

Um modelo aberto mantém chegadas independentes da conclusão das ações anteriores; um modelo fechado pode reduzir a chegada quando as respostas ficam lentas. A documentação oficial do k6 explica essa distinção: [Open and closed models](https://grafana.com/docs/k6/latest/using-k6/scenarios/concepts/open-vs-closed/). Usar k6 para implementar o gerador, conforme solicitação do autor, preservando os envelopes de chegada da Rinha. A ferramenta original da Rinha é Gatling.

Calibrar o gerador antes da comparação para suportar o pico sem saturar. Informar ações programadas, iniciadas, não iniciadas, concluídas e interrompidas. Se o gerador não conseguir aplicar o perfil, marcar a execução como inválida para aquela comparação, em vez de interpretar o limite do gerador como capacidade do backend.

Requisições com timeout têm resultado de persistência desconhecido até auditoria; não repetir automaticamente e contar de novo. Registrar IDs retornados e identificar cada ação no log do gerador. Não repetir automaticamente operações de escrita no teste. Chaves únicas naturais cobrem seguir/compartilhar, mas não evitam duplicação de criação de posts após retry.

## Cortes de medição e integridade

- **T0:** base estabilizada, antes da carga.
- **T1:** fim programado das chegadas; 205 segundos no perfil de referência da Rinha.
- **TA:** término do gerador e medição de referência, como no procedimento da Rinha. Não reiniciar o prazo de processamento nesse instante.
- **TF:** corte final, calculado como T1 mais o prazo adicional configurado. O prazo pode ser zero e deve ser idêntico nas duas arquiteturas. Requisições em voo e workers não prolongam TF.

**Regra definida pelo autor:** em TF, conta apenas o que foi persistido dentro do prazo e atende ao contrato. O que permanece na fila ou é gravado depois não conta. Não criar campos de status intermediário nos recursos.

Um compartilhamento conta somente se houver presença válida no feed em TF. Entregas e desdobramentos cuja única origem seja um compartilhamento inválido ficam fora da contagem; caminhos válidos alternativos preservam a entrada única de feed.

Preservar o estado de TF por snapshot consistente ou interrupção coordenada das escritas no corte, com mecanismo equivalente validado para cada composição. A consulta tardia de `/metricas` não pode incorporar gravações posteriores. Essa preservação é responsabilidade do teste e não exige estados de negócio. Nenhum mecanismo de corte foi implementado por esta documentação.

Checagens finais: um feed por usuário; ausência de referências órfãs; unicidade de relações/compartilhamentos; contagem válida por categoria; correspondência entre respostas 201 e recursos duráveis no prazo; destinatários esperados e existentes por evento. Medir faltantes e excedentes. Fila vazia não substitui essas verificações.

Exemplo de amplificação (assumed, ilustrativo): um post com 1.000 seguidores produz um post original e até 1.000 novas entradas de feed. Um compartilhamento pode produzir menos entradas que seu número de seguidores se eles já receberam o original. Esses valores exemplificam a contagem; não são metas ou resultados.

## Repetições e relatório

Executar, por padrão configurável, três repetições independentes por composição/cenário, alternando a ordem das composições e restaurando a mesma base. Registrar a quantidade de repetições antes da coleta; apresentar valores individuais, mediana e mínimo/máximo por indicador; não selecionar apenas a melhor execução.

Preservar manifesto, sequência de ações, resultados HTTP, métricas, traces amostrados, logs de erro, auditoria e motivo de invalidação. Relatar valores individuais e dispersão, além dos agregados. Não inferir causalidade isolada de framework ou banco: o objeto do estudo são as composições completas.

## Parâmetros configuráveis de execução

Tamanho e distribuição do grafo, nós centrais, base inicial, taxas e proporções de ações, fração de usuários que compartilham, intervalos de consulta ao feed, timeouts, prazo adicional até TF, repetições e distribuição do orçamento entre componentes são configuração. Não bloqueiam a definição do contrato nem exigem confirmação individual. Seus valores efetivos devem constar no manifesto e permanecer iguais entre as execuções comparadas, exceto a distribuição interna de recursos permitida pelo orçamento comum.

Os números sugeridos em discussão não se tornam valores obrigatórios ou resultados experimentais por esta documentação.

## Implementação

Não há aprovações pendentes do protocolo. O gerador k6 e o relatório JSON estão em [../k6/](../k6/README.md). A preservação do corte e a auditoria completa de integridade permanecem pendentes; as contagens atuais são observacionais, sem pontuação final validada. Usar interrupção coordenada das escritas no corte e auditar o estado preservado, sem incorporar operações que concluam depois de TF. Validar esse mecanismo nas duas composições antes das execuções oficiais.
