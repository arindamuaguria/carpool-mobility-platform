# CLAUDE.md — Carpool Mobility Platform (CMP)

> Operating memory for Claude Code. Navigation and rules only — **not** a copy of the
> documentation. Every rule here traces to a source document; follow the reference when you
> need detail.
>
> Audit basis: `CLAUDE-READINESS-ANALYSIS.md` (2026-08-19).

---

## 1. Project Identity

Peer-to-peer carpooling and daily-commute platform for the Indian market. Android client,
Laravel backend, MySQL, Laravel Filament admin, REST/JSON API at `/api/v1/`, UPI payments,
Google Maps/Places/Routes, Firebase Cloud Messaging.

**Brand name is undecided** (`BAD-DEC-023`). Refer to the project as *Carpool Mobility
Platform* or *CMP*. The folder and repository name `carpool-mobility-platform` is not a brand
and carries no authority, and neither is any name used informally in conversation. Do not
adopt one as decided (README §2).

**Three architectural absolutes** (README §4–5, `ARCH-016`, `MOB-010`):

1. Android is a **client**. Laravel is the **business authority**. The REST API is the contract.
2. The Android app **must never** connect to MySQL.
3. The client **must never** contain a business rule, fare computation, seat calculation or
   state-transition rule. `MOB-011` requires this to be verified by build-time inspection.

**Excluded technologies:** Supabase, PostgreSQL, Spring Boot (README §3.1).

---

## 2. Current State — verified, 2026-08-19

- **No application source code exists.** The repository contains `Document/` and two
  root-level audit files. Nothing is implemented, mocked or stubbed.
- **20 specification documents, all v0.1/v0.2 Draft. Zero approved.**
- **507 implementation work items** in the register: 496 `Not Started`, 11 `Blocked`, **0 in
  any other status**.
- **Development gate: NO. Deployment gate: NO.** See §12.

---

## 3. Source-of-Truth Hierarchy

```
Project Owner  →  DOC-01 BAD  →  DOC-02 BRD  →  DOC-03 UC  →  DOC-04 FRD
                                                     ↓
                              DOC-05 NFR  →  DOC-06 SRS  →  DOC-07 SAD
                                                     ↓
                   DOC-08 Mobile · DOC-09 Backend · DOC-10 API · DOC-11 DB
                   DOC-12 UX · 13 Security · 14 Payment · 15 GPS · 16 Notif · 17 Admin
                                                     ↓
                             DOC-18 Testing → 19 DevOps → 20 Traceability
```

- **Business rules:** CMP-DOC-01. **System behaviour:** CMP-DOC-04. These win.
- **Earlier in the chain beats later.** If DOC-10 and DOC-04 disagree, **DOC-04 governs** —
  and you must still report the conflict (§6).
- `.docx` files are **generated presentations**. Never read them as authority; read the `.md`.
- The `.xlsx` tracker is the working artefact; `CMP-Implementation-Tracker.docx` is a
  generated rendering — never edit it.

---

## 4. Mandatory Rules

1. **Never invent a business decision.** If behaviour is undefined, stop and report (§6).
2. **Never implement a functional gap.** `FRD-RISK-002` — *a developer implements a gap* — is
   the highest-severity risk in the chain. A `— GAP —` row in CMP-DOC-04 means **no
   requirement exists**. Escalate; never infer.
3. **Never build a withheld item.** 37 items are named-and-withheld across DOC-10 §11.14,
   DOC-11 §6.11, DOC-12 §17, DOC-17 §15. `ADM-187`/`ADM-191`: they must not be stubbed,
   prototyped, hidden behind a role, disabled, flagged, or marked "coming soon".
4. **Backend is authoritative.** Reject any client-supplied seat availability, booking status,
   payment status, fare, wallet balance, reward accrual or verification status. Reject the
   **whole request**; never partially apply. Record the attempt as an integrity event
   (`FRD-FR-237`–`241`).
5. **Every integrity-critical (‡) requirement needs a negative test** proving the rule cannot
   be violated (`FR-04`, CMP-DOC-04 §3.5). ‡ requirements are **not subject to descoping**.
6. **Write the audit record before reporting success.** An action whose evidential record
   cannot be written is not complete (`FRD-FR-248`).
7. **No provider call inside a transaction** (`BE-050`). Obtain the result first.
8. **Verify the source.** Every claim you make about a requirement must cite its ID.

---

## 5. Prohibited Behaviour

**Never:**

- Modify anything under `Document/` — including control files — without an explicit instruction.
  Documents are created only when requested, and requesting one never authorises another
  (README §8.1).
- Mark any document `Approved`, or any work item `Completed`. Both are the Project Owner's.
- Build the **SOS control** or any safety response. `BAD-DEC-011` is open and no response
  capability is staffed. `FRD-FR-195` makes the withholding testable. An SOS button with no
  response is a liability (`BAD-RISK-005`).
- Build **ratings, wallet, rewards, or recurring commute**. All three areas carry **zero
  functional requirements** (CMP-DOC-04 §9.2). *Note: DOC-10 §11.9 and DOC-11 §6.7 wrongly
  specify a ratings endpoint and table — see `CC-025`. Do not follow them.*
- Build **refunds, cancellation consequences, driver settlement, verification adjudication or
  account-state change**. All withheld.
- State or imply anywhere in the product that the platform provides **insurance** (CMP-DOC-04
  §8.5 prohibition, `BRD-REQ-187`).
- Store a payment instrument credential in any form, anywhere (`SADR-10`, `DB-037`).
- Treat a UPI application's response as evidence of payment (`FRD-FR-124`, `PADR-03`).
- Present a cached value as authoritative, or a stale position as current (`FRD-FR-150`).
- Fabricate a commit hash, test result or status in the tracker.
- Run `git` commands or create a repository — **no Git policy exists** (§9).

---

## 6. Business Decision & Blocker Handling

When you hit undefined behaviour, **stop that work item and report**. Do not proceed under an
assumption, and do not pick "the obvious" answer.

**Report in this shape:**

```
BLOCKED — BUSINESS DECISION REQUIRED
Work item : CMP-IMP-nnn  (or the file/feature)
Behaviour : what cannot be determined
Source    : the requirement or gap ID, e.g. FRD-GAP-009
Decision  : the deciding item, e.g. BAD-DEC-007
Owner     : who must decide
Effect    : what stays unbuildable meanwhile
```

Use `BLOCKED — ARCHITECTURE DECISION REQUIRED` for technical decisions, and
`CONFLICT` for document disagreements (state both documents, both sections, the impact, and
who should resolve — **never choose a winner yourself**).

**Continue with everything the blocker does not touch.** Partial delivery plus a precise
blocker is the correct outcome.

**Registers — read, never edit without instruction:**
`09_Decisions`, `10_Dependencies`, `11_Risks` sheets of the tracker; `Document_Change_Log.md`
§5 for repository-level conflicts.

---

## 7. Documentation Navigation

| Looking for | Go to |
|---|---|
| Business rules, the 24 open decisions, proposed MVP | `Document/01_Business_Analysis/` §14, §24, §27 |
| Use cases (44 Specified, 6 Partial, **33 Outlined**) | `Document/03_Stakeholders_Use_Cases/` |
| **System behaviour — the behaviour authority** | `Document/04_Functional_Requirements/` |
| Functional gaps (29, 11 Critical) | CMP-DOC-04 **§9.3** — read before building anything |
| Quality targets (69 unset) | `Document/05_Non_Functional_Requirements/` |
| Architecture, ADRs | `Document/07_System_Architecture/` |
| Client modules, layers, state, outbox | `Document/08_Mobile_Architecture/` |
| Aggregates, invariants, transactions, jobs | `Document/09_Backend_Architecture/` |
| Endpoints, error model, idempotency, versioning | `Document/10_API_Specification/` §8, §11 |
| Schema, keys, constraints, ledger, evidential log | `Document/11_Database_Design/` |
| Screens (32), states, failure treatments | `Document/12_UI_UX_Specification/` §4.1 |
| Trust boundaries, auth, secrets | `Document/13_Security/` |
| UPI flow, verification, reconciliation | `Document/14_Payment_UPI/` |
| Tracking, staleness, estimates | `Document/15_GPS_Live_Trip/` |
| Messaging, notification categories | `Document/16_Communication_Notifications/` |
| Filament resources, projections, capabilities | `Document/17_Admin_Filament/` |
| Verification levels, obligations, build gates | `Document/18_Testing_QA/` |
| Environments, units, gates, secrets | `Document/19_DevOps_Deployment/` |
| Release criteria, open-decision index | `Document/20_Traceability_Release/` |
| **Implementation tracker (working artefact)** | `Document/20_Traceability_Release/CMP-Implementation-Tracker.xlsx` |
| Governance, naming, integrity rules | `Document/00_Project_Control/README.md` |
| Terminology (253 terms) | `Document/00_Project_Control/Glossary.md` |

> **Cross-check CMP-DOC-10 §11 against CMP-DOC-04 before implementing any endpoint.** Four
> `Realises` citations are known-wrong and three operations exist for withheld behaviour
> (`CONFLICT-01`, `-02`, `-04`, `-05` in the readiness analysis).

---

## 8. Development Workflow

`Analyse → Plan → Implement → Test → Review → Track → Commit`

1. **Analyse** — read the work item, then its cited requirements. Check CMP-DOC-04 §9.3 for a
   gap touching it. Check the tracker Notes column.
2. **Plan** — state which requirements you are realising and which layer owns the change.
3. **Implement** — respect the layer contract. One business rule lives in exactly one Domain
   component (`BE-010`). No business logic in the client.
4. **Test** — §10.
5. **Review** — self-review against ‡ requirements; a negative test per ‡.
6. **Track** — report which work items you touched. **Do not set status yourself**; the
   Project Owner maintains it.
7. **Commit** — **not yet possible.** §9.

---

## 9. Git Workflow — NO POLICY EXISTS

**The documentation defines no version-control policy whatsoever.** Verified across all 28
sources: "GitHub" 0 occurrences, "pull request" 0, no branch strategy, no commit convention,
no merge policy, no tagging or versioning scheme.

**Therefore:**

- **Do not initialise a repository, branch, commit, push, tag or open a PR** until a human
  records the policy.
- If asked to commit, reply with `BLOCKED — DECISION REQUIRED: Git/GitHub policy undefined`
  and cite readiness analysis §14.
- The tracker separates **Implemented / Committed / Pushed / Tested / Reviewed / Completed**
  as six distinct stages. Never collapse them, and never mark a later one to mean an earlier.

*(CMP-DOC-19 §9 defines four build **gates** — Commit, Merge, Pre-release, Release — as
pipeline properties. These are not a Git policy and no pipeline product is named,
`OPS-096`.)*

---

## 10. Testing Rules

- **Six levels, in order**, a failure stopping the later ones (`TC-025`, `TC-028`): static
  analysis → unit/domain → integration/service → contract → system behaviour → manual review.
- An obligation belongs at **the earliest level that can verify it conclusively** (`TC-033`).
- Domain tests run **without a database, framework or network** (`TC-029`).
- Integration tests run against a **real MySQL that enforces `CHECK`** — never in-memory
  (`TC-030`, `OPS-024`).
- Contract tests assert **shape, never business behaviour** (`TC-031`).
- Concurrency tests run under **genuine parallelism** (`TADR-08`) — overbooking must be proven
  impossible.
- Failure-induction hooks must be **absent from the production artefact** (`OPS-099`).
- **A negative test is required for every ‡ requirement** (`FR-04`).
- **No coverage percentage is a gate** (`TC-012`, `TC-020`). 25 obligations are
  non-suppressible at gate 4 (`TC-021`).
- **A passing suite is not readiness** (`TR-121`).

**Test framework is unchosen** — see §13.

---

## 11. Security Rules

- Deny by default; authorisation evaluated in the **application layer**, one path
  (`SADR-06`, `BADR-14`).
- Reject any client assertion of an authoritative value; record it as a security event
  (`SADR-08`).
- Credentials non-recoverable, never on `op_users` (`DB-036`, `DB-042`).
- Protection at rest is **per column**, not per database (`SADR-05`).
- Bind every value; never concatenate a statement (`SADR-09`, `DB-038`).
- **No payment instrument surface anywhere** — no field, column, log or response body
  (`SADR-10`).
- Secrets are injected at deploy time and never appear in an artefact, repository or build log
  (`SADR-14`, `OPS-098`).
- The device holds nothing worth stealing (`SADR-13`).
- Safety traffic is exempt from every rate limit, quota and throttle (`OPS-046`).
- **Fraud is unowned** (`GAP-013`) and **the applicable regulatory regime is unknown**
  (`SEC-OQ-01`). Do not design either; report if work touches them.

---

## 12. Current Blockers

**Cannot start — technical decisions, not recorded in any register**
(readiness analysis §21.1): runtime/framework versions · Git policy · static-analysis tooling
· test framework · monetary precision (`DB-032`) · environment variable inventory.

**Live product defects** — reachable through specified behaviour, no engineering needed:
`FRD-GAP-006`/`GAP-008` a driver with confirmed bookings cannot cancel · `FRD-GAP-014`/`GAP-009`
the platform can hold money it cannot return.

**Blocking the core journey:** `BAD-DEC-007` (booking model) · `BAD-DEC-003` (fare) ·
`BAD-DEC-005` (verification) · `BAD-DEC-022` (privacy) · overlap algorithm (`ARCH-OQ-001`).

**Blocking deployment:** `BAD-DEP-009` hosting · 21 sizing decisions · `BAD-DEC-021`
retention · `BAD-DEC-006` admin roles (`ADM-168` — the admin unit cannot start).

**Launch gate:** `BAD-DEC-011` safety response · `BAD-DEC-001` legal opinion.

**First implementable features once the technical decisions are recorded:** `FEAT-033`
Evidential Log, `FEAT-032` Backend Authority, `FEAT-035` API Contract — all derive from
CMP-DOC-04 §8, which has no open dependency.

---

## 13. Documentation Change Rules

- **Do not modify `Document/`** without an explicit instruction — this includes the six
  control files in `00_Project_Control/`.
- If a document needs correcting, **report the required correction**; do not apply it.
- If instructed to change a document: update it, add a `Document_Change_Log.md` entry, and
  keep `Documentation_Index.md` and `Documentation_Status.md` consistent.
- Requirement IDs are **stable**. Never renumber, never reuse — including withdrawn
  `CMP-IMP-` identifiers.
- Never mark a document `Approved`.

---

## 14. Stop Conditions

Stop and ask before: creating any file under `Document/` · any Git operation · any deployment
or infrastructure action · installing a dependency whose version is undecided · implementing
anything CMP-DOC-04 marks `— GAP —` · building anything on the withheld list · setting a
value the documentation records as `[TBD]`.

**When in doubt, the correct action is to report a blocker — not to choose.**
