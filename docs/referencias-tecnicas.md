# Referências técnicas da arquitetura

## Modelagem das cascatas

- **Título:** *Decreasing Social Contagion Effects in Diffusion Cascades: Modeling Message Spreading on Social Media*.
- **Autor:** Hai Liang.
- **Ano e publicação:** 2021; artigo científico; *Telematics and Informatics*, v. 62, art. 101623; Elsevier.
- **DOI:** https://doi.org/10.1016/j.tele.2021.101623 .
- **URL consultada:** https://research.cuhk.edu.hk/en/publications/decreasing-social-contagion-effects-in-diffusion-cascades-modelin-2/ .
- **Data de acesso:** 24 out. 2026.
- **Conteúdo consultado:** resumo e metadados no portal institucional da Chinese University of Hong Kong.
- **Fundamenta:** considerar relações entre exposição, similaridade de interesses e profundidade ao discutir modelos de compartilhamento; não fundamenta percentuais universais.
- **Citações:** portal exibia 22 citações Scopus na consulta; indicador sujeito a atualização.
- **Status:** apoio à proposta de engenharia; fonte já existente em `citations.md` e citada no artigo, sem nova entrada acadêmica.

- **Título:** *Comparing Information Diffusion Mechanisms by Matching on Cascade Size*.
- **Autores:** Jonas L. Juul; Johan Ugander.
- **Ano e publicação:** 2021; artigo científico; *Proceedings of the National Academy of Sciences*, v. 118, n. 46, e2100786118.
- **DOI:** https://doi.org/10.1073/pnas.2100786118 .
- **URL consultada:** https://pubmed.ncbi.nlm.nih.gov/34750252/ .
- **Data de acesso:** 24 out. 2026.
- **Conteúdo consultado:** resumo e metadados indexados no PubMed; identificadores PMID 34750252 e PMCID PMC8609637.
- **Fundamenta:** controle do tamanho das cascatas ao comparar suas estruturas.
- **Citações:** quantidade não verificada.
- **Status:** apoio ao protocolo proposto; fonte já existente em `citations.md` e citada no artigo, sem nova entrada acadêmica.

## Docker: limites de recursos

- **Título:** *Resource constraints*.
- **Autor institucional:** Docker; documentação oficial.
- **Ano:** publicação não confirmada; página continuamente atualizada.
- **URL:** https://docs.docker.com/engine/containers/resource_constraints/ .
- **Data de acesso:** 24 out. 2026.
- **Fundamenta:** ausência de limites por padrão; configuração explícita de CPU, memória e swap.
- **Status:** consultada para planejar o ambiente experimental; não citada no TCC.

Fontes de apoio às decisões de engenharia. Não integram a bibliografia do TCC. Caso sejam utilizadas no artigo, deverão ser verificadas segundo os critérios acadêmicos do projeto e registradas em `citations.md`.

### Grafana k6 — geração de carga e métricas

- **Títulos:** *Constant arrival rate*; *Built-in metrics*; *Install k6*; repositório oficial *grafana/k6*.
- **Autor institucional e publicação:** Grafana Labs; documentação oficial do k6.
- **Ano:** não informado nas páginas consultadas; documentação continuamente atualizada. Acesso em 2026 não equivale a publicação em 2026. Verificar edição datada de 2021 ou posterior antes de usar como referência acadêmica.
- **DOI:** não identificado.
- **URLs:** https://grafana.com/docs/k6/latest/using-k6/scenarios/executors/constant-arrival-rate/ ; https://grafana.com/docs/k6/latest/using-k6/metrics/reference/ ; https://grafana.com/docs/k6/latest/set-up/install-k6/ ; https://github.com/grafana/k6 .
- **Data de acesso:** 24 out. 2026; executor, instalação e repositório oficial consultados em 29 out. 2026.
- **Fundamenta:** execução por taxa de chegada independente das respostas, disponibilidade de usuários virtuais e registro de iterações não iniciadas por `dropped_iterations`.
- **Citações acadêmicas:** não verificadas; documentação técnica.
- **Status:** utilizada na escolha e instalação do k6 OSS local e no exemplo `code/k6/`; não citada no TCC. Repositório oficial identifica licença AGPL-3.0; execução local gratuita, sem serviço Cloud obrigatório.

### Docker Compose e k6 — execução reproduzível

- **Títulos:** *Define services in Docker Compose*; *Running k6*.
- **Autores institucionais:** Docker; Grafana Labs.
- **Tipo:** documentação oficial, continuamente atualizada; ano de publicação e DOI não identificados.
- **URLs:** https://docs.docker.com/reference/compose-file/services/ ; https://grafana.com/docs/k6/latest/get-started/running-k6/ .
- **Data de acesso:** 29 out. 2026.
- **Fundamenta:** montagem do script no contêiner, execução local do k6 via Docker, configuração de serviços e resolução do host com `extra_hosts`.
- **Status:** utilizada em `code/k6/compose.yaml`; não citada no TCC.

### Locust — geração distribuída de carga

- **Título:** *Distributed load generation*.
- **Autor institucional e publicação:** projeto Locust; documentação oficial.
- **Ano:** não informado na página consultada; documentação continuamente atualizada. Verificar edição datada de 2021 ou posterior antes de usar como referência acadêmica.
- **DOI:** não identificado.
- **URL:** https://docs.locust.io/en/stable/running-distributed.html .
- **Data de acesso:** 24 out. 2026.
- **Fundamenta:** distribuição do gerador de carga entre processos e máquinas.
- **Citações acadêmicas:** não verificadas; documentação técnica.
- **Status:** consultada para avaliar alternativas; não citada no TCC.

### Apache JMeter — testes remotos

- **Título:** *User's Manual: Remote (Distributed) Testing*.
- **Autor institucional e publicação:** Apache Software Foundation; documentação oficial do Apache JMeter.
- **Ano:** não confirmado na página consultada. Verificar edição datada de 2021 ou posterior antes de usar como referência acadêmica.
- **DOI:** não identificado.
- **URL:** https://jmeter.apache.org/usermanual/remote-test.html .
- **Data de acesso:** 24 out. 2026.
- **Fundamenta:** execução de testes remotos/distribuídos.
- **Citações acadêmicas:** não verificadas; documentação técnica.
- **Status:** consultada para avaliar alternativas; não citada no TCC.

### Aleatoriedade e repetição — consulta metodológica

- **Fontes oficiais:** NetworkX Developers, *Randomness*, https://networkx.org/documentation/latest/reference/randomness.html ; NumPy Developers, *Random sampling*, https://numpy.org/doc/2.1/reference/random/ ; JMP, *Key Principles of Experimental Design*, https://www.jmp.com/en/statistics-knowledge-portal/design-of-experiments/key-design-of-experiments-concepts/key-principles-of-experimental-design .
- **Tipo:** documentação e material explicativo institucional. Ano de publicação não confirmado; DOI não identificado. Verificar critérios acadêmicos antes de eventual uso no artigo.
- **Acesso:** 24 out. 2026. Conteúdo NetworkX consultado; NumPy e JMP consultados pelos resumos de busca.
- **Conceitos:** sementes reproduzem sorteios, não necessariamente execuções concorrentes completas; repetição permite avaliar variação e não substitui controle das condições experimentais.
- **Status:** consulta de engenharia para revisão do simulador; não citadas no TCC. Não representa escolha de bibliotecas.

### Neo4j — transações

- **Título:** *Database transactions*.
- **Autor institucional e publicação:** Neo4j; documentação oficial, Operations Manual.
- **Ano:** não confirmado; verificar edição datada antes de eventual uso acadêmico.
- **DOI:** não identificado.
- **URL:** https://neo4j.com/docs/operations-manual/current/database-internals/transaction-management/ .
- **Data de acesso:** 29 out. 2026.
- **Fundamenta:** operações no grafo utilizam transações com propriedades ACID; consulta ao conteúdo retornado pela busca. Isso não demonstra superioridade de desempenho nem resolve a publicação confiável no broker.
- **Status:** consultada para discussão da autoridade dos dados; não citada no TCC.

### Twitter — armazenamento Manhattan

- **Título:** *Adopting RocksDB within Manhattan*.
- **Autor:** Vijay Teja Gottipati.
- **Ano e publicação:** 2021, 22 de abril; Twitter Engineering, fonte oficial.
- **DOI:** não identificado.
- **URL:** https://blog.x.com/en/engineering/adopting-rocksdb-within-manhattan .
- **Acesso:** 29 out. 2026; conteúdo consultado.
- **Fundamenta:** uso histórico de armazenamento distribuído chave-valor para tweets, usuários e mensagens; não descreve toda a arquitetura atual do X nem determina a escolha do TCC.
- **Status:** consulta de engenharia; não citada no TCC.

### Meta — armazenamento do grafo social

- **Título:** *Migrating Data Ingestion Systems at Meta Scale*.
- **Autores:** Zihao Tao, Mohan Perumal Swamy, Grace Gong, Ailyn Tong, Peishan Wang, Nilay Kapadia, Md Mustafijur Rahman Faysal, Saurav Sen e Jameel Mohamed.
- **Ano e publicação:** 2026, 12 de maio; Engineering at Meta, fonte oficial.
- **DOI:** não identificado.
- **URL:** https://engineering.fb.com/2026/05/12/data-infrastructure/migrating-data-ingestion-systems-at-meta-scale/ .
- **Acesso:** 29 out. 2026; conteúdo consultado.
- **Fundamenta:** o grafo social da Meta utiliza MySQL; modelar relações como grafo não obriga adotar um produto de banco de grafos.
- **Status:** consulta de engenharia; não citada no TCC.

### Laravel — configuração da API

- **Títulos:** *Routing*; *Error Handling* (documentação 13.x).
- **Autor institucional e publicação:** Laravel; documentação oficial, consultada via Context7.
- **Ano:** não confirmado; documentação continuamente atualizada.
- **DOI:** não identificado.
- **URLs:** https://github.com/laravel/docs/blob/13.x/routing.md ; https://github.com/laravel/docs/blob/13.x/errors.md .
- **Data de acesso:** 29 out. 2026.
- **Fundamenta:** registro de rotas stateless em `bootstrap/app.php`, prefixo `/api` e respostas de exceções em JSON. O comando `install:api` também instala Sanctum; o setup registra as rotas diretamente por não exigir autenticação.
- **Status:** utilizada em `code/laravel`; não citada no TCC.

### Docker, Hyperf, RabbitMQ e Neo4j — ambientes limitados

- **Títulos:** *Define services in Docker Compose*; *Installation* e *Overview* (Hyperf); *Memory Alarm Threshold*; *Modify the default configuration* (Neo4j).
- **Autores institucionais:** Docker, Hyperf, RabbitMQ e Neo4j, respectivamente; documentação oficial.
- **Ano:** não confirmado; documentação continuamente atualizada. DOI e citações acadêmicas não identificados.
- **URLs:** https://docs.docker.com/reference/compose-file/services/ ; https://github.com/hyperf/hyperf/blob/master/docs/en/quick-start/install.md ; https://github.com/hyperf/hyperf/blob/master/docs/en/quick-start/overview.md ; https://www.rabbitmq.com/docs/memory ; https://neo4j.com/docs/operations-manual/current/docker/configuration/ .
- **Data de acesso:** 29 out. 2026. Hyperf consultado também via Context7 e arquivos do esqueleto oficial na branch 3.1: https://github.com/hyperf/hyperf-skeleton/tree/3.1 .
- **Fundamenta:** limites por serviço, dependências com healthchecks, imagem de runtime Swoole, alarme absoluto de memória do RabbitMQ e conversão de configurações Neo4j em variáveis de ambiente. Os valores locais de CPU/RAM são escolhas iniciais de engenharia, sem validação metodológica.
- **Status:** utilizada na base `code/compose.yaml` e nos overrides de arquitetura; não citada no artigo. RabbitMQ e Neo4j foram apenas consultados, sem configuração mantida no ambiente. Imagens e digests conferidos no registro oficial Docker Hub.
