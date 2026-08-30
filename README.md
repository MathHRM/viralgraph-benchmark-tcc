# Trabalho de Conclusão de Curso

Repositório do Trabalho de Conclusão de Curso do 8º período de Sistemas de Informação da Pontifícia Universidade Católica de Minas Gerais (PUC Minas), Unidade Betim.

## Tema

O projeto estuda o comportamento das conexões humanas em redes sociais por meio da Teoria dos Grafos, com apoio de uma arquitetura backend de alta performance para ingestão e processamento de dados relacionais em larga escala.

O tema ainda está em desenvolvimento e poderá ser refinado durante a elaboração do trabalho.

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
└── render-latex.sh                      # Script de compilação e geração do PDF
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
./render-latex.sh
```

Por padrão, o script:

1. utiliza a pasta `artigo/` como fonte;
2. procura o arquivo `principal.tex`;
3. compila o documento com `latexmk` dentro do Docker;
4. grava o PDF na raiz com o nome `artigo-vN.pdf`, incrementando `N` quando já existir uma versão.

Também é possível informar a pasta do documento e o diretório de saída:

```bash
./render-latex.sh caminho/para/o/latex caminho/para/saida
```

Para definir outro nome-base para o arquivo gerado, use a variável `DOCUMENT_NAME`:

```bash
DOCUMENT_NAME=tcc ./render-latex.sh
```

## Desenvolvimento da aplicação

O diretório `code/` está reservado para a aplicação Hyperf. Os comandos de instalação, execução e teste serão documentados aqui quando a implementação do backend for adicionada.

## Referências

As fontes consultadas e utilizadas no trabalho devem ser registradas em [`citations.md`](citations.md), com os dados bibliográficos disponíveis e a indicação de como cada fonte fundamenta o texto.

## Estado atual

O repositório contém a estrutura inicial do artigo, baseada no template do Instituto de Ciências Exatas e de Informática da PUC Minas, além do mecanismo Docker para compilação. O conteúdo acadêmico e o backend ainda estão em desenvolvimento.

