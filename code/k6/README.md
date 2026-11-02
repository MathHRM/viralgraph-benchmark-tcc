# Teste de carga com k6

O Grafana k6 OSS é gratuito e executa localmente, sem conta no Grafana Cloud.
Foi escolhido por permitir controlar a taxa de chegada independentemente do
tempo de resposta e programar cenários de picos. Esta configuração inicial
valida somente carga HTTP constante; ainda não simula cascatas de viralização.

## Execução com Docker (recomendada)

Requisitos: Docker Engine com suporte a `host-gateway` (20.10 ou posterior)
ou Docker Desktop com contêineres Linux, e Docker Compose v2.
Não é necessário instalar k6 no host. A partir da raiz do repositório:

```bash
docker compose -f code/k6/compose.yaml run --rm k6
```

A imagem oficial fixa o k6 em v2.3.0 e identifica seu conteúdo por digest
SHA-256, evitando alterações silenciosas da tag. Na primeira execução, o Docker baixa
a imagem. O script é montado somente para leitura e o contêiner é removido
ao terminar. O resumo aparece no terminal; não há resultados persistidos
automaticamente. Para guardar o resumo, redirecione a saída para um arquivo
no host.

O destino padrão é `http://host.docker.internal:8000/api/health`.
Dentro do contêiner, `localhost` aponta para o próprio gerador.
Para acessar uma API no host Linux, ela precisa escutar em um endereço
acessível pela rede Docker, por exemplo `0.0.0.0`, com firewall adequado.
Uma porta publicada somente em `127.0.0.1` no host Linux pode não ser acessível
por esse caminho. Também é possível usar a URL de outro servidor acessível.

No Linux, para testar uma API que escuta somente em `127.0.0.1`, ou quando
o acesso pelo gateway estiver bloqueado, use a configuração com rede do host:

```bash
docker compose -f code/k6/compose.yaml -f code/k6/compose.host.yaml run --rm k6
```

Nesse modo, o destino padrão é `http://127.0.0.1:8000/api/health` e o gerador
compartilha a rede do host. Essa alternativa é destinada ao Docker Engine
no Linux; não é necessária para o fluxo padrão do Docker Desktop.

Para configurar a execução, use `-e` (mesmo formato em Bash, Zsh e PowerShell):

```bash
docker compose -f code/k6/compose.yaml run --rm -e URL=http://host.docker.internal:8000/api/health -e RPS=1000 -e DURATION=2m -e VUS=100 -e MAX_VUS=1000 k6
```

Para comparar as arquiteturas, repita o comando com os mesmos parâmetros,
alterando somente `URL`. Docker reproduz a ferramenta e sua configuração;
hardware, rede, dados e estado das aplicações ainda precisam ser controlados.

## Execução com instalação local (alternativa)

Com a API Laravel em execução, a partir da raiz do repositório:

```bash
k6 run code/k6/carga.js
```

O padrão é 500 iterações/s por 30 segundos, com um GET por iteração,
sem seguir redirecionamentos. O script envia `X-Tcc-Load-Test` para que o
middleware registre essas chamadas a `/api/health` e continue ignorando as
verificações normais de saúde. Para alterar o destino e a carga:

```bash
k6 run -e URL=http://127.0.0.1:8000/api/health -e RPS=1000 -e DURATION=1m code/k6/carga.js
```

`VUS` define os usuários virtuais pré-alocados (padrão: 100) e `MAX_VUS`
o máximo (500). Usuários virtuais representam execuções concorrentes.
A taxa efetiva depende dos recursos do gerador, da latência e do destino.
`dropped_iterations` indica iterações que não puderam ser iniciadas.
O comando falha se houver iterações descartadas, falhas HTTP ou status diferente de 200.
Esses critérios verificam a execução inicial; não definem o protocolo experimental do TCC.

Observe `http_reqs`, `http_req_failed`, `http_req_duration` (incluindo p95)
e `dropped_iterations` no resumo. O endpoint de saúde não mede persistência,
filas ou processamento de cascatas.

Para os experimentos, execute o gerador fora do contêiner limitado da aplicação;
prefira outra máquina para evitar disputa por CPU e memória.

## Reprodução nas duas arquiteturas

O gerador é externo e o script não depende de Laravel ou Hyperf. Use o mesmo
arquivo e os mesmos parâmetros, trocando somente `URL` entre as execuções.
Os endereços abaixo são exemplos (assumidos); ajuste às portas e rotas reais:

```bash
k6 run -e URL=http://127.0.0.1:8000/api/health -e RPS=500 -e DURATION=30s code/k6/carga.js
k6 run -e URL=http://127.0.0.1:9501/api/health -e RPS=500 -e DURATION=30s code/k6/carga.js
```

## Relatórios pelo dashboard

Ao iniciar um ensaio pelo dashboard, o controle local salva um relatório JSON
por arquitetura em `code/dashboard/reports/<execução>-<arquitetura>/`. O
relatório registra perfil solicitado, início e fim, amostras Prometheus de
cinco em cinco segundos, primeira falha observada e eventos de falha do k6.
Os arquivos `k6-events.json` e `output.log` preservam os dados brutos para
auditoria; o relatório inclui o SHA-256 de cada arquivo. A lista e os links
para os JSON ficam na aba **Execuções**.

O instante da primeira falha é o timestamp do primeiro evento k6 que registra
resposta HTTP com erro ou reprovação do check de status 200. Portanto, sua
precisão é a do evento do gerador; CPU, memória e métricas Prometheus têm
resolução de coleta de cinco segundos. Relatórios continuam no host após
reiniciar o dashboard e não são apagados automaticamente.

Neste checkout, somente Laravel é medido de verdade. Execuções do Hyperf ainda
geram um relatório marcado como `not_measured`, sem métricas nem horário de
falha, até a implementação do serviço assíncrono e sua integração ao gerador.

### Proposta operacional para os experimentos

Estas condições são uma proposta de engenharia, ainda não um protocolo
experimental validado:

- Manter a mesma versão do k6, script, taxa, duração e limites de usuários virtuais.
- Usar operações equivalentes nas duas APIs, com os mesmos dados de entrada.
- Restaurar o mesmo estado lógico inicial antes de cada execução, incluindo
  grafo, usuários e publicações; iniciar sem trabalho pendente nas filas.
- Para cascatas, preparar um conjunto fixo de eventos, com identificadores,
  relações, ordem e tempos relativos definidos. Reutilizar esse conjunto nos
  dois testes, sem gerar dados em função da velocidade das respostas.
- Registrar a carga realmente enviada e as iterações descartadas. A taxa
  configurada sozinha não comprova que ambas receberam o mesmo estímulo.
- Manter condições equivalentes de recursos e aquecimento, registrar as
  configurações e repetir as execuções alternando a ordem das arquiteturas.

No backend assíncrono, a resposta HTTP de aceitação e a conclusão da
persistência devem ser registradas separadamente. O teste completo deverá
acompanhar a conclusão dos mesmos eventos nas duas arquiteturas e o trabalho
que restar na fila após o envio. O cenário atual verifica apenas GET com
status 200; contrato de escrita, eventos e acompanhamento da conclusão ainda
precisam ser implementados.

## Instalação neste ambiente

Binário oficial k6 v2.3.0 instalado em `~/.local/bin/k6`, diretório já presente
no PATH, com SHA-256 conferido contra o arquivo de checksums da release.
A instalação persiste no usuário do host e não depende do contêiner da aplicação.
Em outra máquina, siga a [instalação oficial](https://grafana.com/docs/k6/latest/set-up/install-k6/).
