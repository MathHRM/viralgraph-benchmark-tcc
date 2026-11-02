# Proposal

## Why

O repositório já contém o esqueleto da composição Laravel síncrona, mas ainda não define nem implementa o domínio usado para comparar as arquiteturas do TCC. Esta mudança estabelece uma primeira API síncrona para usuários, seguidores, posts e compartilhamentos, criando uma base funcional que poderá ser ampliada em etapas posteriores.

## What Changes

- Definir operações de API e regras de domínio para usuários, relações direcionadas de seguir, posts textuais e compartilhamentos.
- Persistir o domínio em MySQL por meio da aplicação Laravel existente, com operações concluídas no fluxo síncrono da requisição e sem filas.
- Manter a aplicação exclusivamente backend e preservar a composição atual PHP-FPM/Nginx.
- Deixar consultas de notificações e envio simulado fora da primeira entrega, junto com o próprio registro de notificações.
- Deixar seeders de carga, curtidas, autenticação e geração/propagação de cascatas fora desta mudança; os dados de desenvolvimento necessários aos testes funcionais poderão ser definidos como parte da implementação.
- Registrar como decisões pendentes a propagação e persistência de notificações, estratégia de seeders, contrato HTTP e instrumentação de observabilidade.

## Capabilities

### New Capabilities
- `social-domain`: regras e operações síncronas para usuários, seguidores, posts e compartilhamentos.

### Modified Capabilities
- Nenhuma.

## Impact

- Área principal: `code/laravel/`, incluindo rotas, modelos, migrations, validação, respostas da API e testes.
- Persistência: MySQL já incluído na composição Docker; nenhuma dependência ou serviço adicional é proposto.
- Contrato externo: nova API JSON para as operações do domínio, cujas rotas e respostas serão especificadas no desenho.
- Fora do escopo desta mudança: componentes Hyperf, filas, notificações, envio real de e-mail, frontend, autenticação e protocolo experimental de carga.

## Questões para revisão

- Confirmar se notificações e seu envio simulado ficam para uma mudança posterior. A regra de negócio já documentada diz que a notificação só está concluída após persistência e registro do envio simulado, mas não define ainda os destinatários da publicação original nem falhas parciais para o caminho síncrono.
- Definir seeders: dados funcionais mínimos para desenvolvimento/testes ou também geração de cenários grandes para estresse.
- Definir como a API identifica o usuário que publica, segue ou compartilha enquanto autenticação está fora do escopo.
- Definir quais operações e formatos HTTP compõem o contrato comum com a futura implementação Hyperf.
