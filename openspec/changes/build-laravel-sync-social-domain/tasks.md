# Tasks

## 1. Fechar contrato antes da implementação

- [x] 1.1 Definir a identificação do ator e o contrato HTTP comum da comparação Laravel/Hyperf, registrar rotas, campos, respostas para duplicatas e validações, e conferir que o contrato atende a `specs/social-domain/spec.md`.
- [x] 1.2 Confirmar se a relação de origem de compartilhamento pode ser opcional para ações diretas da API, registrar a regra escolhida e verificar exemplos para compartilhamento direto e simulado.

## 2. Persistência do domínio

- [x] 2.1 Revisar as migrations e o modelo `User` existentes; criar migrations/modelos para follows, posts e shares com chaves estrangeiras e unicidades; verificar com migrations limpas no MySQL e confirmar que nenhuma coluna padrão incompatível permanece.
- [x] 2.2 Implementar username único sem distinção de caixa e validação de conteúdo de post; verificar criação, duplicatas e limite de 500 caracteres no MySQL.
- [x] 2.3 Implementar invariantes de seguir e compartilhar; verificar auto-seguimento, compartilhamento próprio, duplicatas e associações inexistentes por testes de feature.

## 3. API síncrona e validação integrada

- [x] 3.1 Implementar endpoints JSON modulares de usuários, follows, posts e shares conforme o contrato aprovado; verificar respostas de sucesso e erro e persistência antes da resposta.
- [x] 3.2 Verificar integridade do domínio com testes de feature e execução de `php artisan test --compact` no ambiente MySQL/Docker; confirmar que as operações não dependem de fila ou worker.
- [x] 3.3 Atualizar `code/laravel/README.md` com rotas, exemplos e comandos necessários; verificar que os comandos documentados correspondem à configuração atual.
