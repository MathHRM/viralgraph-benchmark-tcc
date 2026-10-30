# Trabalho de Conclusão de Curso

Repositório do Trabalho de Conclusão de Curso do 8º período de Sistemas de Informação da Pontifícia Universidade Católica de Minas Gerais (PUC Minas), Unidade Betim.

## Tema

**Teoria dos Grafos e Observabilidade: análise comparativa de arquiteturas backend sob estresse de viralização.**

O trabalho investiga como a topologia das redes sociais e as cascatas de informação associadas à viralização — especialmente aquelas iniciadas por nós com alta centralidade de grau — produzem crescimento simultâneo de tráfego e de relações. A análise será realizada por meio de simulações de cascatas que produzam picos de compartilhamento e de carga associados à viralização e pela observação de métricas de máquina, filas e rastreamento distribuído.

O estudo não busca isolar o efeito individual de cada tecnologia, mas comparar duas composições arquiteturais completas submetidas ao mesmo estímulo de carga. Serão comparadas duas arquiteturas backend submetidas ao mesmo estresse:

- uma arquitetura síncrona, baseada em PHP-FPM, Laravel e MySQL, com fluxo síncrono e persistência transacional linha a linha;
- uma arquitetura orientada a eventos, baseada em PHP Swoole/Hyperf, RabbitMQ e Neo4j, com processamento assíncrono e ingestão em lotes por workers.

A observabilidade, apoiada por ferramentas como Prometheus, Grafana e OpenTelemetry ou APM, será utilizada para avaliar a degradação e a resiliência das arquiteturas. Os resultados experimentais ainda não foram produzidos e não devem ser antecipados neste repositório.

## Autores

- Gabriel Assunção Costa
- Matheus Henrique Resende Magalhães

## Orientador

- Bernardo Jeunon de Alencar

## Organização do repositório

```text
.
├── artigo/                              # Documento acadêmico em LaTeX
├── code/                                # Código-fonte da aplicação backend
├── modelo-de-artigo-icei-puc-minas/    # Template institucional do artigo
├── citations.md                         # Registro das fontes consultadas e citadas
├── Dockerfile                           # Imagem usada para compilar o LaTeX
└── scripts/
    └── render-latex.sh                  # Script de compilação e geração do PDF
```

## Decisões técnicas

- Backend: Hyperf
- Runtime assíncrono: Swoole
- Interface: não haverá frontend
- Tipo de aplicação: exclusivamente backend

O código da aplicação deverá ser mantido em `code/`. A definição detalhada da infraestrutura e de eventuais dependências ainda faz parte do desenvolvimento do projeto.

## Requisitos

- Docker;
- Bash;
- Git.

O script utiliza a imagem `texlive/texlive:latest` para compilar o artigo. Na primeira execução, a imagem local `tcc-latex:local` é construída automaticamente.

## Compilar o artigo

Na raiz do repositório, execute:

```bash
./scripts/render-latex.sh
```

Por padrão, o script:

1. utiliza a pasta `artigo/` como fonte;
2. procura o arquivo `principal.tex`;
3. compila o documento com `latexmk` dentro do Docker;
4. grava o primeiro PDF como `artigo-v1.0.pdf`;
5. incrementa o patch a cada nova renderização, por exemplo, de `artigo-v1.0.pdf` para `artigo-v1.1.pdf`;
6. remove a versão anterior somente depois que a nova compilação for concluída.

Para incrementar a versão principal e reiniciar o patch, use `-v` ou `--version`:

```bash
./scripts/render-latex.sh --version
```

Por exemplo, se o PDF atual for `artigo-v1.3.pdf`, o novo arquivo será `artigo-v2.0.pdf`. Arquivos no formato antigo, como `artigo-v7.pdf`, são interpretados como `v7.0`.

Também é possível informar a pasta do documento e o diretório de saída:

```bash
./scripts/render-latex.sh caminho/para/o/latex caminho/para/saida
```

Para definir outro nome-base para o arquivo gerado, use a variável `DOCUMENT_NAME`:

```bash
DOCUMENT_NAME=tcc ./scripts/render-latex.sh
```

## Desenvolvimento da aplicação

A API síncrona Laravel está em [`code/laravel/`](code/laravel/README.md), com instruções de instalação, execução e testes. O setup é exclusivamente backend, sem frontend. A base Docker da arquitetura assíncrona está em `code/async/`, sem aplicação ou dependências PHP instaladas.

## Ambientes Docker limitados

Com Make instalado, execute na raiz do repositório:

```bash
make laravel                          # Sobe o Laravel e seus serviços
make async                            # Sobe somente o ambiente PHP/Swoole
make laravel CMD="down"                # Para o Laravel, preservando os dados
make async CMD="down"                  # Para o ambiente async
make async CMD="exec app sh"           # Abre um shell no ambiente async
make laravel CMD="exec app php artisan migrate --force"
```

Os alvos combinam a base e o override correspondente. `CMD` permite passar outros comandos ao Compose; por padrão, executam `up -d --build --wait`. Variáveis como `APP_CPUS` e `APP_MEMORY` também podem ser informadas antes de `make`. Os comandos Docker equivalentes estão abaixo.

`code/compose.yaml` define os limites comuns do serviço `app`. Cada arquitetura tem um override: `code/laravel/compose.override.yaml` e `code/async/compose.override.yaml`. Use sempre o arquivo base primeiro, para que os caminhos de build e montagem sejam resolvidos a partir de `code/`.

Na raiz do repositório, com Docker Engine e Compose v2:

```bash
# Laravel + PHP-FPM + Nginx + MySQL
docker compose -f code/compose.yaml -f code/laravel/compose.override.yaml up -d --build --wait
docker compose -f code/compose.yaml -f code/laravel/compose.override.yaml exec app php artisan migrate --force
curl --fail http://127.0.0.1:8000/api/health

# Somente o ambiente PHP/Swoole, sem aplicação ou serviços adicionais
docker compose -f code/compose.yaml -f code/async/compose.override.yaml up -d --wait
docker compose -f code/compose.yaml -f code/async/compose.override.yaml exec app sh
```

O override async seleciona apenas a imagem de runtime e mantém um processo ocioso. Não instala projeto Hyperf, não inicia servidor HTTP e não publica portas. Não configura RabbitMQ, Neo4j ou integrações. O Composer já vem na imagem, mas não é executado pelo Compose.

Limites iniciais **(assumidos, sem calibração experimental)**:

| Serviço | CPUs | RAM |
| --- | --- | --- |
| `app`, comum às duas arquiteturas | 1 | 512 MiB |
| Nginx, somente Laravel | 0,25 | 128 MiB |
| MySQL, somente Laravel | 2 | 2048 MiB |

Os limites são por contêiner, sem swap adicional; não limitam disco, construção das imagens ou o conjunto da arquitetura. Portanto, os tetos totais das duas composições são diferentes e não estabelecem condições equivalentes para comparação experimental. Sobrescreva `APP_CPUS` e `APP_MEMORY` no ambiente quando necessário:

```bash
APP_CPUS=0.5 APP_MEMORY=768m docker compose -f code/compose.yaml -f code/async/compose.override.yaml up -d --wait
```

No Laravel, `WEB_CPUS`, `WEB_MEMORY`, `MYSQL_CPUS`, `MYSQL_MEMORY` e `HTTP_PORT` ajustam os demais limites e a porta HTTP. PHP-FPM usa quatro processos fixos, com limite PHP de 128 MiB por processo; a memória total do contêiner inclui também outros custos e deve ser calibrada para a carga.

As imagens estão fixadas por digest e as dependências Laravel por `composer.lock`. O build Laravel usa pacotes Alpine que podem mudar, portanto não garante reconstrução byte a byte. Para repetir um ensaio, preserve também as imagens construídas, o commit, as variáveis e o estado inicial dos dados.

O Laravel copia o código para a imagem; alterações exigem `up -d --build --wait` com os dois arquivos. O `.env` local não é copiado nem sobrescrito. As credenciais padrão são públicas e destinadas ao desenvolvimento local; podem ser alteradas por `DOCKER_APP_KEY`, `DOCKER_DB_DATABASE`, `DOCKER_DB_USERNAME`, `DOCKER_DB_PASSWORD` e `DOCKER_DB_ROOT_PASSWORD`. Somente o HTTP Laravel é publicado, em `127.0.0.1`.

O MySQL mantém dados no volume `mysql-data`. Alterar as variáveis não troca credenciais de um banco já inicializado. Para parar preservando os dados:

```bash
docker compose -f code/compose.yaml -f code/laravel/compose.override.yaml down
docker compose -f code/compose.yaml -f code/async/compose.override.yaml down
```

Adicionar `--volumes` ao `down` do Laravel apaga definitivamente os dados do MySQL; as migrations deverão ser executadas novamente. O ambiente async não cria volumes nem monta código da aplicação.

Validação e inspeção:

```bash
docker compose -f code/compose.yaml -f code/laravel/compose.override.yaml config --quiet
docker compose -f code/compose.yaml -f code/async/compose.override.yaml config --quiet
docker compose -f code/compose.yaml -f code/laravel/compose.override.yaml exec app php artisan test --compact
docker compose -f code/compose.yaml -f code/async/compose.override.yaml exec app sh -c 'cat /sys/fs/cgroup/cpu.max /sys/fs/cgroup/memory.max /sys/fs/cgroup/memory.swap.max'
```

Em Linux com cgroups v2, o último comando deve mostrar `100000 100000`, `536870912` e `0` nos valores padrão. O Compose base depende de um override para fornecer a imagem ou o build. A aplicação async, a observabilidade e os testes de carga ainda não estão implementados.

## Referências

As fontes consultadas e utilizadas no trabalho devem ser registradas em [`citations.md`](citations.md), com os dados bibliográficos disponíveis e a indicação de como cada fonte fundamenta o texto.

## Estado atual

O repositório contém a estrutura inicial do artigo, baseada no template do Instituto de Ciências Exatas e de Informática da PUC Minas, além do mecanismo Docker para compilação. O conteúdo acadêmico e o backend ainda estão em desenvolvimento.
