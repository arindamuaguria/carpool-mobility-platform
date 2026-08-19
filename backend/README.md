# CMP Backend

The **business authority** for the Carpool Mobility Platform (`ARCH-016`). The Android
client is a client; this is where every business rule, fare computation, seat calculation
and state transition lives, and the REST API is the contract between them.

Documentation is the source of truth. See `../CLAUDE.md` for navigation, and
`../TECHNICAL-DECISIONS.md` for the runtime, tooling and test-framework decisions.

## Requirements

| | |
|---|---|
| PHP | 8.3 (`TECH-DEC-001`) |
| Composer | 2.x |
| MySQL | 8.4 — must enforce `CHECK` (`OPS-024` ‡) |

## Layout

The four namespaces of `BE-001`, each organised by domain area (`BE-004`):

```
src/Domain/          no framework type, depends on nothing        BE-002, BE-003, BE-007
src/Application/     orchestration, transactions, authorisation   BE-006
src/Infrastructure/  repositories, adapters, evidential writer    BE-008
src/Interface/       adapters only — REST, admin, safety, console BE-005
```

Each has a `README.md` restating its contract. There is no `app/` directory: the
framework-default MVC layout is not used (`BADR-01`).

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

`.env.example` is the environment variable inventory (`TECH-DEC-005`). It carries names
and non-secret defaults only — never a value (`BE-015`, `SADR-14`).

### Database

`DADR-09` requires **three** accounts against the one schema of `DB-001`:

| Connection | Account | Holds |
|---|---|---|
| `mysql` | application | `SELECT INSERT UPDATE DELETE` on `op_`, `proj_`, `mch_`; `SELECT INSERT` only on `ev_` and `led_`; **no DDL** (`DB-118` ‡, `DB-119` ‡) |
| `mysql_migration` | migration | DDL. Used only by migrations (`DB-215`) |
| `mysql_read` | read | `SELECT`. Reporting |

A fourth connection, `mysql_provisioning`, exists only for `db:provision-accounts` and is
never used at runtime: `DB-122` ‡ requires that no credential able to alter the evidential
domain be available to the application **in any environment, development included**.

A local MySQL 8.4 for development — a real server, because `TC-030` ‡ forbids an in-memory
substitute and `OPS-024` ‡ requires one that enforces `CHECK`:

```bash
docker compose up -d
```

Then create the accounts and run migrations:

```bash
composer db:up
```

`composer migrate` runs migrations under the migration account. Running them as any other
account is refused (`DB-215`), and the application account could not perform DDL in any
case — which is the point of `DB-119` ‡.

Every migration additionally verifies, without being asked:

- `DB-218` ‡ — no migration drops a column, a table or a constraint without an approval
  recorded in `database/migrations/DESTRUCTIVE-APPROVALS.md`. Approval is the Project
  Owner's; nothing here may record one on its own behalf.
- `OPS-024` ‡ / `DB-217` ‡ — the server enforces `CHECK`, proven by attempting a violating
  write. A server that accepts the clause and ignores it looks identical in the schema, and
  fourteen of the twenty-one constraints in the CMP-DOC-11 §15 register would be decoration.
- `DB-002`, `DB-009` ‡, `DB-010`, `DB-012` ‡, `DB-013`, `DB-015`–`DB-020` ‡ — storage-domain
  membership, engine, collation, and the naming rules, including that **no identifier
  incorporates a city, corridor, region or market**.

`php artisan schema:verify` runs the same checks on demand, in any environment (`TC-040` ‡).

## Verification

Six levels, in order, a failure stopping the later ones (`TC-025`, `TC-028`):

```bash
composer verify
```

| Command | Level | Constraint |
|---|---|---|
| `composer analyse` | 1 | Pint, PHPStan level 9, Deptrac. No baseline, no suppression (`TC-042`). |
| `composer test:architecture` | 1 | Structural rules asserted in code (`TC-037` ‡). |
| `composer test:domain` | 2 | No database, no framework, no network (`TC-029` ‡). |
| `composer test:integration` | 3 | A real MySQL, never in-memory (`TC-030` ‡). |
| `composer test:contract` | 4 | Shape only, never a business rule (`TC-031`). |
| `composer test:system` | 5 | System behaviour. |

A level joins `composer verify` on the commit that gives it its first test. **No coverage
percentage is a gate** (`TC-012`, `TC-020`), and **a passing suite is not readiness**
(`TR-121`).

## Rules that constrain every change here

- Reject any client-supplied authoritative value — seat availability, booking status,
  payment status, fare, wallet balance, reward accrual, verification status. Reject the
  **whole request**; never partially apply (`FRD-FR-238`, `FRD-FR-239` ‡).
- Write the audit record before reporting success (`FRD-FR-248` ‡).
- No external provider call inside a transaction (`BE-050` ‡).
- One business rule lives in exactly one Domain component (`BE-010`).
- Every ‡ requirement needs a negative test proving the rule cannot be violated (`FR-04`).
- Never store a payment instrument credential, anywhere, in any form (`SADR-10`).
