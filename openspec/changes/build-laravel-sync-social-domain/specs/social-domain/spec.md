# Spec Delta

## Purpose

Define the synchronous social domain used by the Laravel API: users, directed follower relationships, text posts, and shares that refer to an original post. This capability establishes observable domain rules while leaving notification fanout and load generation for later decisions.

## ADDED Requirements

### Requirement: User identity
The system MUST allow creation of users with a stable identifier and a public username. Usernames MUST be unique without regard to letter case. User records MUST NOT require an email address.

#### Scenario: Create user with unused username
- **WHEN** a user is created with a username not already assigned, ignoring letter case
- **THEN** the system creates the user with a stable identifier

#### Scenario: Reject case-insensitive duplicate username
- **WHEN** a user is created with a username that differs only by letter case from an existing username
- **THEN** the system rejects the creation and preserves the existing user

### Requirement: Directed follower relationships
The system MUST allow one user to follow another user, with at most one relationship for each ordered pair. A user MUST NOT follow themself. Following in one direction MUST NOT create the reverse relationship.

#### Scenario: Create directed follow relationship
- **WHEN** user A follows user B and A and B are distinct
- **THEN** the system records A as following B without recording B as following A

#### Scenario: Reject self-follow
- **WHEN** a user attempts to follow themself
- **THEN** the system rejects the relationship

#### Scenario: Do not duplicate a follow relationship
- **WHEN** the same ordered follow pair is submitted more than once
- **THEN** at most one relationship exists for that pair

### Requirement: Text posts
The system MUST allow a user to create a post with required text content of at most 500 characters. Content that is empty or consists only of whitespace MUST be rejected. Each post MUST have exactly one author. Posts MUST NOT be editable or deletable in this initial capability.

#### Scenario: Create valid text post
- **WHEN** a user submits non-whitespace text within the 500-character limit
- **THEN** the system creates one post associated with that user

#### Scenario: Reject invalid post content
- **WHEN** a user submits empty, whitespace-only, or over-limit content
- **THEN** the system rejects the post and does not persist it

#### Scenario: Keep post immutable
- **WHEN** a request attempts to edit or delete an existing post
- **THEN** the system does not change or remove the post

### Requirement: Shares of original posts
The system MUST represent a share as an action by one user referring to one original post. A user MUST NOT share their own post, and at most one share MUST exist for each user and original post pair. A share MUST NOT create a copy of the original post or add a comment. The system MUST retain the origin action that motivated a simulated share only when that origin is available as a persisted domain event; an origin MUST refer to the same original post.

#### Scenario: Share another user's post
- **WHEN** a user shares an existing post authored by a different user
- **THEN** the system records a share associated with the sharing user and original post

#### Scenario: Reject sharing own post
- **WHEN** a post's author attempts to share that post
- **THEN** the system rejects the share

#### Scenario: Do not duplicate a share
- **WHEN** the same user submits another share for the same original post
- **THEN** at most one share exists for that pair

#### Scenario: Preserve original post
- **WHEN** a share is created
- **THEN** the original post and its author remain unchanged and no copied post or comment is created

### Requirement: Synchronous request completion
The system MUST complete each supported domain operation in the request's synchronous processing flow and MUST NOT require a message queue or background worker for that operation to be considered complete.

#### Scenario: Complete domain write synchronously
- **WHEN** a valid domain write request succeeds
- **THEN** its database changes are committed before the successful response is returned
