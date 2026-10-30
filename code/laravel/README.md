# Laravel — API síncrona

Aplicação Laravel 13, somente API, para a composição síncrona do TCC. Requer PHP 8.3 ou superior, Composer e a extensão PDO MySQL. Não utiliza Node.js ou frontend.

## Executar localmente

Na raiz do repositório:

```bash
cd code/laravel
composer install
cp .env.example .env
php artisan key:generate
php artisan serve
```

Não sobrescreva um `.env` já configurado. O setup inicial já gera esse arquivo localmente; ele não é versionado.

`GET http://127.0.0.1:8000/api/health` retorna `{"status":"ok"}`. Essa rota verifica a resposta da aplicação, sem consultar o banco. As rotas ficam em `routes/api.php`, com prefixo `/api`, sem sessão e com erros em JSON.

## Banco de dados

Configure `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` e `DB_PASSWORD` no `.env` para um MySQL existente. Depois execute:

```bash
php artisan migrate
```

O setup local não cria servidor ou banco MySQL; o ambiente Docker descrito abaixo cria ambos. As migrations, o modelo e a factory de usuário são os padrões do Laravel; não representam o domínio do experimento. As migrations podem ser executadas no MySQL do Docker.

Jobs usam execução síncrona (`QUEUE_CONNECTION=sync`) e o cache usa arquivos. Não há autenticação configurada. Laravel Boost está instalado apenas para desenvolvimento, conforme as instruções do esqueleto.

## Testes

```bash
composer test
```

Os testes verificam a rota de saúde, ausência de cookie de sessão, respostas JSON para rotas inexistentes e ausência de página inicial. O ambiente de testes usa SQLite em memória. O servidor Artisan é destinado ao desenvolvimento. O ambiente Docker usa PHP-FPM com quatro processos fixos e Nginx.

## Executar no Docker

Na raiz do repositório:

```bash
docker compose -f code/compose.yaml -f code/laravel/compose.override.yaml up -d --build --wait
docker compose -f code/compose.yaml -f code/laravel/compose.override.yaml exec app php artisan migrate --force
curl --fail http://127.0.0.1:8000/api/health
docker compose -f code/compose.yaml -f code/laravel/compose.override.yaml exec app php artisan test --compact
```

Não é necessário instalar PHP ou Composer no host nem copiar o `.env`. O build instala as dependências do lock, incluindo as de desenvolvimento para executar os testes. As configurações de conexão vêm do Compose. O código fica na imagem; alterações exigem reconstrução. Dados do MySQL persistem no volume `mysql-data`; `down` preserva esse volume e `down --volumes` apaga seus dados.

Veja os limites por serviço, variáveis, credenciais locais e procedimentos de repetição no [README principal](../../README.md#ambientes-docker-limitados).
