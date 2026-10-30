# Projeto da arquitetura e roteiro de decisões

## Estado e finalidade

**Revisão metodológica confirmada:** a cascata será dinâmica, acionada por notificações realmente persistidas, sem cadeia de compartilhamentos pré-planejada. Essa decisão substitui a exigência anterior de agenda idêntica de compartilhamentos. A política de sementes permanece pendente. Repetições e análise de variabilidade deverão ser definidas sem assumir que médias eliminam vieses ou que o experimento deve confirmar uma vantagem esperada.

Documento de trabalho iniciado em 24/10/2026. Registra o levantamento dos três desenhos fornecidos pelos autores e organiza as decisões necessárias antes da implementação. Não constitui alteração do escopo acadêmico nem especificação aprovada para implementação.

Neste documento, **confirmado** significa explicitamente definido no repositório ou solicitado nesta conversa; **observado** significa presente nos desenhos; **proposta** exige discussão; **pendente** significa ainda não definido. Nenhuma capacidade ou vantagem de desempenho foi demonstrada.

O termo utilizado aqui é **projeto da arquitetura (system design)**. A documentação trata do backend, dos dados e da operação.

## Restrições confirmadas

- Comparar composições arquiteturais completas: Laravel/PHP-FPM/MySQL síncrono e Hyperf/Swoole/RabbitMQ/Neo4j orientado a eventos.
- Não atribuir eventual diferença observada a uma tecnologia isoladamente.
- Aplicação exclusivamente backend, com código futuro em `code/`.
- Nesta etapa, apenas documentação e decisões; nenhuma implementação.
- O trabalho atual de projeto e a primeira implementação se concentram no Hyperf. O Laravel será desenvolvido posteriormente; a comparação continua sendo objetivo do TCC.
- O processo deve ensinar os autores a avaliar alternativas e tomar decisões. Nenhuma decisão arquitetural será consolidada sem explicação e discussão com os autores.
- Preparar ambientes Docker reproduzíveis para ambas as arquiteturas.
- Planejar desde o Hyperf uma base comum de observabilidade de infraestrutura e código, reutilizável na futura comparação com Laravel. As ferramentas e a topologia dessa base ainda serão discutidas.
- Comparar sob orçamento equivalente de recursos e estímulo de carga controlado.
- Priorizar decisões arquiteturais compreensíveis; evitar otimizações prematuras.
- Investigar os limites de ambas as arquiteturas quanto à população cadastrada e ao uso simultâneo, sob recursos controlados. Não assumir capacidade de milhões antes dos ensaios.
- Preservar o artigo e o template institucional. Esta documentação técnica não substitui o TCC em LaTeX.

## Levantamento dos desenhos

### Modelo relacional observado

| Entidade        | Atributos desenhados                   | Relação desenhada                                  |
| --------------- | -------------------------------------- | -------------------------------------------------- |
| `users`         | `id`, `email`, `username`              | Um usuário possui vários posts e compartilhamentos |
| `posts`         | `id`, `content` (`VARCHAR`), `user_id` | Um post pertence a um usuário                      |
| `shares`        | `id`, `user_id`, `post_id`             | Cada compartilhamento associa usuário e post       |
| `posts_metrics` | `post_id` (PK e FK), `shares_count`    | Relação 1:1 com post                               |

O desenho não especifica tipos dos identificadores, tamanho do conteúdo, nulabilidade, unicidade, índices, datas ou exclusão. Não serão presumidos.

O fluxo mostra usuário criando post, notificação de seguidores e ações de curtir e compartilhar. Seguidores, curtidas, notificações e origem dos compartilhamentos não estão representados no modelo relacional apresentado. Sua inclusão e suas regras continuam pendentes.

### Arquitetura observada

O desenho apresenta monólito Hyperf com múltiplas instâncias, balanceador, banco relacional, broker, notificação, banco de grafos e observabilidade. As anotações mencionam PostgreSQL, Neo4j, Redis, envio de e-mail e `Parallel`.

Essas anotações não aprovam novas dependências. PostgreSQL diverge da composição Laravel/MySQL registrada no TCC; o papel de um banco relacional adicional no Hyperf precisa ser definido. Redis, distribuição do banco e separação entre leitura e escrita permanecem propostas. O canal de e-mail foi posteriormente limitado a uma simulação, conforme a decisão abaixo.

## Registro inicial de decisões

| ID  | Tema                                 | Estado                                               | Próxima definição necessária                                  |
| --- | ------------------------------------ | ---------------------------------------------------- | ------------------------------------------------------------- |
| D01 | Capacidade pretendida                | Objetivo confirmado; protocolo pendente               | Limites de população e simultaneidade; critérios e duração    |
| D02 | Operações do domínio                 | Pendente                                             | Seguir, publicar, curtir, compartilhar, consultar e notificar |
| D03 | Contrato comum da comparação         | Pendente                                             | Mesmo trabalho lógico e mesmo critério de conclusão           |
| D04 | Modelo relacional                    | Observado, incompleto                                | Invariantes, chaves, índices e histórico                      |
| D05 | Modelo do grafo                      | Neo4j confirmado; modelo pendente                    | Nós, arestas, direção, propriedades e consultas               |
| D06 | Autoridade sobre os dados            | Pendente                                             | Qual armazenamento decide a verdade de cada entidade          |
| D07 | Banco distribuído                    | Proposta                                             | Necessidade, topologia, custo e alternativa simples           |
| D08 | Separação leitura/escrita            | Proposta                                             | Consultas, consistência e atraso aceitável                    |
| D09 | Publicação de eventos                | RabbitMQ confirmado; protocolo pendente              | Falhas entre persistência e publicação                        |
| D10 | Workers e lotes                      | Processamento em lotes confirmado; política pendente | Concorrência, tamanho, tempo e confirmação                    |
| D11 | Notificação e fanout                 | Observado                                            | Destinatários, expansão, persistência e canal                 |
| D12 | Docker e recursos                    | Diretriz confirmada; configuração pendente           | Orçamento total por arquitetura                               |
| D13 | Observabilidade                      | Diretriz confirmada; configuração pendente           | Instrumentação, métricas e custo                              |
| D14 | Experimento                          | Pendente                                             | Carga, sementes, repetições, falhas e critérios de sucesso    |
| D15 | Ferramenta de carga e estresse comum | Requisito confirmado; ferramenta pendente            | Avaliar k6, Locust e JMeter conforme o protocolo              |

## Processo de aprendizado e decisão

Diretriz explicitada pelos autores: aprender durante a elaboração e o desenvolvimento, compreendendo também como decidir sem auxílio no futuro.

Para cada decisão arquitetural, a discussão deverá apresentar:

1. O problema concreto e um exemplo no sistema de viralização.
2. Os conceitos necessários, definidos em linguagem simples.
3. As alternativas viáveis, incluindo a opção mais simples.
4. Benefícios, custos, riscos e condições em que cada alternativa faz sentido.
5. A recomendação fundamentada e suas hipóteses, sem apresentá-las como resultados.
6. O que será medido para verificar a escolha e quando reconsiderá-la.
7. A resposta dos autores e o registro da decisão, ou sua manutenção como pendência.

Escolhas técnicas que afetem comportamento, consistência, durabilidade, infraestrutura, dependências ou validade da comparação devem aparecer na discussão. Detalhes rotineiros de documentação podem ser executados e relatados sem interromper cada edição.

Pesquisar fontes antes de fundamentar recomendações técnicas e registrar as fontes de engenharia em [referencias-tecnicas.md](referencias-tecnicas.md). Reservar `citations.md` às citações do TCC. Não escolher ferramentas apenas porque aparecem nos desenhos ou porque são populares.

## Observabilidade comum: requisitos e questões em aberto

**Confirmado:** observar infraestrutura e código nas duas arquiteturas usando uma base compartilhada. No momento, documentar essa base para o Hyperf e sua futura integração com Laravel, sem implementar o Laravel.

| Área                  | O que o projeto precisa permitir avaliar           | Definição pendente                        |
| --------------------- | -------------------------------------------------- | ----------------------------------------- |
| Infraestrutura        | Consumo e saturação de CPU, memória, disco e rede  | Ferramentas, coleta e limites             |
| Serviços              | Estado do runtime, bancos, broker e workers        | Integrações e métricas de cada componente |
| Código                | Caminho das operações, tempos por etapa e falhas   | Instrumentação e propagação de contexto   |
| Resultado do trabalho | Operações aceitas, concluídas, falhas e pendências | Critérios comuns de conclusão e correção  |
| Experimentos          | Associação de cada medição ao sistema e ao ensaio  | Identificação, retenção e exportação      |

Questões a resolver ao projetar a base:

- O que será comum: convenções, coletores, armazenamento e painéis? Quais partes exigirão adaptações por framework?
- Como acompanhar uma operação desde a requisição até o último efeito assíncrono?
- Quais métricas permitem comparação direta e quais descrevem componentes específicos de cada composição?
- Como igualar a política de coleta e amostragem, documentando diferenças inevitáveis?
- Onde executar a coleta e como medir e contabilizar seu custo nos ensaios?
- Como impedir que dados de um ensaio sejam confundidos com os de outro?
- Quais dados não devem aparecer nos registros, e como controlar volume e retenção?

Prometheus, Grafana e OpenTelemetry/APM já aparecem como possibilidades no escopo do TCC; a seleção final e os componentes adicionais continuam pendentes. Não há stack instalada nem instrumentação validada.

## Ferramenta comum de carga e estresse

**Confirmado:** utilizar a mesma ferramenta nos ensaios Hyperf e Laravel. A escolha ainda não foi aprovada nem instalada.

**Proposta:** avaliar primeiro k6. Sua documentação descreve um executor de taxa de chegada constante: novas iterações são iniciadas na taxa configurada independentemente do tempo de resposta, desde que existam usuários virtuais suficientes. Uma iteração representa uma execução do roteiro; só equivale a uma requisição quando o roteiro faz exatamente uma requisição.

Para entender a escolha, considere um sistema que começa a responder lentamente: queremos distinguir um teste que mantém a pressão de chegada de outro que espera respostas antes de gerar novas operações. Ambos podem ter uso; o protocolo deve explicitar qual comportamento pretende reproduzir.

| Candidato | Capacidade verificada na documentação oficial                   | Ponto a avaliar neste projeto                               |
| --------- | --------------------------------------------------------------- | ----------------------------------------------------------- |
| k6        | Executor `constant-arrival-rate` e métrica `dropped_iterations` | Controle da chegada e detecção de carga não gerada          |
| Locust    | Geração distribuída entre processos e máquinas                  | Modelagem dos comportamentos e controle da carga pretendida |
| JMeter    | Execução remota/distribuída                                     | Manutenção e reprodução dos planos de teste                 |

Essa comparação inicial não demonstra superioridade de desempenho entre as ferramentas. A recomendação de começar avaliando k6 é uma proposta de adequação ao experimento, não uma conclusão experimental.

Antes de decidir, exigir:

- Execução automatizada em Docker, versão fixada e roteiro versionado.
- Mesmo estado lógico inicial, regras de comportamento, distribuições de carga e política de retentativas para ambos os sistemas. Compartilhamentos surgirão dinamicamente; sementes permanecem pendentes.
- Perfis de carga constante, crescimento gradual, pico e recuperação após o pico.
- Registro separado de carga planejada, efetivamente iniciada, aceita e concluída.
- Medição dos recursos do gerador para identificar se ele limita o ensaio.
- Política de expansão do gerador caso uma máquina não alcance a carga necessária, sem presumir equivalência entre implementações distribuídas das ferramentas.
- Exportação dos resultados com identificação do ensaio e integração com a observabilidade comum.
- Verificação do resultado final assíncrono: resposta HTTP de aceitação não comprova persistência de todos os efeitos. O mecanismo de acompanhamento e seu custo continuam pendentes.
- Definir como a ferramenta observará notificações persistidas para produzir a cascata dinâmica. Uma taxa de chegada, isoladamente, não define a topologia nem a propagação da viralização.

Fontes consultadas em 24/10/2026: [k6: taxa de chegada](https://grafana.com/docs/k6/latest/using-k6/scenarios/executors/constant-arrival-rate/), [k6: métricas](https://grafana.com/docs/k6/latest/using-k6/metrics/reference/), [Locust: geração distribuída](https://docs.locust.io/en/stable/running-distributed.html) e [JMeter: testes remotos](https://jmeter.apache.org/usermanual/remote-test.html). Documentação oficial continuamente atualizada; ano de publicação das páginas não confirmado. Registros completos em [referencias-tecnicas.md](referencias-tecnicas.md); não integram as citações do TCC.

## Ordem de discussão

As perguntas serão resolvidas uma por vez, começando pelas dependências. Uma recomendação será acompanhada de justificativa, alternativas e fontes verificadas antes de ser consolidada como decisão técnica. Alterações de escopo exigem confirmação dos autores.

### 1. Capacidade e sucesso

- Distinguir usuários cadastrados, ativos, simultâneos e conexões abertas.
- Definir operações por segundo, proporção leitura/escrita, tamanho dos dados e duração dos picos.
- Definir distribuição de seguidores, concentração nos usuários mais conectados e evolução das cascatas.
- Definir latência aceitável, taxa de erro, atraso máximo de processamento e tempo de recuperação.
- Definir o significado operacional de saturação e de queda.
- Separar base com milhões de usuários de teste com milhões de acessos simultâneos.

### 2. Regras do domínio e equivalência

- Definir se seguir é direcionado, se há auto-seguimento e como desfazer relações.
- Definir unicidade de curtida/compartilhamento e semântica de repetição.
- Definir se compartilhamentos propagam novas notificações e como registrar sua origem.
- Definir destinatários quando a relação de seguidores muda durante o processamento.
- Definir visibilidade, edição, exclusão e retenção de posts e usuários.
- Definir se haverá feed, autenticação e canais externos; não presumir funcionalidades novas.
- Descrever o mesmo resultado final esperado em Laravel e Hyperf.
- Distinguir aceitação HTTP, persistência durável e conclusão de todos os efeitos.

### 3. Persistência relacional e grafo

- Produzir modelos lógico e físico com chaves, restrições, índices e consultas atendidas.
- Definir nós e relações do grafo, direção das arestas, identificadores e atributos temporais.
- Definir como consultar seguidores e reconstruir uma cascata sem confundir criação, exposição e compartilhamento.
- Registrar exemplos pequenos de dados e resultados esperados das consultas.
- Definir autoridade de cada dado, necessidade de cópias e reconstrução de projeções.
- Definir consistência dos contadores e reconciliação com os registros de origem.
- Avaliar explicitamente a linha de métricas do post viral como possível ponto de disputa (hipótese a medir).
- Separar as propostas de replicação, particionamento e distribuição entre máquinas.
- Avaliar leitura/escrita: réplica do mesmo modelo ou modelo de consulta separado; definir atraso e leitura após escrita.
- Verificar edições, licenças e capacidades dos produtos antes de escolher topologias.
- Definir migração, geração inicial dos dados, backup e restauração.

### 4. Fluxo de dados e falhas

- Desenhar sequências de publicação, compartilhamento, notificação e consulta.
- Especificar eventos: identificador, versão, origem, correlação, instante e conteúdo.
- Definir transações locais e tratamento da falha entre banco e broker.
- Definir duplicação, idempotência, ordenação e reprocessamento.
- Definir confirmação do publicador e do consumidor, persistência e recuperação.
- Definir retentativas limitadas, mensagens inválidas e intervenção operacional.
- Especificar falhas em cada fronteira: antes/depois de commit, publicação e confirmação.

### 5. Workers e controle da carga

- Definir filas por finalidade e responsabilidade de cada worker.
- Definir concorrência, prefetch, conexões, lotes por quantidade/tempo e limites de memória.
- Definir expansão paginada de destinatários e retomada após interrupção.
- Definir contenção, distribuição desigual de trabalho e isolamento de falhas.
- Definir limites de admissão, resposta à sobrecarga e drenagem após o pico.
- Justificar qualquer uso de Redis ou paralelismo adicional antes de incluí-lo.

### 6. Infraestrutura reproduzível e comparação

- Planejar Docker Compose, imagens fixadas, dependências, volumes e verificações de prontidão.
- Definir CPU e memória totais de cada composição, incluindo bancos, broker, workers e balanceador.
- Definir armazenamento, limites de I/O, rede, durabilidade e persistência equivalentes.
- Definir onde ficam gerador de carga e observabilidade e como contabilizar sua interferência.
- Executar cada arquitetura isoladamente no mesmo hardware controlado, se esse desenho experimental for aprovado.
- Documentar sistema operacional, hardware, versões, configurações e limites efetivamente aplicados.
- Definir orçamento constante para comparar escalabilidade e protocolo separado para aumentar recursos.
- Não tratar múltiplos contêineres em uma máquina como demonstração de tolerância à perda de uma máquina.
- Definir segredos, acessos, TLS conforme ambiente, desligamento e recuperação.
- Planejar comandos futuros de inicialização, carga inicial, execução, coleta e limpeza; ainda não implementá-los.

### 7. Medição e validação

- Usar o mesmo dataset lógico e regras comuns de simulação dinâmica; definir a política de sementes e registrar a carga efetivamente produzida.
- Medir latência HTTP e tempo até o efeito final, vazão concluída, erros e trabalho pendente.
- Medir filas, idade das mensagens, retentativas, falhas permanentes e reconciliação.
- Medir CPU, memória, disco, rede, conexões, bloqueios e consultas.
- Definir correlação entre HTTP, evento, lote e efeito persistido.
- Definir aquecimento, duração, repetições, ordem dos ensaios e tratamento da variabilidade.
- Testar reinício de worker, indisponibilidade do broker/banco, duplicação e recuperação.
- Verificar correção por invariantes e totais, além de desempenho.
- Definir término da coleta e contabilizar mensagens ainda pendentes; resposta de aceitação não encerra o trabalho.
- Registrar limites da máquina disponível e evitar extrapolar capacidade não testada.

## Artefatos a produzir conforme as decisões avancem

1. Glossário do domínio, após resolver os termos.
2. Requisitos funcionais e metas mensuráveis.
3. Diagramas de contexto, componentes, implantação, entidades e grafo.
4. Sequências normais e de falha, contratos HTTP e eventos.
5. Registros de decisões arquiteturais relevantes, com alternativas e consequências.
6. Protocolo experimental, matriz de recursos e plano de observabilidade.
7. Plano de implementação por etapas e critérios de aceite.
8. Guia de operação, recuperação e reprodução para quem estudar o sistema.

Cada decisão deverá registrar estado, motivação, alternativas, consequência para as duas arquiteturas, fontes, validação necessária e perguntas restantes. Os desenhos finais devem distinguir arquitetura aprovada de evolução futura.

## D01: objetivo de capacidade confirmado

Os autores confirmaram que desejam encontrar o máximo suportado pelas duas arquiteturas, tanto em população cadastrada quanto em uso simultâneo. Milhões de usuários constituem uma escala de interesse, não um resultado garantido. O foco atual continua sendo o projeto do Hyperf.

**Proposta de protocolo, ainda não aprovada:** construir uma matriz de ensaios. Para cada tamanho de população e de suas relações, aumentar progressivamente a carga. Repetir a mesma matriz na outra arquitetura, sob orçamento equivalente de recursos. Registrar separadamente a carga oferecida, a atendida e o trabalho concluído.

Não definir um único “máximo de usuários” sem informar população, relações, operações executadas, taxa de chegada, simultaneidade observada, duração e recursos. A distribuição de seguidores e o volume de posts e compartilhamentos também deverão acompanhar cada resultado.

**Proposta de interpretação dos limites:** registrar a capacidade sustentável (dentro de critérios de latência, erro e atraso a definir), o comportamento em sobrecarga e a recuperação após o pico. Investigar a falha também faz parte do objetivo, mas o instante da queda não será o único resultado.

O número de usuários virtuais do gerador não será automaticamente interpretado como usuários reais simultâneos ou requisições simultâneas. A definição operacional de simultaneidade dependerá do roteiro aprovado.

Se o hardware, o tempo disponível ou o gerador impedirem avançar, registrar o limite do ensaio. Não declarar que o limite da arquitetura foi encontrado sem evidência correspondente.

## D12: ambiente de execução confirmado

Os ensaios serão executados na máquina de quem estiver testando, usando Docker com recursos limitados e condições equivalentes para as duas arquiteturas. Não se exige servidor dedicado ou nuvem. Os valores dos limites ainda não foram escolhidos.

**Proposta para concretizar a equivalência:** definir um perfil versionado com orçamento total de CPU e memória para cada composição, distribuído entre seus serviços. Banco, broker, aplicação, workers e balanceador entram nessa soma. Dar o mesmo limite a cada contêiner, havendo quantidades diferentes de contêineres, não satisfaz esse orçamento total.

**Proposta de execução:** comparar Hyperf e Laravel na mesma máquina, um de cada vez, com o mesmo perfil, dataset e roteiro. Reservar recursos para o host, o gerador de carga e a observabilidade, com limites e interferência registrados separadamente. Não executar ambos disputando os recursos durante o ensaio comparativo.

Os limites deverão ser aplicados explicitamente e conferidos em execução. Docker não limita CPU e memória por padrão, conforme a [documentação oficial](https://docs.docker.com/engine/containers/resource_constraints/). Definir também swap, armazenamento, condições de I/O e rede, sem presumir que limites de CPU e memória padronizem todo o ambiente.

**Limitação do protocolo:** adotar comparação pareada por máquina. Um perfil Docker comum não será tratado como garantia de desempenho idêntico entre computadores diferentes. Registrar hardware, sistema operacional, Docker/Compose e eventual máquina virtual do Docker Desktop em cada ensaio; não misturar resultados de máquinas diferentes como se fossem repetições do mesmo ambiente.

Caso um perfil não caiba no host, o ensaio correspondente não será considerado equivalente. Um perfil menor poderá ser proposto, mas deverá ser aplicado às duas arquiteturas e identificado separadamente.

## Fluxo de cascata: confirmação e proposta de modelagem

**Confirmado pelos autores:** seguir usuários, publicar, notificar seguidores e propagar a publicação por compartilhamentos sucessivos. Exemplo desejado: um autor com um milhão de seguidores e sucessivas ondas de compartilhamento. Curtidas e outras funcionalidades não foram confirmadas por essa aprovação do fluxo.

Os autores exemplificaram uma primeira onda de 500 mil compartilhamentos. O valor escrito para a onda seguinte foi “250”; confirmar se significa 250 ou 250 mil antes de converter o exemplo em parâmetros. Nenhuma dessas quantidades constitui taxa empírica.

**Decisão confirmada:** utilizar um único modelo de cascata sintética sobre grafo, com intensidade configurável e propagação dinâmica (política de sementes pendente). Cada compartilhamento alcança seguidores do usuário que compartilhou. A continuidade depende das relações, de exposição/atividade simuladas, da decisão de compartilhar e do tempo até essa decisão. Não impor que cada onda tenha metade do tamanho anterior. Não manter um segundo perfil de quantidades fixas por geração.

A recomendação de variar exposição, decisão e profundidade é inspirada em Liang (2021), que investiga relações entre compartilhamento, exposições, similaridade de interesses e profundidade das cascatas. O trabalho não fornece uma taxa universal de compartilhamento nem valida os parâmetros deste projeto. Não chamar o simulador de representação fiel de uma plataforma real sem calibração e validação externas.

Para a primeira versão do perfil sobre grafo, **propõe-se**:

1. Um grafo direcionado de seguidores, com graus desiguais e audiências sobrepostas. A distribuição e o método de geração permanecem pendentes.
2. Separar destinatário elegível, notificação persistida, exposição simulada e compartilhamento confirmado. Não considerar todo notificado como leitor.
3. Decidir a exposição e o compartilhamento por sorteios reproduzíveis com parâmetros explícitos; introduzir interesses e efeitos de múltiplas exposições somente se justificados pelo experimento.
4. Distribuir os tempos de reação segundo parâmetros explícitos. A concentração temporal da carga será configurável dentro do mesmo modelo.
5. **Regra confirmada:** cada usuário pode compartilhar cada post original uma única vez, mas pode compartilhar vários posts diferentes. A unicidade é do par `(usuário, post original)`, inclusive quando o mesmo post chega por caminhos diferentes. Reprocessar a mesma ação não deve criar outro compartilhamento, incrementar novamente seu contador ou iniciar outra propagação. O mecanismo para garantir essa regra será definido no desenho da persistência e dos workers.
6. Registrar post original e compartilhamento que originou a ação simulada. Se houver múltiplas exposições, definir a regra de atribuição; essa atribuição é conhecida no simulador, não uma inferência causal sobre usuários reais.
7. Encerrar quando não houver ações agendadas nem trabalho pendente, ou quando atingir uma janela/limite de segurança. Distinguir extinção natural de interrupção do ensaio e contabilizar trabalho restante.

### Regra confirmada de notificações por compartilhamento

O destinatário recebe uma notificação por pessoa que compartilhou o post e que ele segue. Se três pessoas seguidas compartilharem o mesmo post original, serão três notificações, embora o destinatário só possa compartilhar esse post uma vez. Não agrupar essas notificações em uma única ocorrência por post.

A identidade lógica da notificação é o par `(destinatário, compartilhamento)`. Uma nova tentativa de processar o mesmo compartilhamento não deve produzir outra notificação para o mesmo destinatário. O mecanismo de persistência dessa garantia permanece pendente. A regra para notificar a publicação original será detalhada separadamente.

### Canal de e-mail simulado

**Confirmado pelos autores:** não haverá integração real com provedor de e-mail. O envio poderá ser simulado por um componente substituto (mock) ou registro em log. Isso não representa entrega real a uma caixa postal.

**Proposta de implementação:** usar o mesmo contrato de envio simulado nas duas arquiteturas, produzindo um registro estruturado com identificador da notificação, destinatário, canal e resultado. Separar notificação persistida de envio simulado concluído. Um log de tentativa não comprova conclusão; tentativas repetidas também não devem ser contadas como novas entregas lógicas.

O destino dos registros, sua retenção, o custo de armazenamento e a eventual simulação de latência ou falhas permanecem pendentes. Esses parâmetros deverão ser iguais nas duas arquiteturas e registrados no cenário. A simulação permite avaliar o fluxo interno, mas não fundamenta conclusões sobre desempenho de provedores reais de e-mail.

**Exemplo exclusivamente ilustrativo, com valores assumidos:** de um milhão de destinatários, 100 mil são expostos na janela simulada; destes, mil compartilham. As próximas audiências vêm dos seguidores desses mil usuários, com sobreposição. Esses números não são estimativas de uma rede social real nem parâmetros aprovados.

**Decisão confirmada para comparação:** usar cascatas dinâmicas sob regras e distribuições comuns. Não exigir que as duas arquiteturas produzam a mesma sequência ou quantidade efetiva de compartilhamentos; registrar essas diferenças, pois o trabalho entregue influencia a carga subsequente.

**Esclarecimento confirmado em 29/10/2026:** manter a simulação dinâmica e aleatória. A redução da propagação em decorrência de atrasos ou falhas sob estresse faz parte do comportamento a observar, não deve ser compensada artificialmente pelo gerador. Isso não antecipa um resultado experimental. Menor carga efetiva, isoladamente, não demonstrará melhor desempenho; avaliar conjuntamente trabalho concluído, atrasos e pendências.

### Pré-requisito para compartilhar e comportamento do simulador

**Contrato da aplicação preservado:** basta que o post original exista para permitir compartilhar, respeitando as demais restrições do domínio. A API não exigirá a conclusão de uma notificação ou de um envio simulado.

**Comportamento do simulador confirmado:** observar notificações realmente persistidas e, a partir delas, sortear se o destinatário compartilhará. Sem essa notificação, não gerar a ação correspondente. Não aguardar o envio simulado de e-mail para gerar o compartilhamento. Os mecanismos de observação e sorteio serão discutidos separadamente. A introdução de tempo de reação é uma proposta ainda não aprovada, não uma exigência do modelo dinâmico.

**Exposições repetidas confirmadas pelos autores:** cada notificação distinta do mesmo post dá ao destinatário uma nova chance de compartilhar, enquanto ele ainda não tiver compartilhado. Observar novamente a mesma notificação não constitui uma nova chance. A probabilidade será um parâmetro explícito do simulador, sem ser apresentada como taxa empírica de uma rede social.

O limite continua sendo um compartilhamento por usuário e post original, mesmo após vários sorteios favoráveis. A política para novas notificações quando já houver um compartilhamento agendado, mas ainda não executado, permanece pendente; não presumir que cada sorteio favorável deva produzir outra requisição.

**Início preservado:** a cascata depende da persistência do post original. Se sua criação falhar, registrar a falha e não iniciar a cascata. Medir a criação, a propagação e o tempo total separadamente. A persistência da notificação que motiva uma ação não comprova entrega real de e-mail nem comportamento de um usuário real.

### Reprodução e imparcialidade

**Pendente após revisão:** manter ou remover sementes registradas. A cascata dinâmica está aprovada independentemente dessa escolha; não prometer reprodução exata de execução concorrente.

**Confirmado: grafo de seguidores fixo durante cada ensaio.** Usuários e relações de seguidores serão carregados antes da carga medida; não haverá ações de seguir ou deixar de seguir durante a cascata. Publicações, compartilhamentos e notificações continuarão sendo criados durante o ensaio. Portanto, a decisão fixa as relações de seguidores, não todo o estado do banco.

Entre cenários, poderão variar a população e a topologia, conforme parâmetros documentados. Dentro de cada comparação pareada, as duas arquiteturas receberão os mesmos usuários e relações. O ensaio não avaliará o custo de modificar relações de seguidores sob carga; o tempo de preparação deverá ser registrado separadamente da execução da cascata.

**Proposta de detalhamento, a validar antes da implementação:**

- Registrar algoritmo, versão do simulador, parâmetros, distribuições e versão dos registros do cenário.
- Preservar os arquivos de usuários e relações com identificadores estáveis e hashes, representando os mesmos dados lógicos nas duas arquiteturas.
- Registrar notificações observadas, decisões de comportamento, origem, ator, post original e horários de agendamento e execução de cada ação gerada durante o ensaio.
- Garantir uma chance por notificação distinta, sem repetir sorteios pela releitura da mesma notificação. Definir o tratamento de compartilhamentos já agendados, respeitando um compartilhamento por usuário e post original.
- Restaurar o mesmo estado lógico inicial, sem resíduos de ensaios anteriores. Aquecimento e política de sementes permanecem pendentes.
- Registrar ações geradas, iniciadas, aceitas, concluídas e falhas; atrasos do próprio simulador também devem ser observados.
- Definir repetições e análise estatística antes de comparar resultados. Avaliar a variação e a carga efetiva, sem selecionar somente ensaios favoráveis.

A carga é realimentada pelo processamento: menos notificações disponíveis podem produzir menos compartilhamentos. Comparar somente a latência ou a média sem considerar o trabalho gerado e concluído pode ocultar essa diferença. Cascatas não idênticas deverão ser descritas e consideradas na interpretação.

**Medições propostas:** usuários únicos alcançados, exposições repetidas, compartilhamentos por geração, profundidade, duração, notificações produzidas e trabalho concluído. Na comparação, controlar tamanho e formato da cascata; Juul e Ugander (2021) mostram a importância de controlar o tamanho ao comparar estruturas de difusão.

Fontes e limitações da consulta registradas em [referencias-tecnicas.md](referencias-tecnicas.md). Nenhuma alteração foi feita nas referências do artigo.

## Leituras durante a viralização

**Confirmado pelos autores:** incluir consultas a posts e notificações pela API durante a carga de publicação e compartilhamento.

**Escopo confirmado das leituras:** consultar um post por identificador e listar notificações do usuário com paginação. Não haverá feed de publicações das pessoas seguidas; o foco permanece na cascata e em seus efeitos.

**Proposta de detalhamento:** definir frequência e distribuição das consultas no cenário reproduzível, aplicando as mesmas regras de consulta nas duas arquiteturas; a observação de notificações alimentará a cascata dinâmica. Registrar latência, erros e conteúdo retornado, distinguindo uma consulta bem-sucedida de uma notificação ainda pendente de processamento. Consultar notificações não significa automaticamente marcá-las como lidas nem comprova o envio simulado de e-mail.

Detalhes da paginação, ordenação, tamanho das respostas e proporção entre leitura e escrita permanecem pendentes. A inclusão de leituras não aprova automaticamente bancos separados para leitura e escrita.

## Cascatas simultâneas

**Confirmado pelos autores:** permitir várias publicações originais com cascatas simultâneas no mesmo modelo. Quantidade de publicações e intervalos entre suas criações serão configuráveis e reproduzíveis, assim como a intensidade das cascatas. Os valores ainda não estão definidos.

As cascatas utilizarão o grafo de seguidores do cenário. Um usuário poderá participar de várias, mantendo o limite de um compartilhamento por usuário e publicação original. Cada cascata começará após a confirmação da persistência de seu próprio post, sem esperar que as demais terminem.

Registrar os instantes planejados e reais das criações e do início de cada cascata. Atrasos ou falhas de criação poderão alterar a sobreposição efetiva; isso deverá aparecer nos resultados. Não recalibrar a agenda de apenas uma arquitetura para ocultar essa diferença.

**Proposta de observação:** identificar a publicação original nas ações e nos registros de rastreamento para conferir o trabalho por cascata, além dos totais do ensaio. O desenho da instrumentação ainda será definido.

## Janela de recuperação após a carga

**Confirmado pelos autores:** após encerrar a geração de carga, manter uma janela sem novas ações do gerador para acompanhar a conclusão do trabalho pendente e a recuperação. Haverá uma duração máxima definida no cenário, igual para as duas arquiteturas; seu valor permanece pendente.

Durante essa janela, os processos necessários ao trabalho já aceito continuarão executando, incluindo requisições em andamento, workers e tentativas internas previstas pela política de processamento. Não desligar consumidores nem descartar filas ao encerrar a geração. A observabilidade continuará ativa, com seus custos contabilizados conforme o protocolo comum.

Separar trabalho concluído durante a carga, concluído durante a recuperação, falho e ainda pendente ao final. Registrar também o volume pendente no início da janela e o tempo até sua conclusão, quando ocorrer. Não contar uma requisição apenas aceita como trabalho concluído nem somar conclusões tardias à vazão medida exclusivamente durante a carga.

**Detalhes pendentes:** critérios observáveis de recuperação, política de tentativas e condições para encerrar antecipadamente a janela quando não houver trabalho restante. Fila vazia, isoladamente, não comprova ausência de trabalho em execução ou de falhas.

## Critério de conclusão de uma notificação

**Confirmado pelos autores:** uma notificação será considerada totalmente concluída somente quando estiver persistida e seu envio de e-mail simulado tiver sido registrado com sucesso. Medir separadamente a conclusão de cada etapa e a conclusão conjunta.

Se a persistência terminar, mas o envio simulado falhar ou permanecer pendente, a notificação não contará como totalmente concluída. Repetir tentativas não deverá aumentar a quantidade de notificações lógicas concluídas. O sucesso simulado não comprova entrega real de e-mail.

O critério vale para cada destinatário. Uma publicação ou um compartilhamento com parte dos destinatários ainda pendente não poderá ser apresentado como tendo todas as notificações concluídas. O mecanismo para determinar e conferir o conjunto esperado de destinatários será definido no desenho do processamento.

## Próxima etapa e questões pendentes

Construir o modelo lógico passo a passo com os autores, explicando cada decisão antes de avançar. Não consolidar antecipadamente todo o modelo. Permanecem pendentes a divisão de responsabilidades entre bancos, os mecanismos de unicidade e recuperação, a ferramenta de carga, os valores dos recursos e os critérios de capacidade sustentável.

### Primeiro passo: usuário

**Confirmado pelos autores:** o usuário terá identificador estável (`id`) e nome público (`username`). O `username` será único sem diferenciar maiúsculas de minúsculas: `Maria` e `maria` não poderão identificar usuários distintos. O mecanismo de comparação e as demais regras de formato permanecem pendentes.

Não haverá campo de e-mail no usuário. Essa decisão substitui o atributo presente no desenho inicial, preservado acima como levantamento histórico. O envio simulado identificará o destinatário pelo usuário, sem exigir endereço real de e-mail; continua valendo o critério de conclusão já aprovado.

### Segundo passo: relação de seguir, em discussão

**Confirmado pelos autores:** um usuário não pode seguir a si próprio. Os identificadores de quem segue e de quem é seguido devem ser diferentes.

**Confirmado pelos autores:** a relação é direcionada e única por par `(seguidor, seguido)`. Ana seguir Bruno não implica Bruno seguir Ana; as duas relações podem coexistir. Registrar novamente o mesmo par não deve criar outra relação nem duplicar esse destinatário na audiência de uma mesma ação.

Na representação lógica `seguidor → seguido`, cada usuário pode seguir vários usuários e ser seguido por vários usuários. Isso caracteriza uma relação de muitos para muitos entre usuários. A representação física será discutida separadamente; a regra de unicidade deverá valer tanto nos dados gerados quanto no armazenamento.

### Terceiro passo: publicação, em discussão

**Confirmado pelos autores:** cada post tem exatamente um autor, e um usuário pode criar vários posts. Um usuário pode existir sem ter publicado. A relação entre usuário e publicação é de um para muitos; não haverá múltiplos autores no mesmo post.

O autor identifica de quem consultar os seguidores para a propagação da publicação original. O compartilhamento por outro usuário não altera a autoria do post original. Os demais atributos e as regras de conteúdo serão discutidos antes de consolidar a publicação.

**Escopo inicial confirmado pelos autores:** conteúdo textual obrigatório, sem imagens ou anexos nesta primeira versão. Essa delimitação poderá ser reconsiderada explicitamente; não pressupõe suporte futuro a mídia.

**Validação confirmada pelos autores:** no máximo 500 caracteres; rejeitar texto vazio ou composto apenas por espaços. O limite é uma decisão de escopo, não um resultado experimental. A definição precisa de contagem de caracteres Unicode e de espaços será detalhada na especificação de validação, com comportamento equivalente nas duas arquiteturas. Nos cenários, registrar também o tamanho em bytes do conteúdo gerado.

**Ciclo de vida inicial confirmado pelos autores:** posts não poderão ser editados nem excluídos nesta primeira versão. A restrição evita introduzir regras de alteração ou remoção do post original durante a propagação. A restauração do ambiente entre ensaios é uma operação de preparação experimental, não uma funcionalidade de exclusão de posts.

### Quarto passo: compartilhamento, em discussão

**Confirmado pelos autores:** o compartilhamento é uma ação com identidade própria, vinculada ao usuário que compartilhou e ao post original. Não cria uma cópia da publicação nem permite adicionar comentário nesta primeira versão. A autoria e o conteúdo permanecem no post original.

Cada compartilhamento está associado a exatamente um usuário e um post original. Um usuário pode compartilhar vários posts, e um post pode receber compartilhamentos de vários usuários, respeitando a unicidade já aprovada do par `(usuário, post original)`.

**Restrição confirmada pelos autores:** o autor não pode compartilhar seu próprio post. O usuário que compartilha deve ser diferente do autor da publicação original. Essa regra é independente da proibição de seguir a si próprio: mesmo sem uma relação de seguidor, a tentativa de compartilhar o próprio post deverá ser rejeitada.

**Confirmado pelos autores:** salvar também a origem do compartilhamento. No modelo dinâmico aprovado, ela será obtida da notificação persistida que motivou a ação simulada: publicação original ou compartilhamento anterior. Não criar uma origem referente a uma ação apenas planejada.

Essa informação permitirá reconstruir o caminho das ações realmente realizadas e suas gerações. A origem deve pertencer ao mesmo post original. Sua representação física e as verificações de integridade serão discutidas posteriormente. O vínculo comprova a origem usada pelo simulador, não uma leitura por pessoa real ou entrega real de e-mail.

### Quinto passo: notificação, em discussão

**Confirmado pelos autores:** cada notificação está vinculada a exatamente um destinatário e ao acontecimento que a gerou: uma publicação original ou um compartilhamento. Essas alternativas representam a causa da notificação; uma notificação não tem as duas como causas simultâneas. Quando a causa for um compartilhamento, o post original continuará identificável pelo vínculo desse compartilhamento.

Um usuário pode receber várias notificações, e um acontecimento pode gerar notificações para vários destinatários. Isso permite distinguir a publicação por seu autor do compartilhamento por outro usuário e identificar a origem da ação simulada na cascata. A representação física desses vínculos permanece pendente.

**Unicidade confirmada pelos autores:** haverá no máximo uma notificação por par `(destinatário, acontecimento de origem)`, tanto para a publicação original quanto para cada compartilhamento. Reprocessar o mesmo acontecimento não poderá criar outra notificação para o mesmo destinatário. Compartilhamentos distintos do mesmo post são acontecimentos distintos e poderão gerar notificações distintas para esse destinatário, conforme a regra já aprovada.

A unicidade do registro não define, por si só, como evitar repetições do envio simulado. O controle das tentativas e a recuperação após falhas serão discutidos no fluxo de processamento.
