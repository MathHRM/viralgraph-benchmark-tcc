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

O setup não cria servidor ou banco MySQL. As migrations, o modelo e a factory de usuário são os padrões do Laravel; não representam o domínio do experimento. A conexão com MySQL e as migrations ainda precisam de validação no ambiente escolhido.

Jobs usam execução síncrona (`QUEUE_CONNECTION=sync`) e o cache usa arquivos. Não há autenticação configurada. Laravel Boost está instalado apenas para desenvolvimento, conforme as instruções do esqueleto.

## Testes

```bash
composer test
```

Os testes verificam a rota de saúde, ausência de cookie de sessão, respostas JSON para rotas inexistentes e ausência de página inicial. O ambiente de testes usa SQLite em memória. O servidor Artisan é destinado ao desenvolvimento; PHP-FPM para os ensaios ainda não foi configurado.
