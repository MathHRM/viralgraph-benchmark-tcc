# laravel-sync — Arquitetura síncrona (TCC)

Backend síncrono do TCC "Teoria dos Grafos e Observabilidade: análise comparativa de
arquiteturas backend sob estresse de viralização" (PUC Minas — Betim).

Stack: **Laravel 12 + PHP 8.4 (PHP-FPM) + Nginx + MySQL 8**, tudo via Docker.

## Pré-requisitos

- Docker 24+ com Docker Compose v2
- Git

Não é necessário ter PHP nem Composer instalados na máquina.

## Setup (primeira vez)

```bash
cd backends/laravel-sync
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

A aplicação responde em <http://localhost:8000>.

Configure `BENCHMARK_REFERENCE_DATE` no `.env` com a data do manifesto da
execução (`AAAA-MM-DD`) antes de criar usuários. Sem essa data, a criação falha
com erro operacional `503`; a API não usa automaticamente a data corrente.

> O MySQL do projeto é exposto ao host na porta **3308** (a 3306 pode estar em uso
> por outros projetos). Dentro da rede docker, o host do banco é `mysql:3306`,
> já configurado no `.env.example` — nenhum ajuste manual é necessário.

## Uso no dia a dia

```bash
docker compose up -d                          # sobe a stack
docker compose down                           # para a stack
docker compose exec app php artisan <cmd>     # artisan (migrate, tinker, make:...)
docker compose exec app composer <cmd>        # composer
docker compose exec app php artisan test      # testes
docker compose logs -f app                    # logs do PHP-FPM
```

## API e camadas

As rotas do contrato estão em `routes/api.php`, sem prefixo `/api`. Controllers
recebem Form Requests e delegam a regra de negócio aos services; repositories
encapsulam as consultas Eloquent. O JSON público preserva nomes em português,
independentemente dos nomes ingleses do banco.

Os endpoints de usuários, relações, posts, compartilhamentos e métricas estão
implementados. A consulta paginada do feed retorna `501` temporariamente: o
contrato exige ordenar pela sequência lógica do evento da carga, mas os corpos
das requisições ainda não fornecem essa sequência. O endpoint de métricas
retorna contagens do estado atual; a auditoria pelo corte final depende da
definição desse corte no protocolo.

Para conectar um cliente SQL (TablePlus, DBeaver etc.) a partir do host:
`127.0.0.1:3308`, banco `laravel_sync`, usuário `laravel`, senha `secret`.

## Debugging com Xdebug

O Xdebug já está instalado, mas desabilitado por padrão (não impacta performance).
Para ativar, adicione ao seu `.env` local:

```ini
XDEBUG_MODE=debug
```

e reinicie o serviço: `docker compose up -d`. O Xdebug conecta em
`host.docker.internal` na porta 9003 (já funciona no Linux via `extra_hosts`).
No VS Code, use a extensão PHP Debug com `pathMappings`:
`/var/www/html → ${workspaceFolder}`.

## Permissões de arquivos (Linux)

A imagem é buildada com `UID`/`GID` 1000 por padrão. Se o seu usuário tiver outro
UID (`id -u`), exporte antes do build:

```bash
export UID GID
docker compose build app
```

## Estrutura

```text
.
├── Dockerfile               # PHP 8.4-FPM + extensões + Xdebug + Composer
├── docker-compose.yml       # serviços: app, nginx, mysql
├── docker/
│   ├── nginx/default.conf   # vhost -> public/
│   ├── php/local.ini        # memory_limit, uploads, OPcache
│   ├── php/xdebug.ini       # Xdebug (off por padrão)
│   └── mysql/my.cnf         # utf8mb4
└── (aplicação Laravel 12)
```

## Notas

- Sem frontend/Vite: aplicação exclusivamente backend (decisão do TCC).
- Redis, filas e observabilidade (Prometheus/Grafana) entram quando o escopo exigir.
