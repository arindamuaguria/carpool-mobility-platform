# CMP-DOC-20A — Review of the 37 Uncited Integrity-Critical Requirements

**Annex A to CMP-DOC-20 · Carpool Mobility Platform (CMP)**

---

# 0. Document Control

## 0.1 Document Metadata

| Field | Value |
|---|---|
| Document ID | CMP-DOC-20A |
| Document Name | Review of the 37 Uncited Integrity-Critical Requirements |
| Short Name | TRACEABILITY (Annex A) |
| Project Name | Carpool Mobility Platform |
| Project Code | CMP |
| Version | 0.1 |
| Status | Draft |
| Date | 2026-08-17 |
| Author | Documentation Manager (AI-assisted) |
| Reviewer | [TBD] |
| Approver | Project Owner |
| Classification | Internal |
| Brand | TBD |
| Previous Version | None (initial issue) |
| Parent Document | CMP-DOC-20 §6, `TR-042`, `TR-048` |
| Predecessor Documents | CMP-DOC-01 … CMP-DOC-20, **all Draft, none approved** |
| Related Documents | `00_Project_Control/Documentation_Index.md`, `Master_Traceability_Matrix.md` |
| Successor Documents | None |

## 0.2 Purpose

CMP-DOC-20 `TR-042` requires each of the 37 uncited integrity-critical requirements to be
individually reviewed, and `TR-048` requires each to resolve to exactly one outcome, with
**none closed by assertion**. This annex is that review.

It records, for each of the 37, the outcome and the evidence for it. **It creates no
traceability link** — `TRDR-06` reserves that to the authors of the realising documents,
under change control (`TR-051`). What this annex produces is the list of citations those
authors should add, and the gaps that must be registered instead.

## 0.3 Method

| Step | What was done |
|---|---|
| 1 | Recompute the 37 from source rather than transcribe CMP-DOC-20 §6.1 |
| 2 | For each, score every statement in every later document by shared significant vocabulary |
| 3 | Review the ranked candidates by hand and search directly where the ranking was weak |
| 4 | Resolve each cited realiser back to its statement text and confirm it says what is claimed |
| 5 | Assign exactly one outcome per requirement |

**Every realiser named in §2 was resolved to its source text and verified.** None was
written from recollection.

## 0.4 Outcome Categories

CMP-DOC-20 §6.3 defines four. The review found a **fifth**, which §1.1 explains.

| # | Outcome | Action |
|---|---|---|
| **0** | **Already cited, in an abbreviated notation the measure could not see** | Correct the measure, not the documents |
| 1 | Realised, cited elsewhere | Add the citation to the realising statement, under change control |
| 2 | Realised, no statement identifiable | Register a gap |
| 3 | Not realised | Register a gap |
| 4 | Superseded | Record the superseding statement; do not delete the requirement |

---

# 1. Result

## 1.1 The Measure Was Partly Wrong

**FACT, measured.** CMP-DOC-04 records each functional requirement's business-requirement
linkage as **bare three-digit numbers in a dedicated column**, not as `BRD-REQ-nnn`
identifiers:

```
| `FRD-FR-028` ‡ | The system shall present a counterparty's verification
  standing before the user is asked to commit to travel with them. | `UC-007` | 011, 075 | M | D |
                                                                                 ^^^^^^^^
                                                            BRD-REQ-011 and BRD-REQ-075
```

CMP-DOC-04 §0.5 states the convention — each requirement "traces back to a use case
(`UC-nnn`) and through it to a business requirement (`BRD-REQ-nnn`)" — and then records
that linkage in a form no identifier-based measure can see.

**Consequence.** The 254 parsed FRD rows reference **107 distinct business requirements**
by number. Allowing that column:

| Measure | CMP-DOC-20 reported | Corrected |
|---|---|---|
| Uncited upstream requirements | 208 | **177** |
| — integrity-critical | 37 | **31** |
| Uncited `BRD-REQ` | 64 | **33** |
| — integrity-critical | 6 | **0** |

**All six uncited integrity-critical business requirements were already cited.** They are
outcome 0, and the correction belongs in CMP-DOC-20, not in CMP-DOC-02.

This does not overturn `TR-034`. The finding holds for the other 171 and for all 31
remaining integrity-critical requirements, none of which is affected by the abbreviated
column: CMP-DOC-05 and CMP-DOC-06 have no such column, and CMP-DOC-04's own requirements
are cited — or not — by full identifier throughout.

## 1.2 Distribution of Outcomes

| Outcome | Count | Requirements |
|---|---|---|
| **0 — already cited (measurement artefact)** | **6** | All six `BRD-REQ` |
| **1 — realised, cited elsewhere** | **27** | 10 `FRD-FR`, 3 `NFR`, 14 `SRS-REQ` |
| **2 — realised, no statement identifiable** | **2** | `FRD-FR-083`, `SRS-REQ-128` |
| **3 — not realised** | **1** | `NFR-138` |
| **4 — superseded** | **1** | `FRD-FR-243` |
| **Total** | **37** | |

## 1.3 What This Means

**`TR-ASM-02` is confirmed.** CMP-DOC-20 assumed most of the 37 would resolve to
"realised, cited elsewhere". 27 did, and 6 more turned out to be cited already. **33 of 37
are documentation defects of citation, not defects of the platform.**

**Four are not.** Three require a gap to be registered and one requires a supersession to
be recorded:

| Requirement | Outcome | Substance |
|---|---|---|
| `NFR-138` ‡ | **Not realised** | **No document anywhere specifies recording a user's agreement to the rules of participation.** No table, no operation, no screen, no backend statement. `BRD-CMP-008` requires it. |
| `SRS-REQ-128` ‡ | Half-realised | The evidential record captures **actor** but not the **element responsible**; the requirement asks for both. |
| `FRD-FR-083` ‡ | No statement | The seat-availability criterion of the search coarse filter exists only in `ADR-03`'s prose; no numbered statement carries it. |
| `FRD-FR-243` ‡ | Superseded | Client/platform state disagreement cannot arise under the provenance model that replaced it. |

**`NFR-138` is the material finding of this review.** It is integrity-critical, it carries
a compliance character — recorded consent to terms, with the version agreed — and it fell
out of the chain between CMP-DOC-05 and every document after it.

---

# 2. The Review, Requirement by Requirement

## 2.1 Outcome 0 — Already Cited in CMP-DOC-04's Abbreviated Column

| Requirement | Statement | Cited by |
|---|---|---|
| `BRD-REQ-011` ‡ | Counterparty's verification status visible before commitment | `FRD-FR-028` (column: 011, 075) |
| `BRD-REQ-024` ‡ | Vehicle information presented before commitment | `FRD-FR-047` (column: 024) |
| `BRD-REQ-025` ‡ | Vehicle information presented corresponds to the recorded vehicle | `FRD-FR-046` (column: 025) |
| `BRD-REQ-065` ‡ | Confirmed seats never exceed seats offered, including concurrently | `FRD-FR-107` |
| `BRD-REQ-066` ‡ | Booking confirmed only under platform authority, payment verified | `FRD-FR-106` |
| `BRD-REQ-077` ‡ | Client-side UPI response never authoritative evidence of payment | `FRD-FR-124` |

**Action: none on CMP-DOC-02.** The correction is to CMP-DOC-20's measure — §3.1.

## 2.2 Outcome 1 — Realised, Cited Elsewhere

**Every realiser below was resolved to its statement text and confirmed.** The action in
every case is: **the realising document adds the requirement to its own `Src` column**,
under change control (`TR-051`). CMP-DOC-20 does not add it.

### Functional requirements — 10

| Requirement | Realised by | Realising document must cite |
|---|---|---|
| `FRD-FR-046` ‡ Present the vehicle information recorded against a ride | `UX-093` single pre-commitment screen presenting vehicle information; `DB-048` a vehicle row is not deleted while referenced by a ride | CMP-DOC-12, CMP-DOC-11 |
| `FRD-FR-047` ‡ Present vehicle information before commitment | `UX-093`; `MOB-027` the six values presented before any commitment | CMP-DOC-12, CMP-DOC-08 |
| `FRD-FR-098` ‡ Re-confirm seat availability at the moment of request | `ARCH-086` re-checked under lock at confirmation, **not only at request**; `API-135` read from the authoritative record; `PAY-068` re-read under lock in transaction | CMP-DOC-07, CMP-DOC-10, CMP-DOC-14 |
| `FRD-FR-110` ‡ Reject any client assertion that a booking is confirmed | `API-036` no field by which a caller may assert an authoritative value; `API-037` payment status, seat counts and trip state absent from every request schema; `API-039` such a field is recorded as an integrity event | CMP-DOC-10 |
| `FRD-FR-241` ‡ Return the platform's own determination as authoritative | `API-045` a value read under lock is marked authoritative at the stated instant; `MOB-052` the gateway marks every value it returns | CMP-DOC-10, CMP-DOC-08 |
| `FRD-FR-246` ‡ Name the operator responsible where an operator acted | `BE-107` the evidential record captures actor; `BE-078` / `ADM-012` the acting operator's identity is carried into the application service | CMP-DOC-09, CMP-DOC-17 |
| `FRD-FR-251` ‡ Determine the roles held before permitting an action | `SEC-045` entitlement evaluated against platform state on every request; `SEC-055` deny-by-default; `SEC-061` capability restricted by role in the application layer | CMP-DOC-13 |
| `FRD-FR-252` ‡ Permit an action only where a role allows it | `SEC-055`; `SEC-061`; `NFR-058` | CMP-DOC-13 |
| `FRD-FR-253` ‡ Refuse without partial application, and record the refusal | `SEC-057` every refused authorisation recorded; `BE-053` a failed operation leaves no partial effect; `BE-114` refused operations evidenced with reason | CMP-DOC-13, CMP-DOC-09 |
| `FRD-FR-256` ‡ Withdraw or mark an affected capability | `SRS-REQ-132` withdraw entirely rather than degrade; `UX-071` the screen says so rather than presenting a failing control | CMP-DOC-06, CMP-DOC-12 |

### Quality requirements — 3

| Requirement | Realised by | Realising document must cite |
|---|---|---|
| `NFR-033` ‡ Never present a client-asserted value as authoritative | `API-036`; `ARCH-016` the client computes no authoritative value; `BE-033` verification standing never accepted from an inbound value | CMP-DOC-10, CMP-DOC-07, CMP-DOC-09 |
| `NFR-046` ‡ Preserve seat and payment integrity at any load | `BE-024` the aggregate invariant; `BE-048` allocation, confirmation, ledger and payment status in one transaction; `DB-083` the `CHECK` constraint as last line of defence | CMP-DOC-09, CMP-DOC-11 |
| `NFR-130` ‡ Vehicle information shown matches the record | `UX-093`; `DB-048`; `API-133` a vehicle is not withdrawn while carrying a ride with a confirmed booking | CMP-DOC-12, CMP-DOC-11, CMP-DOC-10 |

> **Qualification on `NFR-046`.** The integrity half is realised. The **"at any load"**
> half cannot be verified: load is one of the five categories CMP-DOC-18 §15.3 records as
> having no target (`GAP-012`), so it is measured and never passed. The citation should be
> added and the qualification carried with it.

### Software requirements — 14

| Requirement | Realised by | Realising document must cite |
|---|---|---|
| `SRS-REQ-008` ‡ The client never reaches persistence directly | `ARCH-010` no container other than the platform reaches the database; `ARCH-016`; `BE-087` the ORM is used only in three namespaces | CMP-DOC-07, CMP-DOC-09 |
| `SRS-REQ-023` ‡ Present the six values before commitment | `MOB-027`; `UX-093` | CMP-DOC-08, CMP-DOC-12 |
| `SRS-REQ-030` ‡ No statement or implication of insurance cover | `UX-176` no content states or implies insurance cover | CMP-DOC-12 |
| `SRS-REQ-035` ‡ Identical rules irrespective of originating element | `SEC-009` an operator request traverses the same authorisation and validation; `API-042`; `API-108`; `ADM-029` | CMP-DOC-13, CMP-DOC-10, CMP-DOC-17 |
| `SRS-REQ-038` ‡ A multi-state workflow completes or leaves no partial effect | `BE-053`; `BE-048` | CMP-DOC-09 |
| `SRS-REQ-046` ‡ Verification initiated on the platform's own schedule | `ARCH-073` re-attempt on its own schedule, bounded and recorded; `PAY-128`; `PAY-032` enqueued at creation so an abandoned handoff is still verified | CMP-DOC-07, CMP-DOC-14 |
| `SRS-REQ-058` ‡ Capture safety context at the moment of the signal | `ARCH-098` persisted before any enrichment or routing; `ARCH-101`; `DB-077` insertable with partial context | CMP-DOC-07, CMP-DOC-11 |
| `SRS-REQ-103` ‡ Integration invoked only by Platform Services | `BE-153` no provider type above the adapter; `BE-155` an adapter is invoked outside a transaction | CMP-DOC-09 |
| `SRS-REQ-104` ‡ Integration makes no business decision | `BE-156` an adapter shall not decide business outcome; it reports what the provider said | CMP-DOC-09 |
| `SRS-REQ-124` ‡ No path to Persistence but through Platform Services | `BE-068`; `BE-075`; `BE-087`; `DB-119` the application account holds no `DDL` | CMP-DOC-09, CMP-DOC-11 |
| `SRS-REQ-127` ‡ An audit record for every recordable event | `BE-111`–`BE-114`; `DB-114` the five evidenced categories | CMP-DOC-09, CMP-DOC-11 |
| `SRS-REQ-151` ‡ Each item of authoritative state in exactly one place | `ARCH-016`; `ARCH-024` the outbox holds no authoritative value; `DB-088` seat availability never read from a projection | CMP-DOC-07, CMP-DOC-11 |
| `SRS-REQ-152` ‡ Accountable element and permitted transitions per state | `BE-017` the nine aggregates; `BE-176` an undeclared transition is refused; `BE-178` a transition is evidenced with trigger and actor | CMP-DOC-09 |
| `SRS-REQ-172` ‡ No error path releases or holds a seat wrongly | `BE-024`; `BE-053`; `DB-083` the `CHECK` constraint holds irrespective of code path | CMP-DOC-09, CMP-DOC-11 |

## 2.3 Outcome 2 — Realised, No Statement Identifiable

### `FRD-FR-083` ‡ — exclude from bookable results any ride with fewer available seats than requested

**Evidence.** `ADR-03` (CMP-DOC-07 §4.3) decides two-phase matching, and its decision text
reads: *"a coarse filter … narrowing candidates by geographic proximity, direction, date
and time window, and available seats."* **The seat criterion appears only there.**

The numbered statements that carry `ADR-03` forward — `ARCH-052` (spatially indexed
representation supporting the coarse filter), `DB-053` and `DB-194` (the spatial index
serves the coarse filter only) — **all specify the spatial dimension and none specifies the
seat-count criterion**. No statement in CMP-DOC-09, CMP-DOC-10 or CMP-DOC-11 states that a
ride with insufficient seats is excluded from results.

**Assessment.** The behaviour is decided and unspecified. A decision record's prose is not
a specification, and implementation follows statements.

**Action: register a gap** against CMP-DOC-07 or CMP-DOC-09 — the coarse filter's
non-spatial criteria are not specified in any statement.

### `SRS-REQ-128` ‡ — attribute every audit record to the actor **and element** responsible

**Evidence.** `BE-107` specifies the evidential record's content: *"actor, action, subject,
time, outcome and reason."* CMP-DOC-11 §9.1 gives the same column set: internal key;
actor, action, subject; outcome, reason; occurred instant; previous hash, record hash.

**Neither carries the originating element.** A search across CMP-DOC-09, CMP-DOC-11 and
CMP-DOC-13 for any statement recording which element — client, platform service,
administrative surface, integration — originated an evidenced action returns nothing.

**Assessment.** The actor half is realised. The element half is not, and `SRS-REQ-127`
makes the element explicit as something the audit must be indifferent to *producing* —
which is a different property from *recording* it.

**Action: register a gap.** Either the evidential record gains an originating-element
attribute, or `SRS-REQ-128` is amended to require only actor attribution. **This annex does
not choose**; the choice is CMP-DOC-06's and CMP-DOC-09's, under change control.

## 2.4 Outcome 3 — Not Realised

### `NFR-138` ‡ — record each user's agreement to the rules of participation, with the version agreed

**Evidence.** Searched across CMP-DOC-06 … CMP-DOC-19 for *agreement*, *consent*,
*accepted*, *terms* and *rules of participation*:

| Where | What exists |
|---|---|
| CMP-DOC-02 | `BRD-CMP-008` — *"obtain and record users' agreement to the rules of participation"* |
| CMP-DOC-04 | `FRD-FR-194` realises viewing the rules (`UC-083`); **nothing on recording agreement** |
| CMP-DOC-06 | `SRS-REQ-179` — policy-bearing text held as **versioned configuration** |
| CMP-DOC-11 | **No table.** The user domain holds credentials, verifications, emergency contacts, preferences — no agreements |
| CMP-DOC-10 | **No operation** |
| CMP-DOC-12 | **No screen** |
| CMP-DOC-09, CMP-DOC-13, CMP-DOC-17 | **Nothing** |

`SRS-REQ-179` versions the *text*. Nothing records that a user agreed to a version of it.

**Assessment.** An integrity-critical requirement with a compliance character, required by
`BRD-CMP-008`, **has no realisation anywhere in the chain.** It is not withheld — it appears
in none of the four withheld registers — and it is not one of the 29 functional gaps. It
was simply not carried forward.

**Action: register a gap** against CMP-DOC-04 (no functional requirement), CMP-DOC-10 (no
operation) and CMP-DOC-11 (no table). This is the review's material finding.

## 2.5 Outcome 4 — Superseded

### `FRD-FR-243` ‡ — resolve any disagreement between client-held and platform-held state in favour of platform-held state, and correct the client

**Evidence.** The requirement presupposes that the client holds state which may disagree
with the platform's. The architecture adopted after CMP-DOC-04 removes that premise:

| Statement | Effect |
|---|---|
| `ARCH-016` | The client holds no business rule and computes no authoritative value |
| `MOB-052` | The gateway marks every value it returns as authoritative |
| `MOB-060` | A value read from the cache is marked `Cached` with its retrieval time |
| `MOB-061` | The cache is never the source of a value on which a commitment depends |
| `ARCH-024`, `MOB-067` | The outbox holds no value the platform treats as authoritative |
| `UX-044` | A value beyond its staleness bound is presented as stale, never as current |

Under the provenance model **the client never holds a competing authoritative value**, so
there is no disagreement to resolve and nothing to correct. The requirement's intent — the
platform wins — is satisfied structurally rather than procedurally.

**Action: record the supersession** in CMP-DOC-04 against `FRD-FR-243`, naming `ARCH-016`
and the provenance model. **Do not delete the requirement** (`TR-048`).

---

# 3. Consequences

## 3.1 Corrections Required to CMP-DOC-20

| Location | Reported | Should read |
|---|---|---|
| §5.2, §1.4, §19.1 | 208 uncited | **177** |
| §5.2, §6, §19.1 | 37 integrity-critical uncited | **31** |
| §5.2 CMP-DOC-02 row | 64 uncited, 6 ‡ | **33 uncited, 0 ‡** |
| §5.1, `TRDR-02`, `TRDR-05` | Citation measured by identifier | Must also read CMP-DOC-04's abbreviated column |
| §6.3 | Four outcomes | **Five** — outcome 0 added |
| §14.1 | Five limits of the measurement | **Six** — abbreviated notation added |

**`TR-034` stands.** 177 requirements still carry no downstream citation and 31
integrity-critical ones remain unresolved by citation; the correction changes the size of
the finding, not the finding.

## 3.2 Gaps to Register

| Gap | Subject | Against |
|---|---|---|
| **GAP-018** | **No user agreement to the rules of participation is recorded anywhere** — `NFR-138`, `BRD-CMP-008` | CMP-DOC-04, CMP-DOC-10, CMP-DOC-11 |
| **GAP-019** | The originating element is absent from the evidential record — `SRS-REQ-128` | CMP-DOC-06, CMP-DOC-09, CMP-DOC-11 |
| **GAP-020** | The coarse filter's non-spatial criteria are specified in no statement — `FRD-FR-083` | CMP-DOC-07, CMP-DOC-09 |

**All three are registered.** `GAP-018`, `GAP-019` and `GAP-020` were added to
`Master_Traceability_Matrix.md` on issue of this annex, taking the gap register to **20**.
Registering a gap records that something is unknown; it decides nothing, and the chain's
practice throughout has been that the document which finds a gap registers it.

## 3.3 Citations to Add

**FACT, measured. 72 citations, across 55 distinct realising statements in 11 documents,
closing 27 requirements.** Each belongs in the realising statement's own document
(`TR-051`), added under change control (§29).

| Document | Statements to amend | Requirements they close |
|---|---|---|
| CMP-DOC-05 NFR | 1 | 1 |
| CMP-DOC-06 SRS | 1 | 1 |
| CMP-DOC-07 SAD | 9 | 6 |
| CMP-DOC-08 MOBILE | 3 | 3 |
| **CMP-DOC-09 BACKEND** | **23** | **12** |
| CMP-DOC-10 API | 9 | 6 |
| CMP-DOC-11 DATABASE | 8 | 8 |
| CMP-DOC-12 UIUX | 6 | 6 |
| CMP-DOC-13 SECURITY | 7 | 4 |
| CMP-DOC-14 PAYMENT | 3 | 2 |
| CMP-DOC-17 ADMIN | 2 | 2 |
| **Total** | **72** | 27 |

**CMP-DOC-09 carries a third of the work**, which is consistent with `TR-034`: the backend
document states most of the platform's absolute behaviour and anchored almost all of it to
`BAD-RULE-nnn` rather than to the requirements in between.

## 3.4 The Remaining 171

`TR-050` requires the same review of the 171 uncited non-integrity-critical requirements,
at lower priority. **31 of the 33 remaining uncited `BRD-REQ` should be re-measured first**
against CMP-DOC-04's column, which will reduce the 171 further before any hand review
begins.

---

# 4. Actions Awaiting Direction

| # | Action | Owner | Blocks |
|---|---|---|---|
| ~~A-1~~ | ~~Correct CMP-DOC-20 and add outcome 0 — reissue at v0.2~~ — **done on issue** | Documentation Manager | — |
| ~~A-2~~ | ~~Register **GAP-018**~~ — **done on issue** | Documentation Manager | — |
| ~~A-3~~ | ~~Register **GAP-019** and **GAP-020**~~ — **done on issue** | Documentation Manager | — |
| A-4 | Add the 73 citations across 12 documents | Each document's author | Baselining CMP-DOC-02, 04, 05, 06 (`TR-038`, §16.7) |
| A-5 | Record the supersession of `FRD-FR-243` in CMP-DOC-04 | Product Analyst | Baselining CMP-DOC-04 |
| A-6 | Decide whether `SRS-REQ-128` gains a realisation or is amended | Solution Architect | GAP-019 |
| A-7 | Re-run the review over the 171 | Documentation Manager | Baselining, at lower priority |

> **Three of the seven are done** — A-1, A-2 and A-3, which are the Documentation
> Manager's own records and decide nothing. **A-4 to A-7 have not been performed**, because
> each changes a document this annex does not own: `TRDR-06` reserves adding a citation to
> the author of the realising statement, and A-6 is a design decision.

---

# 5. Statistics

| Measure | Value |
|---|---|
| Requirements reviewed | **37** |
| Realisers named | 72, across 55 distinct statements |
| **Realisers resolved to their source text and verified** | **72 of 72** |
| Non-existent realisers named | **0** |
| Outcome 0 — already cited | 6 |
| Outcome 1 — realised, cited elsewhere | 27 |
| Outcome 2 — realised, no statement | 2 |
| Outcome 3 — not realised | 1 |
| Outcome 4 — superseded | 1 |
| **Requirements closed by assertion** | **0** |
| **Traceability links created** | **0** |
| Gaps registered | 3 — `GAP-018`, `GAP-019`, `GAP-020` |
| Corrections required to CMP-DOC-20 | 6 |
| Actions: done / awaiting direction | 3 / 4 |

---

**End of CMP-DOC-20A, version 0.1 (Draft).**
