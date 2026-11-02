# Design

## Context

See `proposal.md` for motivation and `specs/social-domain/spec.md` for externally visible rules. The Laravel 13 application is an API-only skeleton configured for MySQL in Docker, with PHP-FPM and Nginx. Its current user migration/model are framework defaults and do not implement the domain. The repository's `docs/system-design.md` contains confirmed domain rules as well as unresolved decisions; this design carries forward only the confirmed rules noted by the spec.

## Goals / Non-Goals

**Goals:**

- Add the first usable synchronous Laravel implementation of users, follows, posts, and shares using the existing MySQL service.
- Preserve domain invariants under concurrent requests through database constraints as well as request validation.
- Keep each operation's persistence inside its HTTP request and transaction where multiple writes are needed.

**Non-Goals:**

- Notification records, recipient fanout, simulated email, and their completion semantics.
- Authentication and authorization, load-test datasets/seeders, cascade simulator, likes, feed, and Hyperf implementation.
- Adding infrastructure services, dependencies, or changing the Docker resource profile.

## Decisions

### Use the existing Laravel and MySQL composition

Implement within `code/laravel` and use the MySQL service already defined by the shared Compose configuration. This keeps the synchronous comparison architecture aligned with the project scope and avoids adding a new dependency. SQLite may remain useful for tests, but behavior involving case-insensitive uniqueness and MySQL constraints must also be checked against MySQL.

### Organize the Laravel application as a modular monolith

At the author's direction during implementation, the domain is divided into Users, Follows, Posts, and Shares modules under `app/Modules`. Each module owns its model, application service, HTTP controller, and API route file. Controllers validate HTTP input and format responses; services apply domain rules and persist records. The Laravel application and MySQL database remain shared; module routes are registered by the central API route file. This keeps deployment and request handling in one application while making domain responsibilities visible in the code structure.

### Enforce uniqueness and relationships in storage

Represent follows and shares as distinct persisted records with foreign keys and unique constraints on `(follower, followed)` and `(user, original_post)`. Reject self-follow and self-share in domain validation; also use database constraints where portable. Store username uniqueness with case-insensitive semantics matching the MySQL collation in use, and verify that collation explicitly rather than relying on a test database default.

The physical schema, identifier types, timestamps, indexes beyond invariant-supporting indexes, and deletion policies should follow the existing Laravel migration conventions and be finalized during implementation without changing the stated behavior.

### Keep share origin nullable until its API contract is decided

The domain decision says simulated shares retain the persisted notification that motivated them, while direct API operations do not yet have a defined origin contract and notifications are out of scope here. Do not invent an origin for ordinary API shares. The implementation must preserve the distinction and avoid making a required origin impossible for direct shares. Revisit this field when the simulator/notification change is specified.

### Keep identity attribution explicit and temporary

Authentication is not confirmed. The API still needs an actor for follow, post, and share operations. For this first implementation, accept an explicit user identifier as the acting user in the request contract (assumption), and do not present this as authentication or access control. Replace this mechanism when authentication is decided.

### Common HTTP contract for Laravel and Hyperf

The authors approved the initial comparison contract on 2026-11-02. Both implementations use `POST /api/users` with `{username}`, `POST /api/follows` with `{follower_id, followed_id}`, `POST /api/posts` with `{user_id, content}`, and `POST /api/shares` with `{user_id, post_id, origin_share_id?}`. Successful first writes return `201` with `{data: ...}`; duplicate follow/share actions return `200` with the existing `{data: ...}` record. Invalid input and domain-rule violations return `422`; missing referenced records return `404`; duplicate usernames return `409`. Actor ids are request-supplied experiment identifiers, not authentication.

`origin_share_id` is optional for direct shares. When supplied for a simulated share, it must identify an already-persisted share of the same original post. A direct share or a share motivated by the original post has no preceding share id and stores `null`. This contract does not add notification persistence or simulated sending.

### No queue or worker in this stage

Writes execute as part of the request and commit before a success response. Notification fanout is excluded pending a domain decision; it must not be silently approximated by an unbounded synchronous loop. This keeps response semantics concrete and leaves fanout cost and partial-failure policy for explicit discussion.

### Defer scale seeders

Provide only factories or minimal fixtures needed for domain tests and local functional use, if required by implementation. Do not add high-volume seeders or claim reproducible load scenarios until population, topology, and sharing distributions are decided.

## Risks / Trade-offs

- [Risk] A request-supplied actor identifier is not secure identity → [Mitigation] Document it as a development/experiment contract and do not treat it as authorization; decide authentication before exposing the API beyond its controlled environment.
- [Risk] Database collations vary and SQLite may not match MySQL username uniqueness → [Mitigation] verify the actual MySQL collation and validate the invariant against MySQL.
- [Risk] Synchronous notification fanout could produce long requests or partial results → [Mitigation] notification fanout is excluded until recipients, transaction boundaries, and failure semantics are decided.
- [Risk] The origin semantics for shares do not cover direct API actions yet → [Mitigation] keep origin optional and preserve only known persisted origins; resolve the contract alongside cascade simulation.

## Migration Plan

1. Replace or adapt framework-default user schema only after checking that no existing local or persisted data must be preserved; development Docker volume may contain data.
2. Add migrations for the domain records and constraints, then run migrations against the existing MySQL service.
3. Implement API behavior and verify core invariants with feature tests and MySQL-backed checks.
4. Roll back by reverting the application release and migration only when the new tables contain no required experiment data; destructive rollback must not be automatic.

## Open Questions

- When notifications are added, who receives notifications for original posts and shares, and how will synchronous fanout handle partial failure?
- Which user/follower population and topology should load seeders generate, and should those seeders be shared as a dataset across both systems?
