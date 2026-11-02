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

`GET http://127.0.0.1:8000/api/health` retorna `{"status":"ok"}`. Essa rota verifica a resposta da aplicação, sem consultar o banco. As rotas ficam em `routes/api.php`, com prefixo `/api`; respostas de erro usam JSON.

## API do domínio social

As rotas de escrita aceitam JSON. O backend usa sessão para autenticar; os endpoints protegidos obtêm o ator da sessão, sem aceitar `user_id` ou `follower_id` como identidade do usuário atual.

O código está organizado como monólito modular em `app/Modules`. Services aplicam regras e retornam dados ou erros de domínio; controllers e o renderizador global convertem esses resultados em HTTP. Cadastro, sessão, busca, follows, feed, posts, shares e operações seguem o contrato compartilhado descrito em `openspec/changes/define-shared-social-api/specs/shared-social-api/spec.md`.

| Método e rota | Acesso | Resultado |
| --- | --- | --- |
| `GET /api/csrf` | Público | Token CSRF para as próximas requisições de escrita |
| `POST /api/users` | Público | Cadastro com `username` e `password`; 201 com identidade pública |
| `POST /api/sessions` | Público | Login com `username` e `password` |
| `GET /api/session`, `DELETE /api/session` | Sessão | Lê a identidade ou encerra a sessão |
| `GET /api/users?search=ana` | Sessão | Busca usuários públicos com `limit` e `cursor` |
| `POST /api/follows` | Sessão | Cria follow usando `followed_id` |
| `DELETE /api/follows/{user_id}` | Sessão | Remove follow |
| `GET /api/feed` | Sessão | Feed do próprio usuário e de quem segue, com `limit` e `cursor` |
| `POST /api/posts` | Sessão | Publica `content` de 1 a 500 caracteres |
| `POST /api/shares` | Sessão | Compartilha publicação alheia usando `post_id` e `origin_share_id` opcional |
| `GET /api/operations/{operation_id}` | Sessão | Consulta resultado persistido de uma escrita |

Usuários têm nome entre 3 e 30 caracteres ASCII (letras, números e `_`) e senha de 8 a 128 caracteres. Username é único sem distinção de caixa. Escritas retornam HTTP 201 com operação `completed` depois da persistência. Erros têm envelope `{ "error": { "code", "message", "details?" } }`.

Para clientes de navegador, envie cookies (`credentials: include`) e obtenha o token antes de cada fluxo de escrita. Após login, a sessão é renovada; busque um token atualizado antes da próxima escrita. O frontend já implementa esse fluxo.

Exemplo de chamada:

```bash
curl -i -c cookies.txt http://127.0.0.1:8000/api/csrf
```

O token retornado em `data.token` deve ser enviado no cabeçalho `X-CSRF-TOKEN` junto ao cookie de sessão recebido. A API assíncrona ainda precisa implementar esse mesmo contrato.

## Banco de dados

Configure `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` e `DB_PASSWORD` no `.env` para um MySQL existente. Depois execute:

```bash
php artisan migrate
```

O setup local não cria servidor ou banco MySQL; o ambiente Docker descrito abaixo cria ambos. As migrations criam usuários, relações de seguimento, publicações e compartilhamentos. Em bancos já utilizados, a migration de username preserva usuários existentes e preenche nomes no formato `user-{id}`; faça backup antes de aplicar migrations em outros ambientes.

Jobs usam execução síncrona (`QUEUE_CONNECTION=sync`) e sessão/cache usam arquivos no ambiente Docker de desenvolvimento. Laravel Boost está instalado apenas para desenvolvimento, conforme as instruções do esqueleto.

## Testes

```bash
composer test
```

Os testes verificam a rota de saúde, ausência de cookie de sessão, respostas JSON para rotas inexistentes e as regras do domínio social. O ambiente de testes local usa SQLite em memória. O servidor Artisan é destinado ao desenvolvimento. O ambiente Docker usa PHP-FPM com quatro processos fixos e Nginx.

## Executar no Docker

Na raiz do repositório:

```bash
docker compose -f code/compose.yaml -f code/laravel/compose.override.yaml up -d --build --wait
docker compose -f code/compose.yaml -f code/laravel/compose.override.yaml exec app php artisan migrate --force
curl --fail http://127.0.0.1:8000/api/health
curl --fail http://127.0.0.1:8000/api/metrics
curl --fail 'http://127.0.0.1:9090/api/v1/query?query=up%7Bjob%3D%22sync%22%7D'
docker compose -f code/compose.yaml -f code/laravel/compose.override.yaml exec app php artisan test --compact
```

Não é necessário instalar PHP ou Composer no host nem copiar o `.env`. O build instala as dependências do lock, incluindo as de desenvolvimento para executar os testes. As configurações de conexão vêm do Compose. O código fica na imagem; alterações exigem reconstrução. Dados do MySQL persistem no volume `mysql-data`; `down` preserva esse volume e `down --volumes` apaga seus dados.

O serviço Prometheus consulta as métricas HTTP a cada 5 segundos e mantém 7 dias no volume `prometheus-data`. O dashboard consulta sua API no proxy local do Vite. Para abri-lo, execute `cd code/dashboard && npm install && npm run dev` e acesse o endereço mostrado pelo Vite. O endpoint `/api/metrics` não tem autenticação e deve permanecer restrito ao ambiente local de experimento. Ele contabiliza requisições da aplicação, exceto a própria coleta e as verificações normais de saúde. O k6 marca suas chamadas de carga com `X-Tcc-Load-Test`, permitindo medir `/api/health` sem incluir as verificações automáticas. Fora da imagem Docker, sem a extensão APCu, retorna HTTP 503 e não registra métricas.

Veja os limites por serviço, variáveis, credenciais locais e procedimentos de repetição no [README principal](../../README.md#ambientes-docker-limitados).
