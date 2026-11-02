# Proposal

## Why

O frontend Vue precisa validar se as operações centrais da rede social são utilizáveis no dia a dia e deverá poder apontar para as implementações Laravel síncrona ou Hyperf assíncrona sem ser recriado. Hoje o contrato cobre apenas algumas escritas, usa IDs de usuário enviados pelo cliente e não oferece autenticação nem feed.

## What Changes

- Definir um contrato HTTP comum para cadastro, login, sessão, descoberta de usuários, seguir/deixar de seguir, publicar, compartilhar e consultar o feed.
- Usar a sessão autenticada para identificar o usuário em operações protegidas, sem receber `user_id` como identidade declarada pelo cliente.
- Definir envelopes comuns de dados e erros, paginação e estados de escrita que permitam à interface informar sucesso imediato ou processamento pendente.
- Preservar as regras de domínio já definidas para usernames, publicações, compartilhamentos e relações direcionadas.

## Capabilities

### New Capabilities
- `shared-social-api`: contrato HTTP independente de framework para uso pelo frontend Vue nas implementações síncrona e assíncrona.

### Modified Capabilities
- Nenhuma. A especificação social-domain existente descreve regras de domínio do backend síncrono; este change adiciona a superfície de API e os fluxos de usuário compartilhados.

## Impact

- APIs em `code/laravel` e `code/async`, com rotas, autenticação e serialização compatíveis.
- Futuro frontend Vue, configurável para usar a URL-base de qualquer uma das APIs.
- Implementações precisarão expor resultados equivalentes para as mesmas operações, ainda que o tempo de conclusão e o estado de uma escrita variem conforme a arquitetura.
