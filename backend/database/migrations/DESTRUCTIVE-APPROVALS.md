# Destructive migration approvals

`DB-218` ‡ — **a destructive migration requires explicit recorded approval.**

A migration that drops a column, a table or a constraint destroys something a
party may be entitled to as evidence. `DB-204` ‡ is stronger still: a constraint
is never dropped to make a migration succeed, and a migration that requires one
dropped is **rejected**, not approved.

## How this register works

`DestructiveMigrationGuard` scans `database/migrations/` for destructive schema
operations. A migration that performs one and is **not listed below** fails the
build. The check runs at level 1 (`composer analyse`) and again before any
migration runs.

Approval is recorded by naming the migration file in a table row below, in
backticks. Only the **Project Owner** records an approval. Nothing in the
platform, and no implementation agent, may add a row on its own behalf — an
approval nobody gave is not an approval.

`DB-219` ‡ is absolute and is not approvable here: **no migration shall rewrite
an existing evidential or ledger record.** A migration against `ev_` or `led_` is
additive (`DB-123`), and a mistaken evidential record is corrected by a new
record referencing it, never by amendment (`DB-124` ‡).

## Register

| Migration | What is dropped | Why it is safe | Approved by | Date |
|---|---|---|---|---|
| *(none)* | — | — | — | — |

> The register is empty. The schema has never dropped anything.
