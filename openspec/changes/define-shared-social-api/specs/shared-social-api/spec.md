# Spec Delta

## Purpose

Define a framework-independent HTTP contract for the social application so one Vue client can use either backend and receive equivalent identity, feed, relationship, and publishing behavior.

## ADDED Requirements

### Requirement: Shared API response contract
Both backend implementations MUST expose the same routes, request fields, resource shapes, validation rules, and error codes for each supported operation. A client MUST be able to select either backend by changing its API base URL without changing its behavior or data mapping.

The initial route set MUST include `POST /api/users` for registration, `POST /api/sessions` for login, `GET /api/session` for the current identity, `DELETE /api/session` for logout, `GET /api/csrf` for browser CSRF bootstrap, `GET /api/users?search=` for user discovery, `POST /api/follows` and `DELETE /api/follows/{user_id}`, `POST /api/posts`, `POST /api/shares`, `GET /api/feed`, and `GET /api/operations/{operation_id}`. Public identities MUST use `{id, username}`. Pagination MUST use `limit` and an opaque `cursor`, and return `{data, pagination:{next_cursor}}`.

#### Scenario: Switch backend without changing client behavior
- **WHEN** the client sends the same valid request to either backend
- **THEN** both return the same resource shape and equivalent domain result, allowing for the documented synchronous or pending write status

#### Scenario: Return a structured error
- **WHEN** a request is rejected
- **THEN** the API returns a JSON error envelope containing a stable machine-readable `code`, a human-readable `message`, and optional field `details`

### Requirement: Account registration and session
The API MUST allow registration with a username and password, login by username and password, retrieval of the authenticated user's identity, and logout. Usernames MUST remain unique without regard to letter case; email MUST NOT be required. Protected operations MUST derive the acting user from the authenticated session rather than a caller-supplied user ID.

For this initial contract, usernames MUST contain 3–30 ASCII letters, digits, or underscores. Passwords MUST contain 8–128 characters. These limits are a proposed product contract and MUST be implemented identically by both backends.

#### Scenario: Register an account
- **WHEN** an unauthenticated visitor submits a valid unused username and password
- **THEN** the API creates an account and returns its public identity without returning the password or password hash

#### Scenario: Reject a duplicate username
- **WHEN** registration uses a username already assigned, ignoring case
- **THEN** the API returns a conflict error and leaves the existing account unchanged

#### Scenario: Log in by username
- **WHEN** a visitor submits valid credentials
- **THEN** the API establishes an authenticated session and returns the public identity

#### Scenario: Reject invalid credentials
- **WHEN** a visitor submits an unknown username or incorrect password
- **THEN** the API returns the same authentication error shape without disclosing which credential was incorrect

#### Scenario: Read and end the session
- **WHEN** an authenticated client requests its session identity or logs out
- **THEN** the API returns the authenticated public identity or ends the session, respectively

### Requirement: Discover users and manage follows
The API MUST allow an authenticated user to search public usernames and follow or unfollow another user. Follow relationships MUST be directed, unique per ordered user pair, and MUST NOT include self-following.

#### Scenario: Search accounts
- **WHEN** an authenticated client searches usernames
- **THEN** the API returns a paginated list of other users' public identities without credentials or private session data, excluding the authenticated user

#### Scenario: Follow and unfollow a user
- **WHEN** an authenticated user follows another user or removes that relationship
- **THEN** the API creates at most one directed relationship or removes it, and identifies the acting user from the session

#### Scenario: Reject following self
- **WHEN** an authenticated user attempts to follow themself
- **THEN** the API returns a validation error and creates no relationship

### Requirement: Publish text and share posts
The API MUST allow an authenticated user to publish non-blank text of at most 500 characters and to share another user's original post. Posts MUST be immutable; a share MUST refer to the original post without copying its content or adding a comment. A user MUST NOT share their own post, and duplicate shares by the same user for the same original post MUST NOT create additional share records.

#### Scenario: Publish valid text
- **WHEN** an authenticated user submits valid text
- **THEN** the API accepts one post attributed to the session user

#### Scenario: Reject invalid post text
- **WHEN** an authenticated user submits blank text or text over 500 characters
- **THEN** the API returns a validation error and creates no post

#### Scenario: Share another user's post
- **WHEN** an authenticated user shares an existing post by another user
- **THEN** the API records a share action that refers to the unchanged original post

#### Scenario: Reject own-post or duplicate share
- **WHEN** a user attempts to share their own post or repeats a share of the same original post
- **THEN** an own-post attempt returns a validation error and a duplicate does not create another share

### Requirement: Read the personalized feed
The API MUST provide a paginated chronological feed containing posts authored by the authenticated user and posts authored by users they follow. Feed entries MUST distinguish original posts from shares and preserve the identity of the user who performed each action and the author and content of the original post.

Each feed item MUST expose `{id, kind, created_at, actor:{id,username}, post:{id, content, author:{id,username}, created_at}}`, where `kind` is `post` or `share`. Results MUST sort by `created_at` descending with `id` descending as a stable tie-breaker.

#### Scenario: Read own and followed users' activity
- **WHEN** an authenticated user requests the feed
- **THEN** the API returns their own posts and posts or share actions by followed users in reverse chronological order

#### Scenario: Exclude unrelated users
- **WHEN** another user's post is not authored by the current user or a followed user
- **THEN** that post is absent from the personalized feed

#### Scenario: Continue a feed page
- **WHEN** the client requests a subsequent page using the returned pagination cursor
- **THEN** the API returns the next portion without duplicating entries from the prior page

### Requirement: Represent synchronous and pending writes
The API MUST distinguish a write that has completed from one accepted for asynchronous processing. A synchronous backend MUST return the resulting resource only after its write has committed. An asynchronous backend MAY return an accepted operation with a stable operation identifier, a pending state, and a URL for retrieving its status. The API MUST expose a consistent terminal result shape when processing completes.

Completed writes MUST return HTTP `201` with `{data:{operation_id,status:"completed",resource}}`. Pending writes MUST return HTTP `202` with `{data:{operation_id,status:"pending",status_url}}`. `GET /api/operations/{operation_id}` MUST return `{data:{operation_id,status,resource?,error?}}`, where `status` is `pending`, `completed`, or `failed`; `resource` is present only for completed operations and `error` only for failed operations.

#### Scenario: Complete a synchronous write
- **WHEN** a write is committed within the request
- **THEN** the API returns a successful completed result containing the created resource

#### Scenario: Accept an asynchronous write
- **WHEN** a write is accepted but has not yet completed
- **THEN** the API returns an accepted pending result with an operation identifier and status URL, and the client can display that the publication or share is being sent

#### Scenario: Poll pending operation
- **WHEN** the client retrieves a pending operation's status URL
- **THEN** the API reports pending or a terminal completed/failed state using the shared operation and resource shapes

#### Scenario: Do not report uncommitted work as complete
- **WHEN** a write has only been accepted for processing
- **THEN** the API MUST NOT report it as completed or expose a successful resource result
