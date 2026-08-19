# TECHNICAL-DECISIONS.md — Carpool Mobility Platform (CMP)

> **What this file is.** The record of the *technical* decisions that
> `CLAUDE-READINESS-ANALYSIS.md` §21.1 and §22.1 identified as blocking the first line of
> code (B1–B6 / T1–T6). They were taken under the Project Owner's instruction of
> 2026-08-19 that the implementation agent is autonomous in technical implementation, and
> **ratified by the Project Owner on 2026-08-19 exactly as recorded**.
>
> **What this file is not.** It is not a specification document. It creates no requirement,
> takes no business decision, closes no gap, and issues no identifier into the forward
> traceability chain. It does not sit in the `Document/` chain and nothing here overrides
> `Document/`. Like `CLAUDE.md` and `CLAUDE-READINESS-ANALYSIS.md` it is held at project
> root and is not registered in `Documentation_Index.md`.
>
> **`TECH-DEC-001` … `TECH-DEC-005` are closed.** The Project Owner directed that they not
> be modified, reinterpreted or extended. A change to any of them is a new decision, taken
> by the Project Owner and recorded as a new entry — not an edit to an existing one.
>
> **Business decisions are not recorded here.** `BAD-DEC-*`, `FRD-GAP-*` and every
> withheld area remain open and remain blockers. See `CLAUDE.md` §12.

| Field | Value |
|---|---|
| Document Name | Technical Decisions Record |
| Project Name | Carpool Mobility Platform |
| Project Code | CMP |
| Status | **Ratified** — `TECH-DEC-001` … `TECH-DEC-005`, Project Owner, 2026-08-19 |
| Recorded | 2026-08-19 |
| Ratified | 2026-08-19, by the Project Owner |
| Change control | `Document/00_Project_Control/Document_Change_Log.md` entry **087** |
| Classification | Internal |
| Basis | `CLAUDE-READINESS-ANALYSIS.md` §21.1, §22.1; Project Owner instructions of 2026-08-19 |
| Related | `CLAUDE.md`, `CLAUDE-READINESS-ANALYSIS.md`, `backend/README.md` |

---

## `TECH-DEC-001` — Runtime and framework versions (T1 / B1)

| Component | Decision | Why |
|---|---|---|
| PHP | **8.3** (`^8.3`) | Required by the Laravel version below; installed toolchain is 8.3.32. |
| Laravel | **13.x** (`^13.17`) | Current stable line. `ARCH-014` names Laravel; no version is stated anywhere in the chain. |
| MySQL | **8.4 LTS** | `OPS-024` ‡ requires a server that **enforces** `CHECK`; MySQL enforces `CHECK` from 8.0.16. 8.4 is the current LTS. `DADR-02` requires InnoDB, which is the 8.4 default. |
| Filament | **not yet decided** | The administrative unit cannot start — `ADM-168`, blocked by `BAD-DEC-006`. Deciding a version now would be premature. |
| Kotlin / JDK / AGP / Compose | **not yet decided** | Deferred to the first Mobile work item. `CMP-IMP-010` is already `Blocked` on the supported device range (`MOB-OQ-001`), which the build must declare. |

**Scope of this entry:** the backend only. It does not decide the mobile toolchain.

## `TECH-DEC-002` — Git and GitHub policy (T2 / B2)

`CLAUDE.md` §9 records that the documentation defines no version-control policy. The
Project Owner has since instructed that the repository at
`https://github.com/arindamuaguria/carpool-mobility-platform.git` be used and maintained.
The following is the minimum policy needed to work; it is a placeholder for a Delivery
Lead decision, not a substitute for one.

- **Trunk:** `main`. Integrated, always green, never committed to directly.
- **Work branches:** `feature/CMP-IMP-nnn-short-slug`, one per work item or per coherent
  group of work items in the same feature. Merged into `main` with `--no-ff`.
- **Commit convention:** Conventional Commits, with the work item and the requirements it
  realises named in the body. Every commit must be traceable to a `CMP-IMP-` work item.
- **Never committed:** a secret of any kind (`SADR-14`, `OPS-098`), `vendor/`, `.env`,
  a build artefact, or a `Document/` change made without instruction.
- **The six tracker stages stay distinct.** Implemented / Committed / Pushed / Tested /
  Reviewed / Completed are not collapsed (`CLAUDE.md` §9). A commit records only that the
  work was committed.
- **No tag or release scheme is decided.** Nothing is tagged until one is.
- **UI work is not committed before the human visual test gate.** Project Owner
  instruction, 2026-08-19 §17.

## `TECH-DEC-003` — Static analysis and lint tooling (T3 / B3)

`TC-037` ‡ requires thirteen structural rules to run on every commit and fail the build;
`TC-038` ‡ makes eight of them non-suppressible; `TC-042` requires a rule producing a
false positive to be **narrowed, never suppressed**.

| Tool | Enforces | Rules |
|---|---|---|
| **Deptrac 4** | Layer dependency direction and namespace membership (`BE-003`, `BE-014`, `TC-044`) | 1, 2, 3, 7 |
| **PHPStan 2 (level 9) + Larastan 3** | Type reachability, `BE-002` framework absence, custom structural rules as they are written | 4, 9, and later 11, 12, 13 |
| **Laravel Pint** | Style only. Not a structural rule and not a gate on its own. | — |
| **PHPUnit `Architecture` suite** | Rules that need reflection or file inspection rather than a type graph | 5, 6, 8, 10 |

**No baseline file and no suppression list exists in any of these configurations**, and a
rule is only added once it is also written into this table and its owning document
(`TC-043`).

## `TECH-DEC-004` — Test frameworks (T4 / B4)

| Level (`TC-025`) | Tool | Constraint honoured |
|---|---|---|
| 1 static analysis | Deptrac, PHPStan, Pint, PHPUnit `Architecture` suite | `TC-037`, `TC-040` |
| 2 unit and domain | **PHPUnit 12**, extending `PHPUnit\Framework\TestCase` directly | `TC-029`/`TC-058` ‡ — no database, no framework, no network |
| 3 integration and service | PHPUnit 12 with the Laravel test case, against a **real MySQL 8.4** | `TC-030` ‡ — never an in-memory substitute |
| 4 contract | PHPUnit 12 | `TC-031` — shape only, never a business rule |
| 5 system behaviour | PHPUnit 12 | — |
| 6 manual review | Specified procedure with a recorded outcome | `TC-032` |

Suites are separate so that `TC-028` — levels run in order, a failure stopping the later
ones — is a property of the runner and not of a convention. `composer verify` runs them in
order. **A level joins `composer verify` on the commit that gives it its first test**; an
empty suite is not a passing level. **No coverage percentage is a gate** (`TC-012`,
`TC-020`).

## `TECH-DEC-005` — Environment variable inventory (B6)

`backend/.env.example` is the inventory. It carries **names and non-secret defaults only**
(`BE-015`, `SADR-14`, `OPS-098`). A variable is added to it on the commit that first reads
it. It is not complete and does not claim to be — a deployable inventory additionally needs
the hosting decision (`BAD-DEP-009`) and the 21 sizing decisions, all of which are open.

---

## Deliberately **not** decided here

| # | Item | Why it is not taken here |
|---|---|---|
| T5 | **Monetary precision** (`DB-032`) | Needs launch-scale figures (`GAP-016`) — a Product Owner input, not a technical one. No money column may be created until it is set. |
| T6 | **Route-overlap algorithm and minimum threshold** (`ARCH-OQ-001`, `TECH-DEC-01`) | The readiness analysis routes this through CMP-DOC-07 before anyone implements `FRD-FR-077`. It is the platform's differentiating capability and is not an implementation detail. |
| — | Filament version, admin role set | `ADM-168` — the admin unit cannot start without `BAD-DEC-006`. |
| — | Android toolchain | Deferred to the first Mobile work item; `MOB-OQ-001` already blocks `CMP-IMP-010`. |
| — | SMS/OTP, PSP, email and hosting suppliers | Supplier selections, not technical decisions (readiness analysis §25 action 11). |
| — | CI/CD product | `OPS-096` names none. `composer verify` is a local gate, not a pipeline. |

---

## Change log

| Date | Entry | Change |
|---|---|---|
| 2026-08-19 | `TECH-DEC-001` … `TECH-DEC-005` | Initial record. Taken to unblock `CMP-IMP-021` and `CMP-IMP-022`. |
| 2026-08-19 | `TECH-DEC-001` … `TECH-DEC-005` | **Ratified by the Project Owner, exactly as recorded.** Recorded through the change-control process at `Document_Change_Log.md` entry 087. The Project Owner directed that they not be modified, reinterpreted or extended, and that `T5` and `T6` remain undecided. |
