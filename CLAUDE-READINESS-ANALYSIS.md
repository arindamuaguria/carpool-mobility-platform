# CLAUDE-READINESS-ANALYSIS.md

**Documentation Readiness Audit — Carpool Mobility Platform (CMP)**

| Field | Value |
|---|---|
| Audit ID | CMP-AUDIT-READINESS-001 |
| Project | Carpool Mobility Platform (CMP) |
| Audit date | 2026-08-19 |
| Auditor | Lead Architect / Documentation Auditor / QA / DevOps / Security review (AI-assisted) |
| Subject | `Document/` — 20 specification documents, 6 control files, 2 planning artefacts |
| Method | Full-text analysis of all 28 Markdown sources plus mechanical ID, citation and coverage checks |
| Status | Draft — for Project Owner review |
| Placement note | This file and `CLAUDE.md` are written at the **project root**, deliberately outside the governed `Document/` tree. No source document or control file was modified by this audit. |

---

## 1. Executive Summary

**The specification is unusually complete and unusually disciplined. The project still cannot start.**

Twenty documents, 3,621 traceable statements, 1,412 of them integrity-critical. Mechanical
checks found **zero** ID defects: all twenty identifier ranges are contiguous, no duplicates,
no dangling citations. The chain never invents a value — 175 `[TBD]` markers are recorded
rather than filled. That is rarer, and more valuable, than it sounds.

The blockers are of two kinds, and **the second kind is the one that surprises**.

**Kind 1 — the known business blockers.** 24 open business decisions, 29 functional gaps
(11 Critical), 37 withheld items, 21 sizing decisions, 6 unselected suppliers. These are
thoroughly registered and each names what it blocks. They stop *features*.

**Kind 2 — the unrecorded engineering blockers.** These stop *the first commit*, and unlike
Kind 1 they are not in any register because nobody noticed they were missing:

| Missing | Evidence | Consequence |
|---|---|---|
| **No version-control policy of any kind** | "GitHub" appears **0 times**; "pull request" **0 times**; the only occurrence of "git" in the whole chain is inside a planning artefact | Claude cannot branch, commit, push, tag or open a PR without inventing project policy |
| **No runtime or framework versions** | Zero matches for a PHP, Laravel, Kotlin, Java, MySQL, Gradle, Compose or Filament version number across 34,000 lines | Claude cannot create a project skeleton without choosing them |
| **No coding standards, style or static-analysis tooling** | 0 matches for coding standard, code style, naming convention, linter, PHPStan, ktlint, detekt | `TC-037` mandates 13 structural rules at gate 1 with **no tool named to run them** |
| **No CI/CD product, no pipeline definition** | `OPS-096`: *"No pipeline product is named"* | Four gates are specified as properties; none is executable |
| **No environment variable inventory** | 0 matches for `.env`; "environment variable" appears 3 times as a concept | `OPS-025` requires per-environment configuration that is never enumerated |

**The consequence.** Even Day 1 of the plan — *create the Android project, create the Laravel
project, create the MySQL schema* — requires four undocumented technical choices. The famous
blockers (fare, cancellation, refund, SOS) are not what stops work starting. **Ordinary
engineering decisions nobody recorded are.**

**Development gate: NO. Deployment gate: NO. Claude Code: NOT SAFE TO IMPLEMENT.**

The good news is that Kind 2 is cheap. Six technical decisions, none of them a business
decision and none requiring the Project Owner's product judgement, would move
**14 features from blocked-at-the-starting-line to buildable**.

---

## 2. Repository Inventory

**FACT.** The repository contains **52 files and no source code.** No `.git`, no `CLAUDE.md`,
no `README` at root, no OpenAPI/YAML/JSON, no scripts, no configuration examples, no
environment files, no build files, no archived or withdrawn documents.

| Type | Count |
|---|---|
| Markdown | 28 |
| Word (`.docx`) | 23 |
| Excel (`.xlsx`) | 1 |
| **Total** | **52** |

### 2.1 Document inventory

Authority levels: **G** governed control file · **S** specification (source of truth) ·
**P** planning artefact · **D** derived/generated.

| ID | File | Loc | Ver | Status | Auth | Purpose |
|---|---|---|---|---|---|---|
| CMP-CTRL-README | `README.md` | 00 | 0.1 | Draft | G | Repository governance, naming, content-integrity rules |
| CMP-CTRL-INDEX | `Documentation_Index.md` | 00 | 3.3 | Draft | G | Master register; identifier namespace allocation |
| CMP-CTRL-STATUS | `Documentation_Status.md` | 00 | 3.3 | Draft | G | Lifecycle status, prerequisites, readiness |
| CMP-CTRL-CHANGELOG | `Document_Change_Log.md` | 00 | 3.3 | Draft | G | 84 change entries, 25 conflict records |
| CMP-CTRL-GLOSSARY | `Glossary.md` | 00 | 3.1 | Draft | G | 253 controlled terms |
| CMP-CTRL-RTM | `Master_Traceability_Matrix.md` | 00 | 3.1 | Draft | G | Chain coverage; 20-entry gap register |
| CMP-DOC-01 | BAD Business Analysis | 01 | 0.1 | Draft | S | 78 `BAD-BR`; 24 business decisions; proposed MVP |
| CMP-DOC-02 | BRD Business Requirements | 02 | 0.1 | Draft | S | 188 `BRD-REQ` |
| CMP-DOC-03 | Use Cases | 03 | 0.1 | Draft | S | 83 `UC` — 44 Specified, 6 Partial, **33 Outlined** |
| CMP-DOC-04 | FRD Functional Requirements | 04 | 0.1 | Draft | S | 260 `FRD-FR`; 81 ‡; **29 functional gaps** |
| CMP-DOC-05 | NFR Quality Attributes | 05 | 0.1 | Draft | S | 162 `NFR`; **69 targets unset** |
| CMP-DOC-06 | SRS Software Requirements | 06 | 0.1 | Draft | S | 184 `SRS-REQ`; six software elements |
| CMP-DOC-07 | SAD System Architecture | 07 | 0.1 | Draft | S | 148 `ARCH`; 24 ADRs |
| CMP-DOC-08 | Mobile Architecture | 08 | 0.1 | Draft | S | 168 `MOB`; 16 MADRs |
| CMP-DOC-09 | Backend Architecture | 09 | 0.2 | Draft | S | 218 `BE`; 18 BADRs; 9 aggregates |
| CMP-DOC-10 | API Specification | 10 | 0.1 | Draft | S | 216 `API`; **55 operations**; 11 withheld |
| CMP-DOC-11 | Database Design | 11 | 0.1 | Draft | S | 232 `DB`; 6 domains; ~30 tables; 7 withheld |
| CMP-DOC-12 | UI/UX Specification | 12 | 0.1 | Draft | S | 224 `UX`; 32 screens; 14 withheld |
| CMP-DOC-13 | Security Design | 13 | 0.1 | Draft | S | 240 `SEC`; 4 trust boundaries; 8 open questions |
| CMP-DOC-14 | Payment & UPI | 14 | 0.1 | Draft | S | 208 `PAY` |
| CMP-DOC-15 | GPS / Live Trip | 15 | 0.1 | Draft | S | 196 `GPS` |
| CMP-DOC-16 | Communication & Notifications | 16 | 0.1 | Draft | S | 188 `NOTIF`; 8 categories |
| CMP-DOC-17 | Admin / Filament | 17 | 0.1 | Draft | S | 204 `ADM`; 9 projections; **5 withheld capabilities** |
| CMP-DOC-18 | Testing & QA | 18 | 0.1 | Draft | S | 216 `TC`; 99 obligations; 25 non-suppressible |
| CMP-DOC-19 | DevOps / Deployment | 19 | 0.1 | Draft | S | 208 `OPS`; 21 sizing decisions, **all open** |
| CMP-DOC-20 | Traceability & Release | 20 | 0.2 | Draft | S | 192 `TR`; 12 release criteria |
| CMP-DOC-20A | Uncited Requirement Review | 20 | 0.1 | Draft | S | Annex; issues no identifiers |
| CMP-PLAN-IMPL-TRACKER | Implementation Work Register | 20 | 0.1 | Draft | P | 507 work items; `.xlsx` authoritative |
| CMP-PLAN-IMPL-ANALYSIS | Implementation Analysis | 20 | 0.1 | Draft | P | Decomposition findings |

**No document is obsolete, withdrawn or superseded.** The Superseded/Deprecated register is
empty. **No document is approved.**

### 2.2 Format duplication

Each `.md` has a `.docx` twin carrying equivalent information (README §8.2). The `.docx`
files are **generated presentations, not independent sources**. They are not counted as
separate documents and must never be read as authority.

---

## 3. Documentation Authority Hierarchy

Derived from `README.md` §9–§10, `Documentation_Index.md` §5, and CMP-DOC-20 `TRDR-13`.

```
Project Owner decisions            ← ultimate authority; nothing overrides
  └─ CMP-DOC-01  Business Analysis          (BAD-BR, BAD-RULE, BAD-DEC)
      └─ CMP-DOC-02  Business Requirements  (BRD-REQ)
          └─ CMP-DOC-03  Use Cases          (UC)
              └─ CMP-DOC-04  Functional Requirements (FRD-FR)  ← behaviour authority
                  ├─ CMP-DOC-05 NFR  (parallel branch, qualifies the baseline)
                  └─ CMP-DOC-06  SRS
                      └─ CMP-DOC-07  SAD
                          ├─ CMP-DOC-08 Mobile   ├─ CMP-DOC-09 Backend
                          │   └─ CMP-DOC-10 API  └─ CMP-DOC-11 Database
                          └─ cross-cutting: 12 UI/UX · 13 Security · 14 Payment
                                            15 GPS · 16 Notifications · 17 Admin
                              └─ CMP-DOC-18 Testing → 19 DevOps → 20 Traceability
                                  └─ CMP-PLAN-IMPL-*  (planning, no authority)
```

**Answers to the required questions:**

| Question | Answer | Source |
|---|---|---|
| Ultimate source of truth? | The **Project Owner**. In documents, **CMP-DOC-01** for business rules and **CMP-DOC-04** for system behaviour. | README §10; CMP-DOC-04 §2.3 |
| What happens when two documents conflict? | Record it in `Document_Change_Log.md` §5, explain the impact, request a Project Owner decision. **The earlier document in the chain prevails until a decision is taken.** Do not silently modify. | README §10 |
| Which are generated artefacts? | All 23 `.docx`; `CMP-Implementation-Tracker.docx`; sheets `01_Dashboard`, `03_Feature_Summary`, `12_Document_Traceability`. | Index §3A |
| Which are working artefacts? | `CMP-Implementation-Tracker.xlsx` sheets `02` and `04`–`11` (Project-Owner maintained). | Index §3A |
| Which may Claude modify? | **None of `Document/` without instruction.** Claude may write source code and its own scratch files. | README §8.1; this audit §18 |
| Which require human approval? | Every document in `Document/`. No document may be marked `Approved` without explicit Project Owner approval. | README §8.5 |

> **Governance hazard.** README §8.1 states documents are created **only** when explicitly
> requested, and requesting one never authorises another. Claude must not create
> documentation as a side effect of implementation.

---

## 4. Requirements Readiness — `READY WITH CONDITIONS`

### 4.1 Mechanical integrity — clean

Every identifier prefix was checked for contiguity, duplicates and out-of-range citation
across all 28 Markdown sources.

| Check | Result |
|---|---|
| Ranges checked | 20 (`BAD-BR` … `TR`) |
| Identifiers declared | 3,813 |
| **Gaps within a declared range** | **0** |
| **Duplicate identifiers** | **0** |
| **Citations above a declared maximum (dangling)** | **0** |

This is the strongest single finding in the audit. Requirement hygiene is not the problem.

### 4.2 Substantive problems

| Requirement | Problem | Impact | Resolution | Status |
|---|---|---|---|---|
| `FRD-FR-023` | Disclosure list content undefined | Profile cannot state what counterparties see | `BAD-DEC-022` | BLOCKED |
| `FRD-FR-030`/`031` | Driver eligibility criteria undefined | Role assumption unimplementable | `BAD-DEC-005` | BLOCKED |
| `FRD-FR-036` | Source and evidencing of lawful capacity undefined | Capacity column has no authority | `BAD-DEC-005` | BLOCKED |
| `FRD-FR-038` | Duplicate-vehicle policy undefined | Refusal rule unimplementable | `FRD-OQ-004` | BLOCKED |
| `FRD-FR-042` | "Disqualifying" amendments not enumerated | Amendment guard undefined | `FRD-OQ-005` | BLOCKED |
| `FRD-FR-010`/`011` | Attempt limit and cool-off unset | Obligation testable only once a value exists | `FRD-OQ-003` | BLOCKED |
| `FRD-FR-070` | Refusal stated with **no alternative path** | Driver with confirmed bookings is trapped | `BAD-DEC-009` | **DEFECT** |
| `FRD-FR-108`/`139` | Return of value initiated, never defined | Platform can hold money it cannot return | `BAD-DEC-010` | **DEFECT** |
| `FRD-FR-083` | Seat exclusion carried by **no downstream statement** | Coarse filter may omit the rule | `GAP-020` | BLOCKED |
| `FRD-FR-159` | Pickup/drop sequencing undefined | Unimplementable for multi-passenger trips — the product premise | `GAP-017` | BLOCKED |
| `NFR-138` ‡ | Rules-of-participation agreement **not carried forward** | No requirement, operation or table records consent | `GAP-018` | BLOCKED |
| `SRS-REQ-128` ‡ | Requires actor **and element**; `BE-107`/`DB` §9.1 carry actor only | Audit record under-specified | `GAP-019` | BLOCKED |
| 69 of 162 `NFR` | Target unset (47 business, 22 technical) | Quality unassessable for those attributes | `BAD-DEC-018` | BLOCKED |

**93 of 162 NFRs are enforceable today** (CMP-DOC-05 §19). 44 derive from absolute rules and
need no target.

### 4.3 Acceptance criteria

Objectively testable: **211 of 260** FRs carry verification method **T** (Test), 23 D, 23 I,
3 A. Every requirement carries a priority and a method (`AC-4`, `AC-5` both Met). Acceptance
criteria are derivable. **This is adequate.**

---

## 5. Business Decision Readiness — `BLOCKED`

| Class | Count | Register |
|---|---|---|
| Business decisions | **24** | CMP-DOC-01 §27 (`BAD-DEC-001`…`024`) |
| Repository gaps | 20 | `Master_Traceability_Matrix.md` §8 |
| Functional gaps | 29 (11 Critical) | CMP-DOC-04 §9.3 |
| Withheld items | 37 | DOC-10 §11.14, DOC-11 §6.11, DOC-12 §17, DOC-17 §15 |
| Sizing decisions | 21 | CMP-DOC-19 §4 |
| Unset `[TBD]` values | 175 (115 business, 60 technical) | across 14 documents |
| Open questions | 191 | per-document registers |

**APPROVED: 0. REJECTED: 0. SUPERSEDED: 0. OPEN: 24 of 24.**

**Four decisions block the majority** (CMP-DOC-20 §8.4): `BAD-DEC-018` (quality targets —
also blocks 11 sizing decisions and every alert threshold), `BAD-DEC-021` (retention),
`BAD-DEC-006` (admin roles), `BAD-DEP-009` (hosting).

**Areas blocked, by decision:**

| Decision | Blocks |
|---|---|
| `BAD-DEC-003` fare model | Ride publishing fare, payment composition, Screen 7, ledger counterparty |
| `BAD-DEC-004` settlement | Driver earnings; entire settlement/payout admin surface |
| `BAD-DEC-005` verification | Driver eligibility, vehicle capacity, **all verification adjudication** |
| `BAD-DEC-006` account states + roles | **Admin surface cannot deploy** (`ADM-168`); safety responder cannot act on an account |
| `BAD-DEC-007` booking model | Driver acceptance, seat holding, payment timing — *the narrow waist* |
| `BAD-DEC-009` cancellation | Driver cancellation (**live defect**), no-show, trip consequences, intervention effects |
| `BAD-DEC-010` refund | Return of value (**live defect**) |
| `BAD-DEC-011` safety response | **SOS must not ship** — launch gate |
| `BAD-DEC-012` ratings | Whole area; reputation unrecoverable per trip completed without it |
| `BAD-DEC-013` rewards | Whole wallet/reward area; uncapped liability if built |
| `BAD-DEC-015` trip state | Trip state machine definition |
| `BAD-DEC-018` quality targets | 69 NFR targets, 11 sizing decisions, all thresholds |
| `BAD-DEC-021` retention | Position history growth **today**, restore procedure, log retention |
| `BAD-DEC-022` privacy | Disclosure, result precision, messaging availability, trip sharing |
| `BAD-DEC-001` legal | Fare/fee design must not be finalised before it |

---

## 6. Dependency Analysis

### 6.1 Recommended development order

Derived from the chain and the tracker's dependency register:

```
0. TECHNICAL DECISIONS (§22 — none is a business decision)
1. FEAT-003 Database foundation ─┬─ FEAT-002 Laravel foundation ─ FEAT-001 Android foundation
2. FEAT-035 API contract  ·  FEAT-032 Backend authority  ·  FEAT-033 Evidential log
3. FEAT-037 Verification infrastructure  →  FEAT-038 Pipeline & environments
4. FEAT-004 Auth → FEAT-005 Verification → FEAT-006 Profile → FEAT-007 Roles
5. FEAT-008 Vehicles → FEAT-009 Publishing → FEAT-010 Preferences
6. FEAT-011 Search & matching  (parallel with 4–5; needs the overlap algorithm decision)
7. FEAT-017 Ledger → FEAT-015 Fare → FEAT-016 Payment verification
8. FEAT-012 Ride detail → FEAT-013 Request → FEAT-014 Booking
9. FEAT-018 Trip → FEAT-019 Tracking → FEAT-020 Estimates → FEAT-021 History
10. FEAT-034 Degradation · FEAT-023 Notifications · FEAT-022 Messaging
11. FEAT-024/025/026 Safety   ·   FEAT-027–031 Admin   ·   FEAT-036 Security · FEAT-039 Ops
```

**Search (FEAT-011) is the only large feature parallelisable early** — it depends on the
publishing data shape, not on identity behaviour.

### 6.2 Detected dependency problems

| Type | Finding |
|---|---|
| **Circular dependencies** | **None found.** The chain is a strict DAG. |
| **Impossible sequencing** | **Yes.** CMP-DOC-20 `TR-126`: release criteria 7, 8 and 11 (obligations passing, structural rules enforced, instrumentation live) **cannot begin** until criteria 2, 3 and 4 are met — there is nothing to deploy onto. |
| **Hidden dependency** | **Yes.** The whole delivery depends on runtime versions, Git policy and static-analysis tooling that appear in **no register**. See §1 Kind 2. |
| **Missing dependency** | `GAP-013` fraud — no requirement, no software element, no architectural component, no owner. Unowned through the entire chain. |
| **Dependency on unresolved decisions** | 21 sizing decisions → **all provisioning** (`OPS-014`, `TR-081`). `BAD-DEP-009` hosting → sizing decision 10 → the other 20. |
| **Supplier dependencies** | 6 unselected: PSP, SMS/OTP, transactional email, hosting, and two further. Six ports are buildable; six adapters are not. |
| **External services** | Google Maps/Places/Routes (publishing, search, tracking, estimates); Firebase FCM/Crashlytics. Neither has a contracted quota, cost ceiling or failure budget. |

---

## 7. Architecture Readiness — `READY WITH CONDITIONS`

**Structure is decided; every tuning value is not.** This is the chain's own position
(CMP-DOC-09 §0.8.3) and the audit confirms it.

| Area | Defined | Verdict |
|---|---|---|
| Components, boundaries, communication, data flow | 148 `ARCH`, 24 ADRs; client/platform/admin/persistence/integration | **READY** |
| Backend pattern, modules, services, authorisation, validation, queues, events, logging, errors | 218 `BE`, 18 BADRs, 9 aggregates, 7 job families, 4 namespaces | **READY** |
| Mobile platform, architecture, navigation, state, networking, storage, auth, errors | 168 `MOB`, 16 MADRs, 5 modules, single-Activity graph | **READY** |
| **Runtime & framework versions** | **Nothing** — 0 version numbers in the chain | **BLOCKED — ARCHITECTURE DECISION REQUIRED** |
| **Static-analysis tooling** | `TC-037` requires 13 build-time rules; **no tool named** | **BLOCKED — ARCHITECTURE DECISION REQUIRED** |
| Hosting, networking, cache, storage, queues (infra) | Properties only; `OPS-096` names no product | **BLOCKED — `BAD-DEP-009`** |
| Monitoring, logging, backups | 18 measurement points named; no product, no threshold, no frequency | **BLOCKED — `BAD-DEC-018`, `BAD-DEC-021`** |
| Route-overlap algorithm | Deliberately unspecified (`BAD-RULE-023`) | **BLOCKED — `ARCH-OQ-001` / `TECH-DEC-01`** |

**10 open architecture questions** (`ARCH-OQ-001`…`010`), of which `ARCH-OQ-001` (overlap
threshold) and `ARCH-OQ-007` (fraud ownership) are material to construction.

---

## 8. Database Readiness — `READY WITH CONDITIONS`

| Aspect | Status | Evidence |
|---|---|---|
| Entities, relationships, 6 domains, ~30 tables | **Defined** | DB §4, §6, §6.10 |
| Keys — dual internal/external, entropy, non-exposure | **Defined** | `DB-021`…`DB-027` |
| Constraints, FK policy, cascade prohibition | **Defined** | `DB-029`, `DB-030`, §15 |
| Seat allocation — narrow lockable row + CHECK | **Defined** | `DADR-05`, §7 |
| Ledger — append-only, double-entry, no stored balance | **Defined** | `DADR-06`, `DADR-07`, §8 |
| Evidential log — append-only, chained, physically non-updatable | **Defined** | §9, `DADR-09` |
| Indexes and access paths | **Defined structurally** | §14.2 |
| Audit fields, timestamps, soft deletion (removal in place) | **Defined** | `DADR-12` |
| State fields via policy configuration, not ENUM | **Defined** | `DB-039` |
| Migrations, seed data, transactions, concurrency | **Defined** | §16; `BE-047`, `BADR-04` |
| **Monetary precision** | **UNSET** — `DB-032`, depends on `GAP-016` | **BLOCKED** |
| **Retention periods (8)** | **UNSET** — §13.2 | **BLOCKED — `BAD-DEC-021`** |
| **Partitioning / sizing** | **UNSET** — §14.5 | **BLOCKED — `GAP-016`** |
| **7 tables withheld** | Wallet, rewards, refunds, disputes, referrals, recurring, fraud | **NOT SPECIFIED** by decision |

> **`op_trip_positions` is the highest-volume table and has no retention rule.** `DB-076`
> and `FRD-RISK-007`: location history accumulates without bound from the first tracked trip.

**Verdict:** the schema can be created **except the money columns** (precision unset) and
**except retention behaviour**. That is enough to start; it is not enough to carry real data.

---

## 9. API Readiness — `READY WITH CONDITIONS`

**55 operations** across 13 resource groups, plus the safety surface, callback surface and
configuration delivery.

| Aspect | Status |
|---|---|
| Endpoints, methods, paths | **Defined** — §11 |
| Authentication and session carriage | **Defined** — §9 |
| Authorisation, relationship-varying representation | **Defined** — `AADR-08` |
| Error model — four branches, four shapes, classification rules | **Defined** — §8 |
| Idempotency — mandatory key on state change | **Defined** — `AADR-04` |
| Versioning — URI path, N and N−1 | **Defined** — `AADR-03` |
| Provenance / as-of marking on every business value | **Defined** — `AADR-09` |
| Refusal reasons identified, not free text | **Defined** — `AADR-14` |
| **Request/response schemas** | **NOT DEFINED** — no field-level schema, no OpenAPI artefact exists |
| **Pagination/limit/window/page size** | **UNSET** — §0.8.4 states no value appears |
| **Rate limiting bounds** | **UNSET** — §15.3 |
| **Date/time format** | Instant required (`API-015`); **no wire format named** |
| **File handling** | Not specified — no upload operation exists |
| **11 resources withheld** | §11.14 |

> **This is the single largest practical gap for parallel mobile/backend work.** The contract
> defines *shape rules* but not *shapes*. Two teams cannot code against it independently.
> **RECOMMENDATION:** produce an OpenAPI document from CMP-DOC-10 as the first API task —
> it is transcription, not decision-making, and it would surface the missing schemas.

### 9.1 Citation defects found in §11

Mechanically cross-checked every `Realises` citation against the actual FRD text.

| Operation | Cites | That requirement actually says | Verdict |
|---|---|---|---|
| `POST /vehicles` | `FRD-FR-030` | *allow a user to take the driver role* | **WRONG** — should be `FRD-FR-033` |
| `POST /searches` | `FRD-FR-090` | *present driver information and verification indicators* | **WRONG** — should be `FRD-FR-073` |
| `POST /trips/{id}/ratings` | `FRD-FR-172` | *record every message against the conversation* | **WRONG** — and the operation itself is withheld (§19 `CONFLICT-01`) |
| `POST /conversations/{id}/messages` | `FRD-FR-176` | *support messaging between multi-passenger participants* | **WRONG** — should be `FRD-FR-169` |

The check was deliberately conservative (keyword-matched by resource). Several further
citations are plausibly off-by-one within the right subject — e.g. `POST /trips/{id}/start`
cites `FRD-FR-142` (the *refusal*) rather than `FRD-FR-141` (the *permission*). **The
`Realises` column of CMP-DOC-10 §11 must not be trusted without checking CMP-DOC-04.**

---

## 10. Feature Readiness Matrix

Computed from the implementation register. A feature is **BLOCKED** if it contains at least
one work item that cannot start; most blocked features are only *partially* blocked, so the
blocked/total ratio is shown.

| Feature | Blocked/Total | Status | Blocking references |
|---|---|---|---|
| FEAT-001 Project Foundation — Android | 1/20 | **BLOCKED** | `MOB-OQ-001`, `MOB-OQ-002`, `GAP-015` |
| FEAT-002 Project Foundation — Laravel | 0/17 | READY WITH CONDITIONS | `BAD-DEC-015` (state definitions) |
| FEAT-003 Project Foundation — Database | 0/13 | READY WITH CONDITIONS | `GAP-016` (monetary precision) |
| FEAT-004 Authentication & Session | 0/15 | **READY** | — |
| FEAT-005 Registration & Phone Verification | 2/17 | **BLOCKED** | `FRD-OQ-003`, SMS provider unselected |
| FEAT-006 Profile & Counterparty Disclosure | 1/12 | **BLOCKED** | `BAD-DEC-022`, `FRD-GAP-001` |
| FEAT-007 Roles & Authorisation | 1/8 | **BLOCKED** | `BAD-DEC-005`, `FRD-GAP-002` |
| FEAT-008 Vehicle Management | 0/12 | READY WITH CONDITIONS | `BAD-DEC-005`, `FRD-OQ-004`, `FRD-OQ-005` |
| FEAT-009 Ride Publishing | 0/25 | READY WITH CONDITIONS | `BAD-DEC-003`, `BAD-DEC-009`, `FRD-GAP-005/006` |
| FEAT-010 Ride Preferences | 0/7 | **READY** | — |
| FEAT-011 Ride Search & Route Matching | 0/25 | READY WITH CONDITIONS | overlap algorithm, `GAP-020`, `BAD-OQ-007` |
| FEAT-012 Ride Detail & Commitment Surface | 1/10 | **BLOCKED** | `BAD-DEC-022`, `BAD-DEC-003`, `BAD-DEC-007` |
| FEAT-013 Ride Request | 0/13 | READY WITH CONDITIONS | `BAD-DEC-007`, `FRD-GAP-009` |
| FEAT-014 Booking & Seat Allocation | 0/22 | READY WITH CONDITIONS | `BAD-DEC-009/010`, `FRD-GAP-012/014` |
| FEAT-015 Fare & Payment Initiation | 0/13 | **READY** | — (composition itself is withheld, not a work item) |
| FEAT-016 Payment Verification & Reconciliation | 1/17 | **BLOCKED** | `BAD-DEP-004` PSP unselected |
| FEAT-017 Ledger | 0/6 | **READY** | — |
| FEAT-018 Trip Execution | 0/12 | **READY** | — |
| FEAT-019 Live Position Tracking | 1/15 | **BLOCKED** | `BAD-DEC-021`, `FRD-OQ-009` |
| FEAT-020 Estimates & Multi-Passenger Trip | 0/11 | READY WITH CONDITIONS | `GAP-017`, `FRD-OQ-007` |
| FEAT-021 Trip History & Records | 0/5 | **READY** | — |
| FEAT-022 Messaging | 0/15 | **READY** | — (availability point withheld, not a work item) |
| FEAT-023 Notifications | 0/21 | READY WITH CONDITIONS | `BAD-DEC-013` (reward category must issue nothing) |
| FEAT-024 Emergency Contacts | 0/6 | **READY** | — |
| FEAT-025 Safety Incident Pipeline | 0/11 | **READY** | — |
| FEAT-026 Safety Centre & Safety Surface | 0/10 | **READY** | — SOS excluded by `FRD-FR-195` |
| FEAT-027 Admin Foundation & Projections | 0/17 | READY WITH CONDITIONS | `BAD-DEC-006` — **cannot deploy** without one role |
| FEAT-028 Admin Inspection & Intervention | 0/13 | READY WITH CONDITIONS | `FRD-OQ-006` |
| FEAT-029 Admin Payment Reconciliation | 0/7 | READY WITH CONDITIONS | `GAP-009` |
| FEAT-030 Admin Safety & Support | 0/13 | READY WITH CONDITIONS | `BAD-DEC-012` |
| FEAT-031 Admin Operational Reporting | 0/5 | **READY** | — |
| FEAT-032 Backend Authority & Idempotency | 0/9 | READY WITH CONDITIONS | `FRD-OQ-010` (cache discard vs refresh) |
| FEAT-033 Evidential Log & Audit | 0/12 | **READY** | — |
| FEAT-034 Degraded Operation & Configuration | 0/10 | **READY** | — |
| FEAT-035 API Contract & Versioning | 0/11 | **READY** | — |
| FEAT-036 Security Controls | 0/14 | READY WITH CONDITIONS | `BAD-DEC-018`, `BAD-DEP-009`, `FRD-OQ-003` |
| FEAT-037 Verification & Test Infrastructure | 1/16 | **BLOCKED** | `MOB-OQ-002` accessibility standard |
| FEAT-038 Build, Pipeline & Environments | 1/16 | **BLOCKED** | `BAD-DEC-021`, `BAD-DEP-008`, `GAP-016` |
| FEAT-039 Observability & Operations | 1/6 | **BLOCKED** | `SEC-OQ-07` incident ownership |
| FEAT-040 Ratings & Reviews | — | **NOT SPECIFIED** | `BAD-DEC-012` — zero requirements |
| FEAT-041 Wallet & Rewards | — | **NOT SPECIFIED** | `BAD-DEC-013` — zero requirements |
| FEAT-042 Recurring Commute | — | **NOT SPECIFIED** | `BAD-DEC-008` — zero requirements |
| FEAT-043 Cancellation / Refund / Settlement | — | **WITHDRAWN** | `BAD-DEC-009/010/003/004` |
| FEAT-044 Verification Adjudication / Account State | — | **WITHDRAWN** | `BAD-DEC-005/006/016/021` |
| FEAT-045 Project Decisions & Preconditions | — | **WITHDRAWN** | not implementation work |

**Tally: 14 READY · 15 READY WITH CONDITIONS · 10 BLOCKED · 3 NOT SPECIFIED · 3 WITHDRAWN.**

---

## 11. Implementation Tracker Audit

| Check | Result |
|---|---|
| Every task has a unique ID | **PASS** — 507 unique `CMP-IMP-nnn`, 0 duplicates |
| Every task traces to a requirement | **PASS** — every row carries a Requirement ID; `TBD` appears nowhere in that column |
| Feature grouping correct | **PASS** — 39 features, each mapping to a documented functional area |
| Dependencies present | **PASS** — feature-level prerequisites plus 57 entries on `10_Dependencies` |
| Blocked work correctly marked | **PASS** — 11 Blocked, each naming its blocker |
| Withdrawn work excluded | **PASS** — 55 IDs withdrawn, vacant, never reissued; itemised in the analysis §24 |
| No task falsely marked completed | **PASS** — 496 `Not Started`, 11 `Blocked`, **0 in any other status** |
| Git fields not fabricated | **PASS** — Commit Hash and Push Reference are `TBD` on all 507 rows |
| Testing fields not fabricated | **PASS** — Test Result `Not Tested` on all 507 rows; `07_Testing` empty |
| Task ordering logical | **PASS with one caveat** — the Day 1–15 allocation is *dependency-ordered, not capacity-fitted*; 2,540 h in a 15-day window implies ~21 engineers, and no team size exists (`BAD-CON-017`) |

**No execution status was changed by this audit.**

> **One structural caveat.** The tracker's day allocation presumes the technical decisions in
> §22 are already taken. Day 1 items — *create the Android project*, *create the Laravel
> project* — cannot actually be executed today. The plan is sound; its starting line is not
> yet reachable.

---

## 12. Testing Readiness — `READY WITH CONDITIONS`

| Aspect | Status |
|---|---|
| Strategy — six levels, ordered, fail-stops-later | **Defined** — `TC-025`…`TC-028` |
| Level ownership rule (earliest conclusive level owns) | **Defined** — `TC-033`…`TC-036` |
| Unit/domain — no DB, framework or network | **Defined** — `TC-029` |
| Integration — real database, never in-memory | **Defined** — `TC-030` |
| Contract — shape not behaviour | **Defined** — `TC-031` |
| Database constraint testing | **Defined** — §9 |
| Concurrency under genuine parallelism | **Defined** — `TADR-08` |
| Induced failure; hooks absent from production | **Defined** — `TADR-09`, `OPS-099` |
| Security verification | **Defined** — §10 |
| Deployment verification / smoke | **Defined structurally** — gates 3–4 |
| Regression | Implicit in gate composition; **not separately specified** |
| Performance | **BLOCKED** — no target exists (`TC-196`, `GAP-012`) |
| **Accessibility** | **BLOCKED** — conformance standard unchosen (`MOB-OQ-002`) |
| **`TC-017` ‡ mapping** | **NOT STARTED** — release criterion 6; 1,412 ‡ statements unmapped to obligations |
| **Static-analysis tool** | **BLOCKED** — 13 rules mandated, no tool named |

**Coverage discipline is exemplary:** `TC-012`/`TC-020` forbid a coverage percentage as a
gate; 25 obligations are non-suppressible at gate 4 with no suppression path.

---

## 13. Security Readiness — `READY WITH CONDITIONS`

| Aspect | Status |
|---|---|
| Trust boundaries (4), positional defence | **Defined** — §4, `SADR-01` |
| Authentication mechanism, attempt bounding, credential storage | **Defined** — §5 (limits unset) |
| Session management, non-replayable material | **Defined** — §6, `SADR-04` |
| Authorisation — single path, deny by default | **Defined** — `SADR-06` |
| Disclosure rules by relationship | **Defined** — §7.3 (scopes open: `SEC-OQ-08`) |
| Protection at rest — per column | **Defined** — `SADR-05` |
| Protection in transit | **Defined as property**; cipher/protocol **unset** (`OPS-049`) |
| Injection prevention by construction | **Defined** — `SADR-09` |
| Payment credentials never received | **Defined** — `SADR-10` |
| Client-side posture | **Defined** — `SADR-13` |
| Secrets — injected at deploy, never in artefact | **Defined** — `SADR-14` (rotation periods unset) |
| Audit logging as evidential records | **Defined** — `SADR-15` |
| Abuse / rate limiting | **Defined as mechanism**; all bounds unset |
| **Roles & permissions** | **BLOCKED** — 12 capabilities defined, **no role defined** (`ADM-166`) |
| **Regulatory regime** | **BLOCKED** — `SEC-OQ-01`: which regimes apply is unanswered, *no default assumed* |
| **Breach notification** | **BLOCKED** — `SEC-OQ-02` |
| **Data residency** | **BLOCKED** — `SEC-OQ-03` |
| **Penetration testing scope** | **BLOCKED** — `SEC-OQ-04` |
| **Account recovery** | **BLOCKED** — `SEC-OQ-05` |
| **Fraud** | **UNOWNED** — `GAP-013`, no requirement/element/component/owner |

> **`SEC-OQ-01` is the most serious single omission in the audit.** A platform moving money in
> India, carrying passengers, holding location history and personal data, does not know which
> regulatory regimes apply to it. Every retention, residency and notification decision
> downstream of it is consequently unanswerable.

---

## 14. Git / GitHub Readiness — `BLOCKED`

**Nothing is defined. Not one item.**

Full-text search across all 28 Markdown sources:

| Term | Occurrences in the specification chain |
|---|---|
| "GitHub" | **0** |
| "pull request" | **0** |
| "git" (as version control) | **0** — the single hit is inside a planning artefact |
| "conventional commit" / "semantic version" | **0** |
| "repository strategy" / "monorepo" | **0** |
| "branch" | 72 — **all** are the API four-branch error model |
| "commit" | 189 — **all** are database transaction commits or the UX commitment surface |
| "merge" | 146 — **all** are substring hits on "emergency"; gate 2 is *named* Merge but defines no merge policy |

| Required | Defined? |
|---|---|
| Repository strategy, branch strategy, branch naming | **NO** |
| Commit convention, PR process, review requirements | **NO** |
| Merge strategy, protected branches | **NO** |
| Tags, releases, versioning scheme, rollback | Release *gates* exist (`OPS-103`); **no tagging or versioning scheme** |
| GitHub Actions, secrets, environments | **NO** |

**Can Claude Code safely perform local → commit → push → PR → merge → release?**
**NO.** Every step would require inventing project policy. This is the most complete
absence found in the audit and, unlike the business decisions, **it is not recorded as a gap
anywhere** — the chain does not know it is missing.

---

## 15. CI/CD Readiness — `BLOCKED`

| Aspect | Status |
|---|---|
| Gates — four, each running every level below, no bypass, no emergency path | **Defined** — `OPS-089`…`OPS-096`, `OPS-105` |
| Build → artefact per unit, promoted unchanged, bit-for-bit | **Defined** — `OPS-097`, `OPS-030` |
| Artefact contains no env value or secret; hooks absent | **Defined** — `OPS-098`, `OPS-099` |
| Deployment conditions, environment promotion, rollback as promotion | **Defined** — `OPS-029`…`OPS-033` |
| Failed-pipeline behaviour | **Defined** — failure blocks progression; partial deploy is a failed deploy (`OPS-104`) |
| Approvals | Promotion authoriser recorded (`OPS-032`); **approval policy undefined** |
| **Pipeline product** | **NONE NAMED** — `OPS-096` |
| **Lint / static analysis tool** | **NONE NAMED** |
| **Test runner, build tool** | **NONE NAMED** |
| **Secrets store product** | **NONE NAMED** |
| **Commit-to-verified-build bound** | **UNSET** — `NFR-107` |
| **Dependency currency policy** | **UNSET** — `NFR-111` |

The pipeline is specified as **properties a pipeline must have**, deliberately and
defensibly (`DDR-01`). It is not executable, and cannot become executable until a product is
selected and the tooling in §22 is chosen.

---

## 16. Deployment Readiness — `DEPLOYMENT BLOCKED`

| Aspect | Status |
|---|---|
| Four environments, distinct secrets, no production data outside production | **Defined** — `OPS-021`…`OPS-028` |
| Five deployment units, independently deployable/restartable | **Defined** — `OPS-037` |
| Stateless application unit; exactly one scheduler | **Defined** — `OPS-038`, `OPS-039` |
| Safety worker isolation; two routed prefixes | **Defined** — `OPS-040`, `OPS-045`…`OPS-047` |
| Migrations in deploy; no migration rollback | **Defined** — `OPS-034` |
| Database accounts and grants | **Defined** — §11.2 |
| Health endpoint | `GET /health` exists (`BE-203`); **no probe/threshold definition** |
| **Hosting, infrastructure, networking** | **BLOCKED** — `BAD-DEP-009` |
| **All 21 sizing decisions** | **OPEN** — `OPS-014`, `TR-081`: blocks *all* provisioning |
| **DNS** | **NOT MENTIONED ANYWHERE** |
| **SSL/TLS certificate management** | **NOT MENTIONED** — only the transport property (`SEC-091`) |
| **Environment variables** | **NOT ENUMERATED** — no inventory, no `.env` example |
| **Backups** | Structure only; frequency, retention, RPO/RTO **all unset** |
| **Restore-versus-retention procedure** | **CANNOT BE COMPLETED** — `OPS-189` |
| **Smoke tests / deployment verification** | Gate 4 exists; **no smoke-test definition** |
| **Admin surface deployability** | **CANNOT DEPLOY** — needs at least one role (`ADM-168`) |

**Verdict: `DEPLOYMENT BLOCKED`.** Reasons, exactly: (1) no hosting provider; (2) 21 of 21
sizing decisions open; (3) no DNS or certificate management; (4) no environment variable
inventory; (5) retention periods unset, so the restore procedure cannot complete; (6) no
administrative role exists, so the admin unit cannot start; (7) no CI/CD product; (8) no
runtime versions.

---

## 17. Operations Readiness — `BLOCKED`

| Aspect | Status |
|---|---|
| Instrumentation — 18 platform + 9 mobile measurement points | **Defined**, required from first release (`OPS-119`) |
| What must not be confused (health vs readiness) | **Defined** — §12.3 |
| Artefact identity in logs | **Defined** — `OPS-100` |
| Break-glass access | **Defined structurally**; authorisation **blocked** by `BAD-DEC-006` |
| **Alerting thresholds** | **UNSET** — `BAD-DEC-018` |
| **Incident response** | **NOT COMPLETABLE** — no owner (`SEC-OQ-07`), no notification obligations (`SEC-OQ-02`) |
| **Disaster recovery, RPO/RTO** | **UNSET** — values 5 and 6 of `OPS` §15.4 |
| **Backup frequency / data recovery** | **UNSET** |
| **Maintenance windows, availability target** | **UNSET** |
| **Operational log retention** | **UNSET** — `BAD-DEC-021` |
| Troubleshooting runbooks | **NOT SPECIFIED** |

`DDR-11` — *instrument first, threshold later* — is a sound position. It does not make the
platform operable; it makes it measurable once deployed.

---

## 18. AI / Claude Code Readiness — `BLOCKED`

The decisive audit question: **what would Claude have to guess?**

| Claude needs to know | Defined? | If it guessed |
|---|---|---|
| What it may modify | **Partially** — README §8.1 governs documents; **nothing governs source code** | Could edit governed docs as a side effect |
| Source-of-truth hierarchy | **YES** — README §9–10, chain order | — |
| How to handle an OPEN decision | **YES, exceptionally well** — `FRD-RISK-002`, `FR-10`: escalate, never infer | — |
| How to report a blocker | **Partially** — registers exist; no reporting protocol for an implementer | Inconsistent escalation |
| **Runtime and framework versions** | **NO** | Wrong PHP/Laravel/Kotlin baseline; rework of everything |
| **Coding standards, style, naming** | **NO** | Arbitrary conventions, later churn |
| **Static-analysis tooling** | **NO** | The 13 mandated structural rules go unenforced |
| **Test framework** | **NO** | Arbitrary choice |
| **Git rules — branch, commit, PR, merge, tag** | **NO** | Invented VCS policy on the first commit |
| **When to update the tracker** | **Partially** — the model exists; no instruction to the implementer | Status drift |
| Architecture rules | **YES** — layer direction, no business logic in client, one rule one component | — |
| Security rules | **YES for controls**; policy-level regime **NO** | — |
| Deployment rules | **YES as properties**; nothing executable | — |
| Approval requirements | **YES** — every document needs Project Owner approval | — |

**Verdict: `NOT SAFE TO IMPLEMENT`.** Not because the domain is under-specified — it is
better specified than most shipped products — but because the *engineering operating
context* is entirely absent. Claude would guess versions, tooling and Git policy on its very
first action.

`CLAUDE.md` (generated alongside this audit) closes the *behavioural* half of this gap: what
Claude may touch, how to escalate, what never to build. **It cannot close the factual half.**
Six technical decisions must be recorded by a human.

---

## 19. Cross-Document Conflicts

| ID | Document A | Document B | Conflict | Impact | Resolution required from |
|---|---|---|---|---|---|
| **CONFLICT-01** | CMP-DOC-10 §11.9 + CMP-DOC-11 §6.7/§6.10 | CMP-DOC-04 §1.2/§9.2 + CMP-DOC-17 §15 | API specifies `POST /trips/{id}/ratings`; DB specifies `op_trip_ratings`; **FRD records Ratings & Reviews as carrying zero requirements**, withheld pending `BAD-DEC-012` | **A developer following CMP-DOC-10 would build a withheld feature.** Already registered as `CC-025` / `DOC-CONFLICT-01` / `RISK-018` | Documentation Manager, then Project Owner |
| **CONFLICT-02** | CMP-DOC-10 §11 (4 confirmed miscitations) | CMP-DOC-04 register | `Realises` column cites the wrong requirement for `POST /vehicles`, `POST /searches`, `POST /trips/{id}/ratings`, `POST /conversations/{id}/messages` | Implementation against the wrong requirement; false traceability | Software Architect / Backend Lead |
| **CONFLICT-03** | CMP-DOC-12 §4.1 heading ("The Thirty-One Screens") | CMP-DOC-12 table (32 rows) + `UX-001` ("thirty-two screens") | Internal contradiction on screen count | Low — cosmetic, but it is a count in a controlled document | UI/UX Designer |
| **CONFLICT-04** | CMP-DOC-10 §11.6 (`/ride-requests/{id}/acceptance`, `/decline`) | CMP-DOC-04 `FRD-GAP-009` | API defines operations for behaviour the FRD explicitly refuses to specify | Endpoints exist whose business effect is undecided (`API-147` admits this) | Project Owner via `BAD-DEC-007` |
| **CONFLICT-05** | CMP-DOC-10 §11.7 (`/bookings/{id}/cancellation`) | CMP-DOC-04 `FRD-GAP-012` | Cancellation operation exists; its monetary effect does not (`API-151` states this openly) | The endpoint is buildable and meaningless until `BAD-DEC-009`/`010` | Project Owner |
| **CONFLICT-06** | `SRS-REQ-128` (actor **and element**) | `BE-107`, CMP-DOC-11 §9.1 (actor only) | Audit record content disagrees between SRS and its realisers | Blocks baselining of CMP-DOC-06; registered as `GAP-019` | Solution Architect |
| **CONFLICT-07** | `ADR-03` decision prose (seats in coarse filter) | `ARCH-052`, `DB-053`, `DB-194` (spatial only) | No numbered statement carries the seat-availability exclusion | Search may return unbookable rides; registered as `GAP-020` | Solution Architect / Backend Lead |

> **CONFLICT-01, -04 and -05 share a root cause.** CMP-DOC-10 was written to the *shape* of
> the journey rather than to the *decided* set of requirements, so it specifies interfaces for
> three areas the FRD withholds. This is a systemic pattern, not three coincidences, and the
> API document should be re-reviewed against CMP-DOC-04 §9 in full.

---

## 20. Traceability Gaps

Chain: `BAD-BR → BRD-REQ → UC → FRD-FR → SRS-REQ → ARCH → MOB/BE → API/DB → TC → commit → release → verification`

| Link | Status |
|---|---|
| `BAD-BR → BRD-REQ` | **Complete** — 78/78 |
| `BRD-REQ → UC` | **Complete** — 184 realised + 4 justified = 188/188 |
| `UC → FRD-FR` | **Deliberately partial** — 50 of 83; 33 Outlined |
| `FRD-FR → SRS-REQ` | **Complete** — 260/260 allocated |
| `NFR → SRS-REQ` | **Complete** — 162/162 |
| `SRS-REQ → ARCH` | Partial — 96 of 184 realised by a named statement |
| `ARCH → MOB/BE` | Partial — 70 of 148; **14/14 client and 24/24 backend obligations covered** |
| `MOB/BE → API/DB` | **`TRACEABILITY: TBD`** — never recorded |
| `API/DB → TC` | **`TRACEABILITY: TBD`** — `TC-017` not started |
| `TC → commit` | **BROKEN — no Git policy exists** |
| `commit → release` | **BROKEN — no versioning or tagging scheme** |
| `release → deployment verification` | **BROKEN — no smoke-test definition** |
| **Backward traceability matrix** | **EMPTY** — `Master_Traceability_Matrix.md` §6 |

**208 requirements are uncited downstream, 37 of them integrity-critical** (CMP-DOC-20 §6);
31 reviewed by CMP-DOC-20A, **146 outstanding**. That is release criterion 5.

**The last three links of the chain do not exist at all.** Traceability stops at the
specification boundary.

---

## 21. Critical Blockers

Ordered by what they stop.

### 21.1 Stop the first commit (unrecorded — not in any register)

| # | Blocker | Consequence |
|---|---|---|
| B1 | **No runtime/framework versions** | No project can be created |
| B2 | **No Git/GitHub policy** | Nothing can be committed, branched, reviewed or released |
| B3 | **No static-analysis tooling** | 13 mandated structural rules unenforceable; gate 1 cannot exist |
| B4 | **No test framework named** | Level 2–5 infrastructure unbuildable |
| B5 | **No monetary precision** (`DB-032`) | Money columns cannot be created |
| B6 | **No environment variable inventory** | No environment can be configured |

### 21.2 Stop features (fully registered)

| # | Blocker | Stops |
|---|---|---|
| B7 | `BAD-DEC-007` booking model | The request→booking→payment transition — the core journey |
| B8 | `BAD-DEC-003` fare model | Publishing fare, payment composition, Screen 7 |
| B9 | `BAD-DEC-005` verification policy | Driver eligibility, vehicle capacity, all adjudication |
| B10 | `BAD-DEC-022` privacy boundaries | Disclosure, result precision, messaging availability, trip sharing |
| B11 | Route-overlap algorithm | The platform's differentiating capability |

### 21.3 Live product defects (reachable through specified behaviour)

| # | Defect | Consequence |
|---|---|---|
| **B12** | `FRD-GAP-006` / `GAP-008` — driver cancellation | **A driver with confirmed bookings is trapped; passengers get no notice** |
| **B13** | `FRD-GAP-014` / `GAP-009` — return of value | **The platform can hold a passenger's money with no specified way to return it** |

Neither requires engineering to resolve. Both must be closed before a real passenger books.

### 21.4 Stop deployment

| # | Blocker |
|---|---|
| B14 | `BAD-DEP-009` hosting unselected → all 21 sizing decisions → all provisioning |
| B15 | `BAD-DEC-021` retention → restore procedure cannot complete; location history unbounded **today** |
| B16 | `BAD-DEC-006` admin roles → admin unit cannot start (`ADM-168`) |
| B17 | No DNS, no certificate management, no smoke tests |

### 21.5 Compliance and safety

| # | Blocker |
|---|---|
| B18 | `SEC-OQ-01` — **which regulatory regimes apply is unknown** |
| B19 | `BAD-DEC-001` — legal opinion on the operating model not obtained |
| B20 | `BAD-DEC-011` — SOS must not ship; response capability unstaffed |
| B21 | `GAP-013` — fraud unowned through the entire chain |
| B22 | `GAP-018` — no record that a user agreed to the rules of participation (`NFR-138` ‡) |

---

## 22. Required Human Decisions

### 22.1 Technical — unblock the starting line (no product judgement needed)

**These six are the cheapest, highest-leverage actions available. None is a business
decision. Together they move 14 features from unstartable to buildable.**

| # | Decision | Owner |
|---|---|---|
| T1 | **Runtime and framework versions** — PHP, Laravel, Filament, Kotlin, JDK, Gradle, Compose, MySQL (must enforce `CHECK`, per `OPS-024`) | Solution Architect |
| T2 | **Git/GitHub policy** — repository layout, branch strategy and naming, commit convention, PR and review requirements, merge strategy, protected branches, tag and version scheme | Delivery Lead |
| T3 | **Static-analysis and lint tooling** for both stacks, able to enforce the 13 structural rules of `TC-037` | Solution Architect |
| T4 | **Test frameworks** for domain, integration, contract and client levels | QA Analyst |
| T5 | **Monetary precision** (`DB-032`) — needs launch-scale figures (`GAP-016`) | Solution Architect + Product Owner |
| T6 | **Route-overlap algorithm and minimum threshold** (`ARCH-OQ-001`, `TECH-DEC-01`) — route to CMP-DOC-07 before anyone implements `FRD-FR-077` | Solution Architect |

Also technical, needed slightly later: supported Android device range (`MOB-OQ-001`),
accessibility standard (`MOB-OQ-002`), verification attempt limits (`FRD-OQ-003`),
incident-response ownership (`SEC-OQ-07`).

### 22.2 Business — Project Owner only

| Priority | Decision | Unblocks |
|---|---|---|
| **1** | `BAD-DEC-009` + `BAD-DEC-010` | The two live defects. **Neither needs engineering.** |
| **2** | `BAD-DEC-007` booking model | Three critical gaps; the core journey |
| **3** | `SEC-OQ-01` regulatory regime | Retention, residency, notification — everything compliance-shaped |
| **4** | `BAD-DEC-001` legal opinion | MVP precondition; `BAD-DEC-003` must not precede it |
| **5** | `BAD-DEC-018` quality targets | 69 NFR targets, 11 sizing decisions, all thresholds |
| **6** | `BAD-DEP-009` hosting | All provisioning |
| **7** | `BAD-DEC-005` verification policy | Eligibility, capacity, adjudication |
| **8** | `BAD-DEC-021` retention | Restore procedure; unbounded location history |
| **9** | `BAD-DEC-006` account states + admin roles | Admin deployability |
| **10** | `BAD-DEC-003` + `BAD-DEC-004` fare and settlement | Money model |
| **11** | `BAD-DEC-011` safety response + staffing | Launch gate |
| **12** | `BAD-DEC-022` privacy boundaries | Four gaps |
| **13** | `BAD-DEC-012` / `013` / `008` | Three whole functional areas |
| **14** | `GAP-013` fraud ownership | Assign before money moves |
| **15** | Approve CMP-DOC-01 … CMP-DOC-20 in chain order | Everything |

---

## 23. Development Gate

### Can Claude Code safely begin implementation today?

# NO

**Exact blockers:** B1–B6 (§21.1). Every one is a technical decision, none is recorded as a
gap in any register, and all six are needed before the first file is written.

The business blockers (B7–B11) do **not** prevent starting — CMP-DOC-04 `FR-02` correctly
observes that ~180 requirements have no open dependency. They prevent *finishing features*.

### First Implementable Feature

**`FEAT-033` Evidential Log & Audit**, jointly with **`FEAT-032` Backend Authority &
Idempotency** and **`FEAT-035` API Contract & Versioning**.

All three derive entirely from CMP-DOC-04 §8 — the 24 cross-cutting requirements that
CMP-DOC-04 §1.4 confirms carry **no dependency on any open business decision**. They are
`READY`, they contain no blocked work item, and every later feature inherits them.

*Preconditions: T1–T4 only. No business decision required.*

Immediately after: `FEAT-004` Authentication, `FEAT-017` Ledger, `FEAT-034` Degraded
Operation — all `READY`.

### First Blocked Feature

**`FEAT-007` Roles & Authorisation** — the earliest feature in dependency order that cannot
be completed without a business decision. `FRD-FR-030`/`031` require driver eligibility
criteria that `BAD-DEC-005` has not defined. Authorisation itself is buildable; *role
assumption* is not.

Earlier still, but blocked by a **supplier** rather than a decision: `FEAT-005` Registration
& Phone Verification — the SMS/OTP provider is unselected, so the port is buildable and the
adapter is not.

---

## 24. Deployment Gate

### Can this project safely be deployed to production on the current documentation?

# NO

**Exact missing documentation and decisions:**

1. **Hosting provider** — `BAD-DEP-009`. Nothing can be provisioned (`OPS-014`).
2. **All 21 sizing decisions** — instance counts, pool sizes, database tier, redundancy,
   backup regime, replicas, cost ceilings, environment count. **21 of 21 open.**
3. **DNS management** — not mentioned anywhere in the chain.
4. **TLS certificate management** — only the transport property is stated; protocol versions
   and cipher selection are unset (`OPS-049`).
5. **Environment variable inventory** — no enumeration, no example file.
6. **Retention periods** — 8 unset; the restore-versus-retention procedure **cannot be
   completed** (`OPS-189`).
7. **Administrative role set** — the admin unit **cannot start** with no role (`ADM-168`).
8. **CI/CD product and pipeline definition** — `OPS-096` names none.
9. **Runtime versions** — nothing to deploy without them.
10. **Smoke tests / deployment verification** — gate 4 exists; its content does not.
11. **Incident response owner** — `SEC-OQ-07`; procedure not completable.
12. **Alert thresholds, RPO, RTO, availability target** — all unset (`BAD-DEC-018`).

**Additionally: 1 of 12 release readiness criteria is met** (CMP-DOC-20 §11.1). Criteria 7, 8
and 11 **cannot begin** until 2, 3 and 4 are met (`TR-126`).

---

## 25. Recommended Next Actions

| # | Action | Owner | Effort | Unblocks |
|---|---|---|---|---|
| 1 | **Take the six technical decisions T1–T6** (§22.1) and record them in a controlled document | Solution Architect + Delivery Lead | Days | **14 READY features become startable** |
| 2 | **Close `BAD-DEC-009` and `BAD-DEC-010`** | Project Owner | Days | The two live product defects. No engineering needed. |
| 3 | **Answer `SEC-OQ-01`** — which regulatory regimes apply | Project Owner + legal | Weeks | Retention, residency, breach notification, `BAD-DEC-021` |
| 4 | **Obtain the legal opinion** (`BAD-DEC-001`) | Project Owner | Weeks | Fare/fee design; MVP precondition 1 |
| 5 | **Re-review CMP-DOC-10 §11 against CMP-DOC-04 §9** and correct the four miscitations and three withheld-area interfaces | Software Architect | Days | CONFLICT-01, -02, -04, -05 |
| 6 | **Produce an OpenAPI document** from CMP-DOC-10 — transcription, not decision-making | Backend Lead | Days | Independent mobile/backend work; surfaces missing schemas |
| 7 | **Resolve `BAD-DEC-007`** | Project Owner | — | The core journey |
| 8 | **Assign fraud ownership** (`GAP-013`) | Security Analyst → Project Owner | Days | Unowned obligation before money moves |
| 9 | **Complete `TC-017`** ‡ mapping | QA Analyst | Weeks | Release criterion 6 |
| 10 | **Begin approval of CMP-DOC-01 … CMP-DOC-20 in chain order** | Project Owner | Weeks | Everything; `TRDR-13` |
| 11 | **Select suppliers** — PSP, SMS/OTP, email, hosting | Project Owner | Weeks | Six adapters; all provisioning |
| 12 | **Start construction on `FEAT-033`, `FEAT-032`, `FEAT-035`** once action 1 completes | Delivery | — | The foundation every feature inherits |

> **Actions 1, 2, 5 and 8 are cheap, need no supplier and no market research, and between
> them remove the two live defects, the systemic API conflict, an unowned security
> obligation, and the entire starting-line blockage.** They are the highest-value week
> available to this project.

---

*End of CLAUDE-READINESS-ANALYSIS.md — audit complete, no source documentation modified.*
