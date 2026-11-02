# Tasks

## 1. Stabilize the wire contract

- [ ] 1.1 Document canonical request/response examples for registration, login, session, CSRF bootstrap, user search, follows, posts, shares, feed pagination, errors, and operation polling; verify every route and field matches `specs/shared-social-api/spec.md`.
- [ ] 1.2 Define shared validation and serialization fixtures, including case-insensitive username uniqueness, username/password limits, feed tie-breaking, and error codes; verify fixtures are framework-neutral and reusable by both backends.

## 2. Implement the synchronous API

- [x] 2.1 Add registration, login, session identity, logout, and CSRF behavior to Laravel; verify public responses never expose password material and protected routes use the authenticated session identity.
- [x] 2.2 Add account discovery, follow/unfollow, and paginated feed endpoints to Laravel; verify feed includes self and followed users only, with posts and shares represented consistently.
- [x] 2.3 Adapt Laravel post and share writes to authenticated actors and the common completed-operation response; verify writes are committed before returning `completed`.
- [ ] 2.4 Run the shared contract checks against Laravel and document local API setup and session/CSRF requirements; verify the examples work through the configured API origin.

## 3. Implement the asynchronous API

- [ ] 3.1 Add equivalent registration, login, session identity, logout, and CSRF behavior to Hyperf; verify it matches Laravel's public identity and error shapes.
- [ ] 3.2 Add equivalent account discovery, follow/unfollow, and paginated feed endpoints to Hyperf; verify the same fixtures yield equivalent feed entries and pagination.
- [ ] 3.3 Adapt Hyperf post and share writes to authenticated actors and the common pending/completed operation contract; verify pending requests can be polled to a terminal result.
- [ ] 3.4 Run the shared contract checks against Hyperf and document local API setup and session/CSRF requirements; verify the same examples pass against both backends.

## 4. Confirm frontend interchangeability

- [x] 4.1 Configure the Vue client to use a selectable API base URL and consume only the shared contract; verify switching backend configuration requires no endpoint-specific client branches.
- [x] 4.2 Verify the UI shows pending feedback for HTTP `202`, confirmation after completion, and a useful error for failed operations using the common response envelope.
