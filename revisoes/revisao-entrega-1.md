# Revisão do professor — TCC

## Visão geral da revisão

O retorno do professor foi positivo em relação à direção do trabalho, mas trouxe alguns ajustes de redação e uma preocupação importante com o tamanho da seção de Trabalhos Relacionados e, indiretamente, com a amplitude do escopo proposto.

A revisão deve ser tratada não apenas como uma lista de correções pontuais, mas como um conjunto de cuidados para as próximas versões do texto, evitando que os mesmos problemas reapareçam durante novas alterações.

---

## Pontos de melhoria

### Introdução

#### Clareza e precisão na redação

No trecho:

> “Redes sociais permitem que conteúdos se propaguem rapidamente entre usuários conectados...”

o professor indicou a retirada de **“conectados”**.

A ideia é evitar palavras que não acrescentam informação relevante à frase ou que tornam a redação mais carregada sem necessidade.

Na revisão da introdução, vale verificar se existem outros termos redundantes ou dispensáveis e priorizar construções mais diretas.

---

#### Precisão conceitual sobre disseminação

No trecho:

> “Quando uma publicação alcança usuários com grande capacidade de disseminação...”

o professor observou:

> “Usuários não são disseminados. Usuários produzem conteúdos que possuem capacidade de disseminação.”

O ponto principal é manter clara a distinção entre:

- o **usuário**, que publica ou compartilha;
- o **conteúdo**, que se dissemina;
- o **alcance ou posição do usuário na rede**, que pode contribuir para essa disseminação.

A redação deve evitar atribuir ao usuário uma propriedade que, naquele contexto, pertence ao conteúdo ou ao processo de propagação.

Exemplo de reformulação:

> “Quando uma publicação produzida ou compartilhada por usuários com grande alcance se dissemina pela rede...”

---

#### Pontuação da pergunta de pesquisa

No trecho:

> “A investigação é orientada pela seguinte pergunta. Como a topologia da rede...”

o professor pediu o uso de **dois-pontos**.

A construção deve ficar:

> “A investigação é orientada pela seguinte pergunta: Como a topologia da rede e a concentração de influência em nós com alto grau de saída afetam...?”

Esse padrão deve ser mantido sempre que uma frase introdutória apresentar diretamente uma pergunta, enumeração ou explicação subsequente.

---

## Trabalhos Relacionados

O principal ponto de melhoria indicado pelo professor está nessa seção.

Os comentários foram:

> “NÃO É NECESSÁRIO COLOCAR TANTOS CASOS. TRÊS OU QUATRO OK.”

e:

> “diminua a seção de trabalhos realizados, não é necessária tantas referências.”

Pelo contexto, “trabalhos realizados” provavelmente se refere a **Trabalhos Relacionados**.

A seção atual cobre muitos trabalhos individualmente. O professor sugere uma versão mais enxuta, concentrada em aproximadamente **três ou quatro referências principais**.

A revisão deve priorizar trabalhos que sustentem diretamente os principais eixos do TCC, por exemplo:

- difusão de informação e estrutura das cascatas;
- topologia de redes e influência de nós;
- comparação de arquiteturas backend;
- observabilidade, caso seja necessária para sustentar diretamente o método experimental.

O objetivo não deve ser mostrar a maior quantidade possível de referências, mas selecionar aquelas que realmente fundamentam a proposta e ajudam a posicionar o trabalho.

---

## Escopo do trabalho

O professor também registrou:

> “Está legal, com algumas observações que fiz. Espero que consiga cumprir os objetivos.”

Apesar de não ser uma solicitação explícita de redução de escopo, essa frase merece atenção.

O trabalho propõe combinar diversos elementos:

- teoria dos grafos;
- modelagem de cascatas;
- geração de carga;
- duas arquiteturas backend;
- processamento síncrono e assíncrono;
- diferentes tecnologias de persistência;
- filas;
- observabilidade;
- métricas de desempenho;
- análise de saturação, recuperação e drenagem de filas.

Nas próximas revisões, é importante verificar se todos esses elementos continuam diretamente ligados à pergunta de pesquisa e se permanecem executáveis dentro do tempo disponível.

Caso novos objetivos, métricas ou tecnologias sejam adicionados, deve-se revisar novamente o escopo para evitar crescimento não controlado do trabalho.

---

## Pontos de revisão para evitar regressão

### Manter a terminologia conceitualmente consistente

Ao revisar ou reescrever a introdução, verificar se não voltou a aparecer alguma construção que confunda:

- usuário;
- conteúdo;
- influência;
- alcance;
- disseminação;
- estrutura da cascata.

Especialmente, evitar novamente dizer que o usuário possui “capacidade de disseminação” quando a intenção for descrever o conteúdo ou sua propagação.

---

### Evitar voltar a expandir Trabalhos Relacionados

Durante novas pesquisas bibliográficas, não adicionar automaticamente cada referência encontrada à seção.

Antes de incluir um novo trabalho, verificar se ele:

- acrescenta uma contribuição realmente diferente;
- é diretamente necessário para fundamentar a pesquisa;
- substitui ou complementa algum dos trabalhos já selecionados;
- não torna a seção novamente extensa demais.

A orientação do professor deve permanecer como limite: aproximadamente **três ou quatro trabalhos centrais**.

---

### Não aumentar o escopo sem revisar os objetivos

Qualquer nova tecnologia, métrica, cenário ou experimento adicionado ao projeto deve ser confrontado com:

- a pergunta de pesquisa;
- o objetivo geral;
- os objetivos específicos;
- o prazo disponível para implementação e experimentação.

Se algo novo não for necessário para responder à pergunta de pesquisa, deve-se considerar deixá-lo fora do trabalho.

---

### Preservar a neutralidade da comparação

O texto atual evita pressupor que uma arquitetura será superior à outra. Esse cuidado deve continuar nas próximas versões.

A pesquisa deve comparar os comportamentos observados sob condições equivalentes, sem escrever a metodologia ou os resultados de forma a antecipar uma vantagem esperada para a arquitetura orientada a eventos ou para qualquer tecnologia específica.

---

### Diferenciar planejamento de resultado

Na parte de observabilidade, o texto deixa claro que as métricas descritas são **variáveis planejadas para os experimentos**, e não resultados já obtidos.

Esse cuidado deve ser mantido enquanto o experimento ainda não tiver sido executado.

Ao revisar metodologia, desenvolvimento e resultados, verificar se:

- metodologia descreve o que será feito;
- desenvolvimento descreve o que foi implementado;
- resultados apresentam apenas dados efetivamente obtidos;
- conclusões são baseadas nesses resultados.

---

## Direção para a próxima versão

A próxima versão deve manter a estrutura e a proposta geral do trabalho, mas com uma redação mais precisa e uma fundamentação bibliográfica mais enxuta.

A prioridade da revisão deve ser:

- melhorar clareza e precisão conceitual;
- reduzir Trabalhos Relacionados;
- evitar expansão desnecessária do escopo;
- preservar a coerência entre pergunta de pesquisa, objetivos, metodologia e experimentos;
- revisar futuras alterações com atenção para que os pontos já corrigidos não sejam reintroduzidos.
