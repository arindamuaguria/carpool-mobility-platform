# `Cmp\Application` — the Application layer

**Contract:** `BE-006`, `BE-041`–`BE-058`.

- Contains orchestration, transactions, authorisation and idempotency — and **no
  invariant** (`BE-006`). An invariant belongs to its aggregate.
- One use case is realised by **exactly one** application service operation (`BE-041`).
- A service accepts a command in application terms, not a transport representation
  (`BE-042`), and is invocable without HTTP context (`BE-043`).
- Authorisation is evaluated **before** the domain is invoked (`BE-044`).
- Every state-changing operation accepts an idempotency key (`BE-045`).
- **Only** an application service begins, commits or rolls back a transaction
  (`BE-047` ‡), at the narrowest scope preserving the required atomicity (`BE-054`).
- **No external provider call inside a transaction** (`BE-050` ‡); obtain the result
  first (`BE-052`).
- Depends on `Cmp\Domain` only.

Organised by domain area (`BE-004`).
