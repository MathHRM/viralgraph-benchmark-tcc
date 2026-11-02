# Design

## Context

See `proposal.md` for motivation and `specs/shared-social-api/spec.md` for observable requirements. The existing Laravel API has `POST /api/users`, `/api/follows`, `/api/posts`, and `/api/shares`; actor IDs are supplied in requests, and there are no session, account-password, feed, or read endpoints. The existing social-domain change defines username uniqueness, directed follows, immutable text posts, and shares referencing an original post.

## Goals / Non-Goals

**Goals:**

- Give the future Vue application one stable contract for both backend implementations.
- Make account identity, feed, follows, posts, shares, errors, and pending writes observable in a predictable way.
- Preserve existing domain rules while replacing request-supplied actor IDs with authenticated identity for user-facing operations.

**Non-Goals:**

- Implementing either backend or the Vue interface in this change.
- Defining notification fanout, viral cascade processing, ranking, likes, comments, post editing/deletion, or media uploads.
- Prescribing internal persistence, queue, graph traversal, or session storage technology.

## Decisions

### Framework-neutral REST resources

Use `/api` resources with JSON envelopes and stable machine-readable error codes. Keep the current route prefix and avoid a version segment for this initial contract, since the current API already uses `/api` and no external clients depend on a released version. Both implementations must map their internal models to the same public resource fields. An endpoint inventory and representative request/response examples should be maintained as part of implementation.

### Browser session cookie for authentication

Use a server-managed session cookie shared in behavior by both APIs. Keep the cookie HttpOnly, apply appropriate Secure and SameSite settings for the deployment, and protect state-changing browser requests against CSRF. `GET /api/csrf` returns a short-lived CSRF token in the common data envelope; the browser sends it in `X-CSRF-Token` on state-changing requests. A frontend development proxy or deployment routing can keep API requests under the configured API origin. This avoids exposing long-lived bearer credentials to browser JavaScript and matches the first-party browser client use case. Bearer tokens were considered but would introduce token storage and revocation behavior the Vue client does not need.

### Session identity replaces caller-supplied actor IDs

Registration accepts `{username, password}`; username is 3–30 ASCII letters, digits, or underscores, and password is 8–128 characters. Login accepts the same fields at `POST /api/sessions`. `GET /api/session` returns the current public identity and `DELETE /api/session` ends it. The current `POST /api/users` behavior that creates username-only experiment records cannot remain the authenticated registration contract; implementation must migrate/adapt it while preserving domain test fixtures where needed.

### Feed includes self and followed accounts

Expose `GET /api/feed` as a reverse-chronological, cursor-paginated stream. Each entry has `{id, kind, created_at, actor, post}`, where actor is the user who published or shared, and post contains the immutable original content and author. Sort by `created_at` descending and `id` descending to break ties. Include the session user's own activity as well as activity of accounts they follow. Cursor pagination is preferred to offset pagination because new writes can arrive while the user reads pages.

### Explicit completion state for writes

Keep ordinary successful synchronous writes as completed responses containing the resource. Allow async writes to return HTTP `202 Accepted` with a pending operation identifier and status URL; polling returns the same operation envelope in pending, completed, or failed state. Errors use a common shape `{error:{code,message,details?}}`. The interface can show a pending message only when the API reports pending, and show confirmation once the operation is completed.

The completion boundary for this frontend contract is persistence of the requested post or share. Downstream notification fanout and a full viral cascade remain separate work and are not implied to be complete by the post response.

### Shared verification across implementations

Create framework-neutral API examples or fixtures and run equivalent contract checks against both backends during implementation. Backend-specific tests can supplement these checks, but a frontend must not need framework-specific branches for resource interpretation. Do not require identical latency or identical HTTP success status for completed versus accepted asynchronous writes.

## Risks / Trade-offs

- [Risk] Cookie sessions require coordinated cookie, CSRF, and origin settings across deployments → [Mitigation] document a common bootstrap and local proxy configuration before implementing the browser client.
- [Risk] Cursor ordering can be unstable if entries share timestamps → [Mitigation] define a deterministic tie-breaker using the entry identifier in the endpoint examples and implementation.
- [Risk] Existing experimental scripts may rely on explicit actor IDs → [Mitigation] preserve a separate controlled load-generation path if needed; do not weaken authenticated user-facing routes.
- [Risk] Async completion may refer to persistence while viral processing continues → [Mitigation] name and document operation completion narrowly as the requested post/share persistence result.

## Migration Plan

1. Agree on the remaining wire details (session cookie/CSRF bootstrap, username/password validation limits, resource identifiers, and exact status payload fields) before implementing endpoints.
2. Implement the shared contract in Laravel and Hyperf, retaining backend-specific internals while making externally visible behavior equivalent.
3. Validate shared request/response examples against both implementations and then build the Vue client against the contract.
4. Roll back by reverting the new routes and auth integration; preserve existing database records and provide a migration path for any username-only development accounts before requiring passwords.
