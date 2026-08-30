## Objetivo do projeto

Este repositório será utilizado no desenvolvimento do Trabalho de Conclusão de Curso (TCC) do 8º período de Sistemas de Informação da PUC Minas — Unidade Betim.

## Autores

- Gabriel Assunção Costa
- Matheus Henrique Resende Magalhães

## Tema atual

**Teoria dos Grafos, Alta Performance e Observabilidade: análise comparativa de arquiteturas sob estresse de viralização.**

O trabalho investiga como a topologia das redes sociais e as cascatas de informação associadas à viralização — especialmente aquelas iniciadas por nós com alta centralidade de grau — produzem crescimento simultâneo de tráfego e de relações. A investigação deverá combinar simulações de carga que reproduzam o chamado “efeito manada” com observabilidade de métricas de máquina, filas e rastreamento distribuído.

Serão comparados dois cenários backend submetidos ao mesmo estresse:

- um cenário tradicional, baseado em PHP-FPM, Laravel e MySQL, com fluxo síncrono e persistência transacional linha a linha;
- um cenário de alta performance, baseado em PHP Swoole/Hyperf, RabbitMQ e Neo4j, com processamento assíncrono, orientação a eventos e ingestão em lotes por workers.

A observabilidade deverá utilizar ferramentas como Prometheus, Grafana e OpenTelemetry ou APM para avaliar a degradação e a resiliência dos cenários. Os resultados, métricas e conclusões ainda não devem ser inventados ou apresentados como já obtidos.

Antes de alterar o escopo, o problema de pesquisa ou o objetivo principal, apresente a proposta e solicite confirmação.

## Estrutura do repositório

### Documento acadêmico

O diretório `modelo-de-artigo-icei-puc-minas/` contém o template oficial em LaTeX que deverá ser seguido.

Regras obrigatórias:

- Todo o TCC deve ser escrito em LaTeX.
- A estrutura e a formatação do template devem ser preservadas, respeitando também as orientações institucionais da PUC Minas e as normas da ABNT vigentes em 2026.
- Não alterar margens, tipografia, espaçamentos, cabeçalhos, organização ou outros padrões visuais sem solicitação explícita.
- Antes de criar ou modificar partes do documento, examine o template e reutilize seus comandos, ambientes e convenções.
- O conteúdo produzido deve manter linguagem acadêmica, clara, objetiva e impessoal.
- Não inventar resultados, experimentos, métricas, referências ou conclusões.

### Código-fonte

Todo o código desenvolvido para o projeto deverá ficar no diretório `code/`.

Decisões técnicas já definidas:

- Backend: Hyperf
- Servidor/runtime assíncrono: Swoole
- Frontend: não haverá
- O sistema deverá ser desenvolvido como uma aplicação exclusivamente backend.

Antes de adicionar bibliotecas, serviços, bancos de dados ou componentes de infraestrutura, justifique tecnicamente a escolha e verifique se ela está alinhada ao escopo do TCC.

## Pesquisa e referências

Não utilize apenas conhecimento interno ou dados de treinamento como base para afirmações que exijam fundamentação acadêmica.

Sempre pesquise fontes externas confiáveis antes de:

- inserir uma citação;
- recomendar uma referência;
- fundamentar uma afirmação acadêmica;
- apresentar números, resultados ou comparações;
- descrever o estado da arte;
- justificar escolhas metodológicas ou arquiteturais.

Priorize pesquisas nas seguintes bases:

1. Portal de Periódicos CAPES
2. IEEE Xplore
3. SciELO
4. Google Scholar

Também podem ser utilizadas fontes oficiais, livros acadêmicos, dissertações, teses e artigos de periódicos ou conferências reconhecidas, desde que sua qualidade seja verificada.

## Critérios para seleção das fontes

Antes de utilizar uma referência, verifique:

- se é um artigo científico, livro acadêmico, dissertação, tese ou fonte oficial;
- quem são os autores;
- onde o trabalho foi publicado;
- o ano de publicação;
- a existência de DOI ou outro identificador persistente;
- a quantidade de citações, quando essa informação estiver disponível;
- a relação direta entre a fonte e a afirmação que será fundamentada;
- a credibilidade do periódico, conferência, instituição ou editora.

Em assuntos relacionados à tecnologia:

- priorize trabalhos recentes;
- não descarte trabalhos clássicos quando forem fundamentais para o tema;
- diferencie conceitos consolidados de resultados recentes;
- procure fontes primárias em vez de textos que apenas resumem outros trabalhos.

Não cite uma fonte sem consultar seu conteúdo ou, no mínimo, seu resumo, metadados e informações de publicação. Não crie autores, títulos, datas, DOI ou dados bibliográficos.

## Registro das referências

Toda fonte consultada ou citada deverá ser registrada no arquivo `citations.md`.

Cada registro deve conter, quando disponível:

- título;
- autores;
- ano;
- tipo de publicação;
- periódico, conferência, instituição ou editora;
- DOI;
- URL;
- data de acesso;
- trecho, conceito ou afirmação que a fonte fundamenta;
- status indicando se a fonte apenas foi consultada ou se já foi citada no TCC.

Evite duplicar referências. Antes de adicionar uma nova entrada, confira se ela já existe em `citations.md`.

As referências inseridas no documento LaTeX devem corresponder às informações registradas em `citations.md`.

## Conduta ao modificar o projeto

Antes de realizar mudanças:

1. Leia os arquivos relacionados à tarefa.
2. Verifique as convenções já utilizadas no repositório.
3. Preserve mudanças existentes que não façam parte da tarefa.
4. Não altere o template acadêmico nem decisões estruturais sem necessidade.
5. Caso uma decisão possa mudar o escopo acadêmico ou técnico, solicite confirmação.

Depois de realizar mudanças:

1. Verifique se o LaTeX continua compilando, quando o documento for alterado.
2. Execute os testes e verificações disponíveis, quando o código for alterado.
3. Informe quais arquivos foram modificados.
4. Explique brevemente as decisões tomadas.
5. Indique qualquer limitação, dúvida ou validação ainda necessária.

## Normas acadêmicas e padrão ABNT

O TCC deve seguir:

1. o template oficial do ICEI/PUC Minas disponível em `modelo-de-artigo-icei-puc-minas/`;
2. as orientações institucionais da PUC Minas;
3. as normas da ABNT vigentes em 2026 aplicáveis a trabalhos acadêmicos.

Antes de aplicar ou recomendar uma regra da ABNT:

- consulte a versão vigente da norma em uma fonte oficial ou institucional confiável;
- não presuma que uma norma ou edição lembrada internamente continua atualizada;
- registre qual norma e edição fundamentam a decisão;
- verifique especialmente as regras de citações, referências, resumos, numeração progressiva, ilustrações, tabelas e apresentação de trabalhos acadêmicos.

Quando houver aparente conflito entre o template e uma regra geral da ABNT:

- não altere o template automaticamente;
- identifique e explique a divergência;
- priorize as determinações específicas da PUC Minas para a entrega institucional;
- solicite confirmação antes de realizar mudanças estruturais.

Todas as citações e referências devem:

- seguir as normas da ABNT vigentes em 2026;
- manter correspondência entre a citação presente no texto e a referência bibliográfica;
- apresentar dados bibliográficos completos e verificáveis;
- utilizar um padrão consistente em todo o documento;
- ser registradas também em `citations.md`.

Não invente regras, números ou edições de normas da ABNT. Caso não seja possível consultar uma norma necessária, informe a limitação antes de formatar o conteúdo.

## Idioma e terminologia

- Produza o texto acadêmico em português brasileiro, salvo solicitação diferente.
- Preserve nomes oficiais de tecnologias, trabalhos e conceitos estrangeiros.
- Defina termos técnicos na primeira vez em que forem relevantes.
- Utilize terminologia consistente ao longo do documento.
- Evite afirmações vagas, promocionais ou sem fundamentação.
