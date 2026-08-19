# CMP Implementation Analysis

**Planning Artefact — Implementation Work Breakdown**

| Field | Value |
|---|---|
| Document ID | CMP-PLAN-IMPL-ANALYSIS |
| Document Name | Implementation Work Breakdown Analysis |
| Project Name | Carpool Mobility Platform |
| Project Code | CMP |
| Version | 0.1 |
| Status | Draft |
| Date | 2026-08-19 |
| Author | Technical Project Management (AI-assisted) |
| Reviewer | [TBD] |
| Approver | Project Owner |
| Classification | Internal |
| Companion artefact | `CMP-Implementation-Tracker.xlsx` (same directory) |
| Source of truth | `Document/` — CMP-DOC-01 … CMP-DOC-20, all v0.1 Draft |

---

## 0. What this document is, and what it is not

This is a **planning artefact**, not a specification. It decomposes the approved
documentation chain into trackable implementation work items and reports what that
decomposition found. It creates no requirement, takes no business decision, and closes no
gap.

Five rules governed the decomposition:

1. **Nothing was invented.** Every work item names the document statement it derives from.
   No missing business decision was supplied, guessed at, or given a provisional value.
2. **A decision is not work.** Business decisions, technical decisions, functional gaps,
   risks and documentation conflicts are **not** implementation work items and do not appear
   in the register. They are carried on `09_Decisions`, `10_Dependencies` and `11_Risks`,
   where they can be tracked without ever being mistaken for something to build.
3. **Withheld areas stay withheld.** CMP-DOC-04 §9.2 records three functional areas as
   carrying **zero** functional requirements. No work item implements any of them, and none
   is stubbed, prototyped, disabled or marked as forthcoming (`ADM-187`, `ADM-191`).
4. **The register starts empty of progress.** Every row is `Not Started` or `Blocked`.
   Nothing is marked `Implemented`, `Committed`, `Pushed`, `Tested`, `Reviewed` or
   `Completed` — analysing a requirement is not implementing it.
5. **Recommendations are labelled.** Engineering tasks not traceable to a numbered statement
   are marked in the Notes column.

> **Why rule 2 matters.** `FRD-RISK-002` is the highest-severity risk in CMP-DOC-04: *a
> developer implements a gap*. A gap sitting in a work-item register, with an ID and an
> assignee column, is an invitation to do exactly that. The register therefore contains
> implementation work and nothing else.

> **FACT.** At the time of this analysis the repository contained **`Document/` and nothing
> else**. No Android project, no Laravel project, no MySQL schema, no build configuration.
> Sections 18 and 19 below are consequences of this, not oversights.

---

## 1. Total features identified

**39 features carrying implementation work**, grouped into 13 epics
(`FEAT-001` … `FEAT-039`).

Six further feature identifiers — `FEAT-040` … `FEAT-045` — are **reserved, not built**. They
name the withheld areas (ratings and reviews, wallet and rewards, recurring commute,
cancellation/refund/settlement, verification adjudication and account state) and the
project-level decision set. **None appears in the implementation register.** Each is carried
where it belongs: on `09_Decisions`, `10_Dependencies` and `11_Risks`.

## 2. Total implementation work items

**507 work items.** Work IDs were issued across the full analysed set (`CMP-IMP-001` …
`CMP-IMP-562`); the 55 IDs belonging to rows that are not implementation work are **withdrawn
and never reissued**, so the register contains 507 rows with 55 vacant identifiers. Section 24
lists every withdrawn ID and where its content now lives.

| Measure | Value |
|---|---|
| Work items in the register | **507** |
| Work IDs issued (contiguous, never reused, never renumbered) | 562 |
| Work IDs withdrawn as not-implementation-work | 55 |
| Estimated effort | **3,802 hours** |
| Status `Not Started` | **496** |
| Status `Blocked` | **11** |
| Status anything else | **0** |
| Distinct source documents cited | 20 |

> Every row starts `Not Started` or `Blocked`. Nothing is `Implemented`, `Committed`,
> `Pushed`, `Tested`, `Reviewed` or `Completed`, and nothing in the workbook sets those
> automatically. Analysis is not progress.

**What `Blocked` means here.** A blocked row is **buildable implementation work whose value or
policy is not yet set** — the obligation exists as a numbered statement, only a parameter is
missing. Example: `FRD-FR-011` requires the platform to stop accepting verification attempts
once the limit is reached; the limit itself is unset (`FRD-OQ-003`). The work item is real;
it is blocked on a value.

A blocked row is **never** a decision, a gap or a conflict. Those are not work.

| The 11 blocked work items | Blocked on |
|---|---|
| Declare the supported Android version range | `MOB-OQ-001` device range unchosen |
| Cease accepting verification attempts at the limit | `FRD-OQ-003` limit and cool-off unset |
| Implement the SMS/OTP provider port and one adapter | Provider unselected |
| Indicate which profile elements are disclosed | `BAD-DEC-022` disclosure list |
| Allow a user to take the driver role subject to eligibility | `BAD-DEC-005` eligibility criteria |
| Apply disclosure rules at the commitment surface | `BAD-DEC-022` location precision |
| Implement the payment provider port and one adapter | `BAD-DEP-004` PSP unselected |
| Apply position privacy scoping and retention marking | `BAD-DEC-021` retention period |
| Establish accessibility verification as a procedure | `MOB-OQ-002` standard unchosen |
| Implement backup and the restore-versus-retention procedure | `BAD-DEC-021` retention periods |
| Establish incident response structure and break-glass access | `SEC-OQ-07` ownership unanswered |

## 3. P0 work items — MVP critical

**433** (85%). Derived from the MVP scope proposed in CMP-DOC-01 §24.2 and from the
integrity-critical (‡) statements, which CMP-DOC-04 §3.5 states are *not subject to descoping*.

## 4. P1 work items — MVP important

**70** (14%). Items whose requirement carries MoSCoW `S` (Should), or which serve a decided
behaviour that the core commute loop can complete without.

## 5. P2 work items — post-MVP

**4** (1%). Explicitly deferred by a document statement — principally `FRD-FR-160`
(rich telemetry, Release R2), multi-passenger messaging, and admin bulk operations and export.

> Priority was derived from documentation, not assigned. Where CMP-DOC-02 priority was
> unavailable the item inherits its feature's priority, and where the business priority is
> genuinely unknown the item is marked `Blocked` rather than guessed.

## 6. Android work items

**86** on platform `Android`, plus a share of the **84** `Cross Platform` items that carry
client-side obligations.

| | Value |
|---|---|
| Android-platform items | 86 |
| Android effort | 669 h |
| Compose screens specified | **32** (CMP-DOC-12 §4.1 — 31 numbered plus Screen 32) |
| Screens marked **Partial** by CMP-DOC-12 | 4 (Screens 7, 13, 14, 24) |
| Screens **withheld** by CMP-DOC-12 §17 | 14 |

## 7. Laravel work items

**256** — the largest platform share, 50% of all work items and 1,780 hours.

This is the correct shape. CMP-DOC-07 makes Laravel the **business authority**; CMP-DOC-08
`MOB-010` forbids the client from containing a business rule, a fare computation, a seat
calculation or a state-transition rule, and `MOB-011` requires that absence to be verified
by build-time inspection.

## 8. API work items

**46** items of work type `API`, covering roughly **60 operations** across the 13 resource
groups of CMP-DOC-10 §11, plus the separate safety surface (§12), the provider callback
surface (§13) and configuration delivery (§14).

**11 API resources are withheld** (CMP-DOC-10 §11.14) — recurring commute, wallet, rewards,
refunds, driver-cancellation consequence, disputes, insurance position, referral, fraud
reporting, emergency dispatch, and the operator surfaces.

## 9. MySQL work items

**35** items, 215 hours, covering the six storage domains of CMP-DOC-11 §4 (`op_`, `led_`,
`ev_`, `proj_`, `mch_`, `cfg_`) and roughly **30 tables** realising the nine aggregates of
CMP-DOC-09 `BADR-02`.

**7 tables are withheld** (CMP-DOC-11 §6.11). CMP-DOC-11 states the reason directly: *a
speculative table acquires foreign keys, then data, then a migration cost — and it encodes a
guess about behaviour nobody has decided.*

## 10. Filament work items

**46** items, 320 hours, across four features: admin foundation and the nine projections,
inspection and intervention, payment reconciliation, and safety and support case handling.

**5 operator capabilities are withheld** (CMP-DOC-17 §15). Two of them are operationally
serious and are carried as risks in §17 below.

## 11. Payment work items

**34 items** across FEAT-015 (fare and initiation), FEAT-016 (verification and reconciliation)
and FEAT-017 (ledger) — 276 hours.

CMP-DOC-04 §5.3 states that **the payment integrity chain is complete and unblocked**:
`FRD-FR-117`, `118`, `124`–`131` and `133` together implement every absolute payment rule and
depend on no open business decision. This analysis agrees, and CMP-DOC-04 `FR-03` recommends
building it first. Two things around it are not buildable: the *composition* of the amount
payable (`BAD-DEC-003`) and the *return* of value (`BAD-DEC-010`).

## 12. GPS / live trip work items

**24 items** across FEAT-019 (position tracking) and FEAT-020 (estimates and multi-passenger
trip) — 221 hours.

CMP-DOC-15 delivers a complete tracking mechanism with **no values set**: cadence, staleness
bound and retention are all configuration, and every one is unset.

## 13. Testing work items

**49** items of work type `Testing`, of which **14** form the verification infrastructure
(FEAT-037) and the remainder are per-feature test suites.

| Verification obligation | Source | Count |
|---|---|---|
| Consolidated verification obligations | CMP-DOC-18 §4 | 99 |
| Non-suppressible at gate 4 | `TC-021`, `TC-200` | **25** |
| Structural rules at gate 1 | `TC-037` | 13 (8 non-suppressible) |
| Integrity-critical statements across the chain | CMP-DOC-20 §4.3 | 1,412 |
| Integrity-critical mapping (`TC-017`) | CMP-DOC-18 | **Not started** |

`TC-017` — mapping every integrity-critical statement to an owning obligation — is release
readiness criterion 6 and is carried in the register at 24 hours. It is analysis work, it
gates gate 4, and no code depends on it.

## 14. DevOps work items

**21 items** across FEAT-038 (build, pipeline, environments) and FEAT-039 (observability) —
217 hours. Four environments, five deployment units, four gates, 18 measurement points.

**None of it can be provisioned.** All 21 sizing decisions are open and the hosting provider
is unselected. CMP-DOC-19 `OPS-014` states this plainly, and CMP-DOC-20 `TR-081` records that
the sizing class blocks everything downstream of it.

## 15. Estimated 15-day workload

| Measure | Items | Effort |
|---|---|---|
| Allocated to Day 1 – Day 15 | **336** | **2,540 h** |
| Deferred to Post-MVP | 161 | 1,184 h |
| Unschedulable (`TBD`, blocked on a value or a supplier) | 10 | 78 h |
| **Total** | **507** | **3,802 h** |

**The day allocation is dependency-ordered, not capacity-fitted.** Day *n* means *this work
becomes possible on day n given what precedes it*, not *this work fits in day n*.

| Day | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 | 9 | 10 | 11 | 12 | 13 | 14 | 15 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Items | 7 | 10 | 12 | 17 | 22 | 24 | 26 | 23 | 26 | 32 | 25 | 38 | 30 | 26 | 18 |
| Hours | 39 | 74 | 92 | 147 | 162 | 193 | 192 | 182 | 194 | 232 | 199 | 268 | 235 | 204 | 127 |

> **2,540 hours over 15 days is approximately 169 hours per day.** At 8 productive hours per
> engineer that implies a team of **about 21**. No team composition, budget or delivery
> horizon has been supplied — `BAD-CON-017` records their absence and `BAD-DEC-002` is the
> decision that would supply them. **The 15-day sprint is therefore a sequence, not a
> commitment**, and should not be reported as one. See §21.

## 16. Major dependencies

Recorded in full on sheet `10_Dependencies` (57 entries). The ones that govern everything else:

| # | Dependency | Type | Gates |
|---|---|---|---|
| 1 | **Approval of CMP-DOC-01 … CMP-DOC-20 in chain order** | Document approval | Everything. Zero of twenty are approved; every document from CMP-DOC-02 onward was produced from unapproved predecessors and records the conflict (`TR-131`). |
| 2 | **`BAD-DEP-009` hosting provider** | Supplier | All provisioning, through sizing decision 10 (`OPS-014`, `TR-081`) |
| 3 | **`BAD-DEC-018` KPI and quality targets** | Business | 69 quality targets, 11 of 21 sizing decisions, every alert threshold, 5 verification categories |
| 4 | **`BAD-DEC-021` retention and closure** | Business | 8 retention periods, the restore-versus-retention procedure, position history growth |
| 5 | **`BAD-DEC-006` administrative role set** | Business | The admin surface cannot deploy until one role exists (`ADM-168`) |
| 6 | **`BAD-DEP-004` payment service provider** | Supplier | The payment adapter. The port is buildable; the adapter is not. |
| 7 | **Route-overlap algorithm** | Technical | FEAT-011, the platform's differentiating capability |
| 8 | **Launch scale figures** (`GAP-016`) | Data | Three backend architectural assumptions; index and partitioning strategy; monetary precision |
| 9 | Google Maps Platform / Places / Routes access | External service | Publishing, search, tracking, estimates |
| 10 | Firebase Cloud Messaging | External service | Notification delivery |

Internal build order is a straight line for the first third: **database and Laravel
foundation → backend authority → evidential log → identity → vehicles → publishing →
search → request → booking → payment**. Search and matching (FEAT-011) is the only large
feature that can be built in parallel with identity, because it depends on publishing data
shape rather than on identity behaviour.

## 17. Major risks

Recorded in full on sheet `11_Risks` (20 entries, seeded from the risk registers of
CMP-DOC-01, CMP-DOC-04 and CMP-DOC-20). The critical ones:

| ID | Risk | Why it is critical |
|---|---|---|
| RISK-002 | **A developer implements a gap.** Faced with undecided behaviour, someone writes a reasonable-looking flow and business policy is set by default. | The highest-severity risk in CMP-DOC-04 (`FRD-RISK-002`). The register carries implementation work only, so no gap can be picked up and built by mistake; the 29 functional gaps live on 09_Decisions. |
| RISK-003 | **The two live product defects ship.** A driver strands passengers; the platform holds money it cannot return. | Both are reachable through *specified* behaviour. Neither needs engineering to fix. |
| RISK-010 | **A structural rule is relaxed under delivery pressure.** | The most common severity-20 risk in the chain, recorded independently by six documents (`TR-087`). |
| RISK-011 | **An unresolved decision is resolved by default rather than decided.** | The second most common (`TR-088`), and §16 makes it likely. |
| RISK-013 | **The 15-day sprint is read as a capacity commitment.** | 2,540 hours in the window; no team size supplied. See §15 and §21. |
| RISK-014 | **Nothing can be provisioned.** | 21 of 21 sizing decisions open. |
| RISK-016 | **A safety responder can close an incident and take no action against the account involved.** | `ADM-196`. A live operational hole, not a missing feature. |
| RISK-019 | **Regulatory characterisation is unknown**, particularly if the platform sets fares or takes a fee. | `BAD-RISK-001`. The legal opinion is MVP entry precondition 1 and is not held. |
| RISK-020 | **A passing test suite is read as readiness.** | `TR-121`. The largest known risks are undecided, not defective. |

## 18. Existing partially implemented functionality

**None. There is no application source code.**

The repository contained only `Document/` at the time of this analysis. Every one of the 507
work items is therefore `Not Started` or `Blocked`; none is `Partially Implemented`,
`Implemented`, `Deprecated` or `Unknown`.

This is consistent with CMP-DOC-20 `TR-132`: *the specification is complete and the platform
does not exist.*

## 19. Mock / placeholder functionality

**None.** There is no mock data, no fake API, no simulated GPS, no mock payment, no
placeholder authentication, no temporary local storage, no hardcoded value and no demo UI —
because there is no code.

There is therefore **no mock-to-production migration work** in this plan, and none should
appear later. Four documented positions make mock substitution actively dangerous here, and
they should be treated as build-time constraints from the first commit:

| Constraint | Statement |
|---|---|
| A UPI application response is **discarded**, not trusted | `FRD-FR-124`, `PADR-03` |
| Payment status is **never** settable through the interface | `API-153`, `DB-066` |
| A position is **never** presented as current beyond its staleness bound | `FRD-FR-150`, `API-161` |
| Failure-induction hooks are **absent** from the production artefact, verified by static analysis | `OPS-099`, `TC-155` |

> **RECOMMENDATION.** Because there is no legacy to migrate, the structural checks
> (FEAT-001, FEAT-002, FEAT-037) should be built in the first three days, before any feature
> code exists to violate them. They are cheap now and expensive to retrofit.

## 20. Recommended MVP cut line

> **Classification: RECOMMENDATION.** The MVP scope itself is `BAD-DEC-020` and is not
> approved. This is a delivery-sequencing recommendation, not a scope decision.

**Build, in this order:**

| # | Body of work | Features | Rationale |
|---|---|---|---|
| 1 | Foundations and structural enforcement | FEAT-001, 002, 003, 037 | The rules are cheap to install and expensive to retrofit. |
| 2 | Backend authority, evidential log, API contract | FEAT-032, 033, 035 | 24 cross-cutting requirements, no open dependency. Every later feature inherits them. |
| 3 | **The payment integrity chain** | FEAT-016, 017, and the unblocked half of FEAT-015 | CMP-DOC-04 `FR-03`: complete, unblocked, and the code that prevents financial loss. |
| 4 | Identity, vehicles, publishing | FEAT-004 – 010 | 34 of 48 requirements are unblocked. |
| 5 | **Search and route matching** | FEAT-011, 012 | 24 requirements, no open dependency, and the platform's differentiator. Route the algorithm decision first. |
| 6 | Safety incident pipeline — **without the SOS control** | FEAT-025 | 16 requirements, fully specified. `FRD-FR-195` keeps the control out, testably. |
| 7 | Notifications, administration | FEAT-023, 027 – 031 | 12 and 28 requirements respectively, no open dependency. |

**Do not build, and do not stub, prototype or flag:**

| Area | Blocked by |
|---|---|
| Ratings and reviews | `BAD-DEC-012` — zero functional requirements |
| Wallet and rewards | `BAD-DEC-013` — zero functional requirements |
| Recurring commute | `BAD-DEC-008` — zero functional requirements |
| Refunds, cancellation consequence, driver settlement | `BAD-DEC-009`, `BAD-DEC-010`, `BAD-DEC-003`, `BAD-DEC-004` |
| The SOS control and the safety response protocol | `BAD-DEC-011` — and a staffed response capability |
| Verification adjudication; account state change | `BAD-DEC-005`, `BAD-DEC-006` |

`ADM-187` and `ADM-191` state the rule that matters here: none of these may be *presented
disabled, hidden behind a role, or marked as forthcoming*, and none may be partially built or
placed behind a flag.

**Cut line for a first release, if one is forced:** everything above the trip surface —
identity through confirmed, paid booking, plus the administrative surface needed to operate
it. Live tracking (FEAT-019, FEAT-020) is the natural cut point, because its retention rule
does not exist and every tracked trip accumulates location history that nobody has decided
how to remove (`FRD-RISK-007`).

## 21. Items that cannot realistically fit in 15 days

**Most of the plan.** Stated precisely:

| Category | Items | Effort | Why |
|---|---|---|---|
| Allocated to Day 1 – 15 by dependency order | 336 | 2,540 h | Requires ~169 h/day — a team of roughly 21 at 8 h/day. No team size exists. |
| Explicitly deferred to Post-MVP | 161 | 1,184 h | Trips, tracking, messaging, notifications, safety, and the whole administrative surface. |
| Unschedulable | 10 | 78 h | Blocked on an unset value or an unselected supplier. No amount of capacity moves them. |

**What a genuinely 15-day-shaped scope looks like**, at a plausible small-team capacity of
5 engineers × 8 h × 15 days ≈ **600 hours**:

| Included | Features | Effort |
|---|---|---|
| Foundations, structural enforcement, verification infrastructure | FEAT-001, 002, 003, 037 | 510 h |
| Backend authority and API contract | FEAT-032, 035 | 152 h |

That is already over budget — and it delivers **no user-visible feature at all**. The honest
conclusion is that a 15-day window at small-team scale buys the *platform skeleton and its
structural guarantees*, and nothing else. Extending to the first user-visible loop
(identity → vehicles → publishing → search) needs roughly **1,300 hours**.

> This is not a criticism of the plan or of the sprint. It is the consequence of a 3,802-hour
> body of specified work meeting an unstated capacity. The value of stating it is that the
> Project Owner can now choose: extend the window, increase the team, or reduce the scope —
> rather than discover the shortfall on Day 12.

**Additionally, and independently of capacity:** criteria 7, 8 and 11 of CMP-DOC-20 §10.1
(non-suppressible obligations passing, structural rules enforced, instrumentation live)
*cannot begin* until criteria 2, 3 and 4 are met, because there is nothing to deploy onto
(`TR-126`).

## 22. Decisions required

Recorded in full on sheet `09_Decisions` (40 entries). Ordered by how much each unblocks:

| Priority | Decision | Unblocks |
|---|---|---|
| **1** | `BAD-DEC-009` cancellation + `BAD-DEC-010` refund | The two live product defects. **Neither requires engineering.** Close before any real passenger books. |
| **2** | `BAD-DEC-007` booking model | Three critical gaps — driver acceptance, seat holding, payment timing. The narrow waist of the product. |
| **3** | `BAD-DEC-018` KPI and quality targets | 69 quality targets, 11 sizing decisions, every alert threshold |
| **4** | `BAD-DEP-009` hosting provider | All provisioning, through the sizing register |
| **5** | `BAD-DEC-005` verification policy | Driver eligibility, vehicle capacity evidencing, the entire adjudication capability |
| **6** | `BAD-DEC-021` retention and closure | Position history growth today; the restore procedure |
| **7** | `BAD-DEC-006` account states and admin roles | The administrative surface cannot deploy without one role |
| **8** | `BAD-DEC-003` fare + `BAD-DEC-004` settlement | Screen 7 cannot state the amount payable |
| **9** | `BAD-DEC-011` safety response protocol | Launch gate. SOS cannot ship. |
| **10** | `BAD-DEC-001` legal opinion | MVP entry precondition 1. `BAD-DEC-003` must not be taken without it. |
| **11** | `BAD-DEC-022` privacy boundaries | Four gaps: disclosure, result precision, messaging availability, trip sharing |
| **12** | `BAD-DEC-012` ratings, `BAD-DEC-013` rewards, `BAD-DEC-008` recurring commute | Three whole functional areas |

**Technical decisions raised or carried by this analysis:**

| ID | Decision | Owner |
|---|---|---|
| `TECH-DEC-01` | The route-overlap algorithm and any minimum overlap threshold | Solution Architect |
| `TECH-DEC-02` | Supported Android device range (`MOB-OQ-001`) | Solution Architect |
| `TECH-DEC-03` | Accessibility conformance standard (`MOB-OQ-002`) | Product Owner |
| `TECH-DEC-04` | Monetary precision (`DB-032`), which needs `GAP-016` | Solution Architect |
| `TECH-DEC-05` | Verification attempt limits and cool-off (`FRD-OQ-003`) | Security Analyst |
| `TECH-DEC-06` | Who owns incident response (`SEC-OQ-07`) | Project Owner |
| `GAP-013` | Fraud detection and response — **unowned through the entire chain** | Security Analyst |
| `GAP-016` | Launch scale figures | Product Owner |
| `GAP-017` | Pickup and drop sequencing on a multi-passenger trip | Product Owner |
| `GAP-018` | How user agreement to the rules of participation is recorded | Solution Architect |
| `GAP-019` | Whether the evidential record carries the originating element | Solution Architect |
| `GAP-020` | Explicit statement of the seat-availability exclusion in search | Backend Lead |

### 22.1 One documentation conflict found during decomposition

> **`DOC-CONFLICT-01` — a ratings interface and table exist for an area with zero requirements.**
>
> CMP-DOC-10 §11.9 lists `POST /trips/{id}/ratings` — *rate a counterparty* — citing
> `FRD-FR-172`, which is in fact a **messaging** requirement (*record every message against
> the conversation for its ride*). CMP-DOC-11 §6.7 and §6.10 list `op_trip_ratings` among the
> tables realising the `Trip` aggregate.
>
> CMP-DOC-04 §1.2 and §9.2 record **Ratings & Reviews as carrying zero functional
> requirements**, withheld pending `BAD-DEC-012`, and CMP-DOC-17 §15 withholds reputation
> records from the support case surface.
>
> **A developer working from CMP-DOC-10 would build a withheld feature.** This is recorded on
> `09_Decisions` and as `RISK-018`. It should be reconciled under change control before
> construction of the trip feature begins. This analysis does not choose which document is
> right.

---

## 23. How the workbook is meant to be used

`CMP-Implementation-Tracker.xlsx` sits beside this document. Thirteen sheets; one master
work-item register; everything on the dashboard and the feature summary is a live formula.

**`02_Work_Items` is implementation work only.** If something cannot be built by an engineer
it is not in that sheet. What is *not* there, and where it lives instead:

| Not a work item | Carried on |
|---|---|
| Business and technical decisions (40 entries) | `09_Decisions` |
| Functional gaps and withheld areas | `09_Decisions`, and named in the Notes of the work they block |
| Supplier selections and document approval | `10_Dependencies` |
| Risks | `11_Risks` |
| The CMP-DOC-10 / CMP-DOC-11 ratings conflict | `09_Decisions` (`DOC-CONFLICT-01`), `11_Risks` (`RISK-018`) |

**The six stages are deliberately separate, and nothing collapses them:**

```
Not Started → In Analysis → Ready → In Progress → Implemented → Committed
            → Pushed → Testing → Tested → Reviewed → Completed
```

`Implemented` means code exists. `Committed` means a Git commit exists. `Pushed` means it
reached the remote. `Tested` means testing completed successfully. `Reviewed` means the
Project Owner or a code review accepted it. `Completed` means the whole lifecycle was
accepted — and **nothing in the workbook sets it automatically.** A work item may be
`Blocked` at any stage.

`TR-121` is the reason the workbook is built this way: *a passing test suite is not
readiness.* Release readiness is assessed against the twelve criteria of CMP-DOC-20 §10.1, of
which **one is currently met**, and any readiness statement must carry the
untested-because-undecided set with it (`TR-119`).

### 23.1 Deviations from the requested workbook specification

Four, all disclosed:

1. `03_Feature_Summary` carries one column beyond those requested — **Other Statuses**
   (`In Analysis`, `Ready`, `Cancelled`) — so that the status columns reconcile against the
   total rather than silently under-counting.
2. The effort column is headed **Estimated Effort (h)** rather than *Estimated Effort*, so
   the unit is unambiguous on the sheet.
3. `04_Daily_Progress` is **pre-seeded** with the 336 Day 1 – Day 15 items rather than left
   empty, so the sheet opens as a usable daily plan. Every owner-maintained column on it is
   blank.
4. `02_Work_Items` has **55 vacant work IDs**. Work IDs were issued across the whole analysed
   set before the not-implementation-work rows were withdrawn, and the ID standard forbids
   reuse and renumbering. The gaps are deliberate and are itemised in §24.

---

## 24. Withdrawn work IDs — what was removed from the register, and where it went

Work IDs were issued across the whole analysed set. **55 of them describe things that cannot
be built**: business decisions, functional gaps, withheld areas and one documentation
conflict. They are withdrawn from `02_Work_Items` and, per the ID standard, **never reissued
and never renumbered** — the register carries 507 rows and 55 vacant identifiers.

Nothing was lost. Every withdrawn item's content is carried on `09_Decisions`,
`10_Dependencies` or `11_Risks`, which is where a project owner acts on it.

| Withdrawn ID | Why it is not work | Subject | Source |
|---|---|---|---|
| `CMP-IMP-139` | Functional gap | Establish the fare on a published ride | `FRD-GAP-005` |
| `CMP-IMP-140` | Functional gap | Amend a published ride after bookings exist | `FRD-GAP-007` |
| `CMP-IMP-196` | Functional gap | Determine whether a ride request requires driver acceptance, and the behaviour of that decision | `FRD-GAP-009` |
| `CMP-IMP-197` | Functional gap | Determine whether requested seats are held pending payment, and for how long | `FRD-GAP-010` |
| `CMP-IMP-198` | Functional gap | Determine whether payment is taken at booking or on trip completion | `FRD-GAP-011` |
| `CMP-IMP-234` | Functional gap | Establish the composition of the amount payable | `FRD-GAP-013` |
| `CMP-IMP-258` | Business or technical decision | Record the unresolved ledger counterparty position | `PAY 12.1` |
| `CMP-IMP-271` | Functional gap | Define the trip state model and its permitted transitions | `FRD-GAP-016` |
| `CMP-IMP-272` | Functional gap | Define the consequence of a trip beginning materially early or late, or ending without completing | `FRD-GAP-017` |
| `CMP-IMP-319` | Functional gap | Determine the point in the journey at which messaging becomes available | `FRD-GAP-019` |
| `CMP-IMP-368` | Functional gap | Record a user agreement to a version of the rules of participation | `GAP-018` |
| `CMP-IMP-369` | Functional gap | Build the SOS control and the safety response protocol | `FRD-GAP-020` |
| `CMP-IMP-370` | Functional gap | Build live trip sharing | `FRD-GAP-021` |
| `CMP-IMP-371` | Functional gap | Build non-emergency safety reporting | `FRD-GAP-022` |
| `CMP-IMP-428` | Functional gap | Set KPI targets and alert thresholds for operational reporting | `BAD-DEC-018` |
| `CMP-IMP-449` | Functional gap | Carry the originating element on every evidential record | `GAP-019` |
| `CMP-IMP-485` | Functional gap | Own fraud detection and response | `GAP-005` |
| `CMP-IMP-518` | Functional gap | Select the hosting provider and resolve the twenty-one sizing decisions | `BAD-DEP-009` |
| `CMP-IMP-526` | Business or technical decision | Set alerting thresholds | `OPS 12.4` |
| `CMP-IMP-527` | Business or technical decision | Set operational log retention | `OPS 12` |
| `CMP-IMP-528` | Business or technical decision | Set the availability and maintenance window position | `OPS 13` |
| `CMP-IMP-529` | Withheld area | Rating, reviewing and reputation behaviour | `CMP-DOC-04 9.2` |
| `CMP-IMP-530` | Documentation conflict | Resolve the conflict: CMP-DOC-10 and CMP-DOC-11 specify a ratings interface and table that CMP-DOC-04 withholds | `API 11.9` |
| `CMP-IMP-531` | Withheld area | Wallet balance, ledger view, credits, debits and transaction history | `CMP-DOC-04 9.2` |
| `CMP-IMP-532` | Withheld area | Reward points, earning, redemption, coupons and history | `CMP-DOC-04 9.2` |
| `CMP-IMP-533` | Withheld area | Operator adjustment of wallet or reward records | `FRD-GAP-026` |
| `CMP-IMP-534` | Withheld area | Recurring commute definition, control and generation | `CMP-DOC-04 9.2` |
| `CMP-IMP-535` | Withheld area | Recurring ride generation as scheduled work | `BE-143` |
| `CMP-IMP-536` | Withheld area | Driver cancellation of a ride carrying confirmed bookings | `FRD-GAP-006` |
| `CMP-IMP-537` | Withheld area | Return of value to a passenger | `FRD-GAP-014` |
| `CMP-IMP-538` | Withheld area | Passenger cancellation, no-show handling and their financial consequences | `FRD-GAP-012` |
| `CMP-IMP-539` | Withheld area | Recording driver earnings and settling funds to drivers | `FRD-GAP-015` |
| `CMP-IMP-540` | Withheld area | Financial consequence of an operator intervention or a support resolution | `FRD-GAP-027` |
| `CMP-IMP-541` | Withheld area | Identity and vehicle verification submission and adjudication | `FRD-GAP-023` |
| `CMP-IMP-542` | Withheld area | Changing a user account state, and any appeal from it | `FRD-GAP-024` |
| `CMP-IMP-543` | Withheld area | Moderating reported content | `FRD-GAP-025` |
| `CMP-IMP-544` | Withheld area | Account closure and the treatment of a closing user data | `FRD-GAP-029` |
| `CMP-IMP-545` | Withheld area | Data retention: what is held, for how long, and how removal reconciles with durability | `FRD-GAP-028` |
| `CMP-IMP-546` | Withheld area | Define the administrative role set from the twelve capabilities | `ADM-166` |
| `CMP-IMP-547` | Project decision or precondition | Approve CMP-DOC-01 through CMP-DOC-20 in chain order | `TR 10.1 criterion 1` |
| `CMP-IMP-548` | Project decision or precondition | Resolve the four decisions that block the majority of outstanding work | `TR 8.4` |
| `CMP-IMP-549` | Project decision or precondition | Resolve BAD-DEC-009 cancellation and BAD-DEC-010 refund before any real passenger books | `FR-01` |
| `CMP-IMP-550` | Project decision or precondition | Resolve BAD-DEC-007 the booking model | `FRD-GAP-009` |
| `CMP-IMP-551` | Project decision or precondition | Resolve BAD-DEC-003 the fare model and BAD-DEC-004 driver settlement | `FRD-GAP-005` |
| `CMP-IMP-552` | Project decision or precondition | Resolve BAD-DEC-005 the verification policy | `FRD-GAP-002` |
| `CMP-IMP-553` | Project decision or precondition | Resolve BAD-DEC-011 the safety response protocol and staff a response capability | `FRD-GAP-020` |
| `CMP-IMP-554` | Project decision or precondition | Resolve BAD-DEC-022 the privacy boundaries between users | `FRD-GAP-001` |
| `CMP-IMP-555` | Project decision or precondition | Obtain the qualified legal opinion on the operating model | `BAD-DEC-001` |
| `CMP-IMP-556` | Project decision or precondition | Select the six unselected suppliers | `TR 10.1 criterion 3` |
| `CMP-IMP-557` | Project decision or precondition | Supply the launch scale figures | `GAP-016` |
| `CMP-IMP-558` | Project decision or precondition | Resolve GAP-017 pickup and drop sequencing on a multi-passenger trip | `GAP-017` |
| `CMP-IMP-559` | Project decision or precondition | Approve or amend the proposed MVP scope | `BAD-DEC-020` |
| `CMP-IMP-560` | Project decision or precondition | Decide what constitutes a release gate beyond the non-suppressible set | `TR-115` |
| `CMP-IMP-561` | Project decision or precondition | Route the route-overlap algorithm to CMP-DOC-07 as a named technical decision | `FR-06` |
| `CMP-IMP-562` | Project decision or precondition | Complete the forward traceability review of the 37 uncited integrity-critical requirements and the 146 remaining | `TR 6` |

**Summary of withdrawals**

| Category | IDs |
|---|---|
| Project decisions and preconditions (`FEAT-045`) | 16 |
| Withheld areas (`FEAT-040` … `FEAT-044`) | 18 |
| Functional gaps embedded in buildable features | 14 |
| Business or technical decisions embedded in buildable features | 6 |
| Documentation conflict | 1 |
| **Total** | **55** |

> **The register got smaller and the plan did not.** Total estimated effort is unchanged at
> **3,802 hours**, because every withdrawn row carried zero effort — there was never anything
> to build in them. What changed is that a developer opening `02_Work_Items` can no longer
> pick up a gap and implement it.

---

*End of CMP Implementation Analysis*
