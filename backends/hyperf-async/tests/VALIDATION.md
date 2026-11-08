# Validação local — 8 nov. 2026

Esta verificação é de implementação, não uma execução experimental oficial nem resultado comparativo do TCC. Usa os limites iniciais **(assumed)** do Compose.

## Verificado

- Build da imagem com Composer lock, PHP 8.4.16, Swoole 6.2.2 e dependências Hyperf 3.2. `composer validate --strict`, `check-platform-reqs` e `audit` passaram; nenhum aviso de vulnerabilidade na consulta.
- Compose saudável com duas APIs, worker, Nginx, RabbitMQ e Neo4j. Em volumes novos, a API 1 inicializou as constraints antes da liberação da API 2 e do worker, sem disputa de schema. Seis consultas consecutivas ao Nginx alternaram as duas APIs, identificadas por `X-Instance`.
- `bin/check-budget.py`: limites efetivos de 1,50 CPU/3000 MiB e zero swap adicional, conferidos com `docker inspect`. A configuração com `API_CPUS=0.30` foi rejeitada por exceder 1,50 CPU.
- `tests/input.php`: calendário, aniversário em 29/02, maioridade, NFC, pontos de código e rejeição de cursores malformados.
- Suíte real de integração: sete testes passaram em 122,295 s com o bootstrap e Compose finais. Inclui todas as rotas, tipos/campos desconhecidos, limites, erros HTTP/JSON, leitura entre APIs, apelido NFC concorrente, relações e compartilhamentos concorrentes, buscas e feed em duas páginas, cascata, exclusão do autor, primeira entrega imutável, origem alternativa, ausência de histórico para novos seguidores, distribuição atrasada e fan-out de 101 seguidores em lotes de 100.
- Recuperação real: worker parado aceita post com 201 e leitura 404; commit sem ACK torna o recurso consultável pela outra API; reinício e replay não duplicam contagens. RabbitMQ parado produz 503 na criação de post; compartilhamento persiste sua outbox e alcança o seguidor após retomada do broker. Recriar o container RabbitMQ, com hostname fixo e o mesmo volume, preservou um post confirmado ainda pendente; após iniciar o worker, a API 2 retornou 200.
- Cinco testes existentes em `code/k6/tests/test_stress.py` passaram, incluindo k6 real contra a fixture do gerador. Os arquivos do gerador não foram alterados.
- Sintaxe PHP, Python e shell e leitura do OpenAPI YAML verificadas; `git diff --check` sem erros. Não existem `resources/views` ou `app/Livewire` neste checkout.

## Smoke do k6 existente

Executado contra o projeto separado `tcc-hyperf-smoke-final-20261108`, com volumes novos e os mesmos limites efetivos conferidos. Configuração curta: cenário `cascade`, seed 42, três usuários, data de referência 2026-11-08, escalas de taxa e tempo 0,02, três VUs pré-alocados, máximo 20, timeout 2000 ms, consulta de feed a cada 100 ms por até 3000 ms, setup 10000 ms, recuperação e graceful stop de um segundo, frações de post e compartilhamento 1 e buckets de um segundo.

O processo terminou com código 99 e relatório `invalid`: 13 criações de post responderam 201, e as dez consultas imediatas de post realizadas retornaram 404. Houve dez iterações descartadas e 13 ações sociais interrompidas. Os thresholds `checks: rate==1` e `dropped_iterations: count==0` falharam. O gerador e seu critério de consulta única após 1–30 ms foram preservados; este smoke não demonstra aprovação nesse critério nem capacidade do backend.

Artefatos locais, ignorados pelo Git: `tests/results/k6-smoke-final-20261108/`, incluindo configuração no manifesto, requisições, saída e `report.json`. O manifesto automático não incorpora a conferência externa de limites feita com `bin/check-budget.py`; tampouco mede recursos do gerador. O projeto de smoke foi encerrado preservando seus volumes, e o projeto padrão foi restaurado.

## Limites

A auditoria precisa integrar preservação exata do corte final e reconstrução de validade. `/metricas` fornece contagens observacionais persistidas, sem substituir essa auditoria. Não foram medidos consumo efetivo, capacidade máxima, desempenho comparativo ou resiliência experimental. Artigo e template LaTeX não foram alterados.
