# Trabalho de Conclusão de Curso

Repositório do Trabalho de Conclusão de Curso do 8º período de Sistemas de Informação da Pontifícia Universidade Católica de Minas Gerais (PUC Minas), Unidade Betim.

## Tema

**Teoria dos Grafos e Observabilidade: análise comparativa de arquiteturas backend sob estresse de viralização.**

O trabalho investiga como a topologia das redes sociais e as cascatas de informação associadas à viralização — especialmente aquelas iniciadas por nós com alta centralidade de grau — produzem crescimento simultâneo de tráfego e de relações. A análise será realizada por meio de simulações de carga que reproduzem o chamado “efeito manada” e pela observação de métricas de máquina, filas e rastreamento distribuído.

O estudo não busca isolar o efeito individual de cada tecnologia, mas comparar duas composições arquiteturais completas submetidas ao mesmo estímulo de carga. Serão comparadas duas arquiteturas backend submetidas ao mesmo estresse:

- uma arquitetura síncrona, baseada em PHP-FPM, Laravel e MySQL, com fluxo síncrono e persistência transacional linha a linha;
- uma arquitetura orientada a eventos, baseada em PHP Swoole/Hyperf, RabbitMQ e Neo4j, com processamento assíncrono e ingestão em lotes por workers.

A observabilidade, apoiada por ferramentas como Prometheus, Grafana e OpenTelemetry ou APM, será utilizada para avaliar a degradação e a resiliência das arquiteturas. Os resultados experimentais ainda não foram produzidos e não devem ser antecipados neste repositório.

## Autores

- Gabriel Assunção Costa
- Matheus Henrique Resende Magalhães

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
4. grava o PDF na raiz com o nome `artigo-vN.pdf`, incrementando `N` quando já existir uma versão;
5. remove a versão imediatamente anterior somente depois que a nova compilação for concluída.

Também é possível informar a pasta do documento e o diretório de saída:

```bash
./scripts/render-latex.sh caminho/para/o/latex caminho/para/saida
```

Para definir outro nome-base para o arquivo gerado, use a variável `DOCUMENT_NAME`:

```bash
DOCUMENT_NAME=tcc ./scripts/render-latex.sh
```

## Desenvolvimento da aplicação

O diretório `code/` está reservado para a aplicação Hyperf. Os comandos de instalação, execução e teste serão documentados aqui quando a implementação do backend for adicionada.

## Referências

As fontes consultadas e utilizadas no trabalho devem ser registradas em [`citations.md`](citations.md), com os dados bibliográficos disponíveis e a indicação de como cada fonte fundamenta o texto.

## Estado atual

O repositório contém a estrutura inicial do artigo, baseada no template do Instituto de Ciências Exatas e de Informática da PUC Minas, além do mecanismo Docker para compilação. O conteúdo acadêmico e o backend ainda estão em desenvolvimento.
