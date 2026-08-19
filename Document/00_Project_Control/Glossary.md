# Glossary — Carpool Mobility Platform (CMP)

**Control Document — Controlled Vocabulary**

| Field | Value |
|---|---|
| Document ID | CMP-CTRL-GLOSSARY |
| Document Name | Glossary |
| Project Name | Carpool Mobility Platform |
| Project Code | CMP |
| Version | 3.1 |
| Status | Draft |
| Date | 2026-08-16 |
| Author | Documentation Manager (AI-assisted) |
| Reviewer | [TBD] |
| Approver | Project Owner |
| Classification | Internal |
| Brand | TBD |
| Previous Version | 0.8 (2026-08-16) |
| Related Documents | README.md, Documentation_Index.md, Documentation_Status.md, Document_Change_Log.md, Master_Traceability_Matrix.md |

---

## 1. Purpose

This glossary defines the controlled vocabulary for the CMP documentation repository.
All documents must use these terms consistently. Where a definition is not yet decided,
the term is marked `[TBD]` rather than given an invented definition.

## 2. Usage Rules

1. Terms defined here are the canonical spelling and capitalization for all documents.
2. Where a term's precise business meaning depends on a pending decision, the entry is
   marked `BUSINESS DECISION REQUIRED` and the definition remains provisional.
3. If a final product brand name is selected, historical technical terminology is
   **not** automatically replaced. Any such replacement requires change control and is
   recorded in `Document_Change_Log.md`.
4. New terms are added as documents are produced; terms are never silently redefined.

## 3. Definition Confidence Legend

| Marker | Meaning |
|---|---|
| FACT | Established by the Master Documentation Control Prompt or an approved document. |
| ASSUMPTION | Working definition adopted for documentation continuity; not yet confirmed. |
| BUSINESS DECISION REQUIRED | Meaning depends on a business decision not yet made. |
| TECHNICAL DECISION REQUIRED | Meaning depends on a technical decision not yet made. |
| TBD | Not yet defined. |

---

## 4. Domain Terms — Actors and Roles

| Term | Definition | Confidence |
|---|---|---|
| **Passenger** | A platform user who searches for, requests, and books a seat in another user's Ride for a journey. | FACT |
| **Driver** | A platform user who owns or operates a Vehicle and publishes a Ride offering one or more seats to Passengers. | FACT |
| **User** | Any registered account holder on the platform. A single User may act as both Passenger and Driver, subject to role and verification rules. | ASSUMPTION |
| **Co-traveller** | A Passenger or Driver participating in the same Ride or Trip as the user in question. | ASSUMPTION |
| **Emergency Contact** | A person nominated by a User to be contacted or informed in a safety event, subject to the safety rules defined in DOC-13/DOC-15. | FACT |
| **Admin** | An operator of the platform's administrative interface (Laravel Filament), responsible for platform operations such as verification, moderation, support, and reporting. | FACT |
| **Project Owner** | The individual with authority to approve documents, scope, business rules, and the final brand name. | ASSUMPTION |

## 5. Domain Terms — Core Business Entities

| Term | Definition | Confidence |
|---|---|---|
| **Ride** | A journey published by a Driver, comprising origin, destination, date, departure time, available seats, fare, Vehicle, and preferences. A Ride is the offer; it is not itself a booking. | FACT |
| **Ride Request** | A Passenger's request to occupy one or more seats on a specific Ride. Whether a Ride Request requires Driver acceptance, and under what conditions it may be auto-accepted, is not yet decided. | BUSINESS DECISION REQUIRED |
| **Booking** | A confirmed seat allocation on a Ride for a Passenger. Booking confirmation is a backend-authoritative state. | FACT |
| **Trip** | The execution of a Ride — the in-progress or completed journey, including live tracking, telemetry, completion, ratings, and reviews. | FACT |
| **Recurring Commute** | A repeating travel pattern (for example daily or working-day) from which Rides may be generated on a schedule. Activation, pause, removal, generation rules, and any verified-peer auto-acceptance behaviour are not yet decided. | BUSINESS DECISION REQUIRED |
| **Route Match** | A determination that a published Ride's route overlaps a Passenger's requested travel segment sufficiently to be offered as a search result. | FACT |
| **Route Overlap Percentage** | A measure of how much of a Passenger's requested segment is covered by a Ride's route. The calculation method is not finalized. | TECHNICAL DECISION REQUIRED |
| **Corridor** | A commuter route segment along which supply and demand are measured and matching liquidity is judged. The unit of launch and expansion planning. Introduced by CMP-DOC-01 §4.3. | ASSUMPTION |
| **Corridor Liquidity** | The sufficiency of published supply and searching demand on a given Corridor within a given time band for search to return a usable result. Identified in CMP-DOC-01 as the single greatest execution risk (`BAD-RISK-002`). | ASSUMPTION |
| **Commute Loop** | The end-to-end cycle the platform must complete: publish → search → book → pay → travel → rate. Used in CMP-DOC-01 §24 to define MVP completeness. | ASSUMPTION |
| **Vehicle** | A car or other conveyance registered by a Driver and associated with published Rides. Subject to verification. | FACT |
| **Seat** | A single bookable capacity unit on a Ride. Seat availability is backend-authoritative. | FACT |
| **Fare** | The amount payable by a Passenger for a booked seat on a Ride. Fare calculation is backend-authoritative. Fare amounts, formulas, and any platform-imposed limits are not decided. | BUSINESS DECISION REQUIRED |
| **Platform Fee** | A fee retained by the platform in connection with a Booking or Trip. Its existence, basis, and value are not decided. | BUSINESS DECISION REQUIRED |
| **Driver Earnings** | The amount attributable to a Driver from completed Bookings/Trips, net of any Platform Fee. Backend-authoritative. Calculation rules not decided. | BUSINESS DECISION REQUIRED |
| **Rating** | A score submitted by a participant about a co-traveller or a completed Trip. Scale and rules not decided. | BUSINESS DECISION REQUIRED |
| **Review** | Free-text feedback submitted about a completed Trip or a co-traveller, subject to moderation. | ASSUMPTION |

## 6. Domain Terms — Money, Wallet and Rewards

| Term | Definition | Confidence |
|---|---|---|
| **Wallet** | A per-User balance held by the platform, recorded as a ledger. Balance is backend-authoritative. Whether the Wallet holds real money, reward value, or both is not decided. | BUSINESS DECISION REQUIRED |
| **Wallet Ledger** | The append-only record of credits and debits that determines a Wallet balance. | ASSUMPTION |
| **Reward** | Value granted to a User for a qualifying action (for example completing a ride, referring a user, or reaching a milestone). Reward economics are not decided. | BUSINESS DECISION REQUIRED |
| **Points** | The unit in which Rewards are accrued and redeemed. | ASSUMPTION |
| **Beride Points** | Term carried forward from the source product concept as the reward-points unit. **This term contains a brand-suggestive element and no final brand name has been selected.** Pending the Project Owner's brand decision, documentation uses the neutral term *Points*, and *Beride Points* is retained here only as a recorded legacy/source term. | BUSINESS DECISION REQUIRED |
| **Coupon** | A redeemable instrument granting a discount or benefit against a Booking or fare. Issue rules, value, and constraints not decided. | BUSINESS DECISION REQUIRED |
| **Referral Reward** | A Reward granted for introducing a new User to the platform. | ASSUMPTION |
| **Milestone Reward** | A Reward granted for reaching a defined cumulative achievement. | ASSUMPTION |
| **Refund** | Return of paid value to a Passenger following cancellation or an exception. Refund decisions are backend-authoritative. Refund policy is not decided. | BUSINESS DECISION REQUIRED |
| **Cancellation Rule** | The business rule governing whether a Ride, Ride Request, or Booking may be cancelled, by whom, when, and with what financial consequence. Not decided. | BUSINESS DECISION REQUIRED |

## 7. Domain Terms — Payments

| Term | Definition | Confidence |
|---|---|---|
| **UPI** | Unified Payments Interface — the Indian real-time interbank payment system that is the current payment direction for CMP. | FACT |
| **UPI Application** | A third-party consumer application used to authorize a UPI payment (for example Google Pay, PhonePe, Paytm, CRED, BHIM, Amazon Pay). | FACT |
| **Payment** | A transfer of value from a Passenger in settlement of a fare or other platform charge. | FACT |
| **Payment Status** | The backend-authoritative state of a Payment. A response returned by a client-side UPI Application must **not** by itself be treated as authoritative payment confirmation. | FACT |
| **Payment Verification** | The backend process that establishes authoritative Payment Status. Mechanism depends on the payment integration ultimately selected. | TECHNICAL DECISION REQUIRED |
| **PSP / Payment Gateway** | The payment service provider used to initiate and verify UPI transactions. Not selected. | TECHNICAL DECISION REQUIRED |

## 8. Domain Terms — Safety

| Term | Definition | Confidence |
|---|---|---|
| **SOS** | An emergency signal raised by a User from within the application during a Trip or other qualifying context. Downstream handling and escalation are not decided. | BUSINESS DECISION REQUIRED |
| **Safety Centre** | An in-application area consolidating safety features, guidance, and controls. | ASSUMPTION |
| **Live Trip Sharing** | A capability allowing a User to share live Trip progress with a nominated recipient. | ASSUMPTION |
| **Safety Incident** | A recorded event requiring safety review or operational response, managed through the Admin interface. | ASSUMPTION |

## 9. Domain Terms — Verification and Status

| Term | Definition | Confidence |
|---|---|---|
| **Verification** | The process by which the platform confirms a claim about a User or Vehicle (for example phone ownership, identity, or vehicle documents). Verification status is backend-authoritative. | FACT |
| **Phone Verification** | Confirmation that a User controls a claimed phone number. Mechanism (for example OTP) and provider not decided. | TECHNICAL DECISION REQUIRED |
| **Identity Verification** | Confirmation of a User's identity. Accepted documents, provider, and legal obligations are **not** to be assumed and are not decided. | BUSINESS DECISION REQUIRED |
| **Verified Peer** | A User whose verification state qualifies them for behaviours reserved to verified users (for example potential auto-acceptance in a Recurring Commute). Qualifying criteria not decided. | BUSINESS DECISION REQUIRED |
| **User Status** | The lifecycle state of a User account (for example active, suspended). Permitted values not decided. | BUSINESS DECISION REQUIRED |
| **Booking Status** | The lifecycle state of a Booking. Permitted values are defined in later documents; backend-authoritative. | TBD |
| **Trip State** | The lifecycle state of a Trip. The provisional concept sequence is: Waiting Departure → Picking Up → On The Way → Approaching Drop → Arrived. Final state model not approved. | ASSUMPTION |

## 10. Technical Terms

| Term | Definition | Confidence |
|---|---|---|
| **CMP** | Project Code for the Carpool Mobility Platform. | FACT |
| **Backend** | The Laravel application that holds business logic, enforces business rules, and owns authoritative business state. | FACT |
| **Business Authority** | The principle that the Backend, not the mobile client, determines authoritative shared business state. | FACT |
| **API** | The versioned REST/JSON interface (`/api/v1/`) forming the communication contract between clients and the Backend. | FACT |
| **REST** | The API architectural style used by CMP. | FACT |
| **Laravel** | The PHP framework used for the CMP Backend. | FACT |
| **Laravel Filament** | The administrative interface framework used for CMP Admin. Part of the Laravel ecosystem; **not** a separate backend. | FACT |
| **MySQL** | The relational database used as the Backend persistence layer. The Android application must never connect to it directly. | FACT |
| **Android** | The initial client platform for CMP. | FACT |
| **Kotlin** | The programming language used for the Android application. | FACT |
| **Jetpack Compose** | The declarative UI toolkit used by the Android application. | FACT |
| **MVVM** | Model-View-ViewModel — the Android presentation architecture pattern in use, applied together with Clean Architecture. | FACT |
| **Clean Architecture** | The layered architecture approach applied in the Android application. | FACT |
| **StateFlow** | The Kotlin state-holder stream used for Android UI state management. | FACT |
| **Kotlin Coroutines** | The concurrency mechanism used in the Android application. | FACT |
| **Room** | The Android local persistence library, used for local/cached state only — never as an authority for shared business data. | FACT |
| **Fused Location Provider** | The Android location API used for device positioning. | FACT |
| **Google Maps Platform** | The mapping service direction for CMP, including Google Places and Google Routes. | FACT |
| **FCM** | Firebase Cloud Messaging — the push notification service direction. | FACT |
| **Crashlytics** | Firebase crash reporting service direction. | FACT |
| **Telemetry** | Trip-time runtime data such as speed, ETA, remaining distance, current landmark, navigation instruction, and vehicle position. | ASSUMPTION |
| **ETA** | Estimated Time of Arrival. | FACT |
| **Cached State** | Client-held data mirroring backend state for responsiveness or offline viewing; never authoritative for shared business data. | FACT |

## 11. Documentation Terms

| Term | Definition | Confidence |
|---|---|---|
| **BAD** | Business Analysis Document (DOC-01). | FACT |
| **BRD** | Business Requirements Document (DOC-02). | FACT |
| **FRD** | Functional Requirements Document (DOC-04). | FACT |
| **NFR** | Non-Functional Requirements / Quality Attributes (DOC-05). | FACT |
| **SRS** | Software Requirements Specification (DOC-06). | FACT |
| **SAD** | System Architecture Document (DOC-07). | FACT |
| **RTM** | Requirements Traceability Matrix. | FACT |
| **MVP** | Minimum Viable Product — the smallest scope delivering business value. CMP MVP scope is not yet defined. | BUSINESS DECISION REQUIRED |
| **Developer-Ready** | The quality bar defined in `README.md` §11. | FACT |
| **Project Control Document** | One of the six governance files in `Document/00_Project_Control/`. | FACT |
| **`BAD-BR-nnn`** | A **traceable business requirement** issued by CMP-DOC-01. The source row of the forward traceability chain. 78 issued at v0.1. | FACT |
| **`BRD-REQ-nnn`** | A **traceable business requirement** issued by CMP-DOC-02, elaborating one or more `BAD-BR` requirements. 188 issued at v0.1. | FACT |
| **Ready** (requirement status) | A requirement that can be elaborated into use cases and functional requirements now. Introduced by CMP-DOC-02 §0.10.5. 109 of 188 at v0.1. | FACT |
| **Blocked** (requirement status) | A requirement whose obligation is settled but whose parameters await a named business decision. **A blocked requirement is a real requirement, not a placeholder.** Introduced by CMP-DOC-02 §0.10.5. 79 of 188 at v0.1. | FACT |
| **Verification intent** | A per-domain statement of how satisfaction of its requirements will be demonstrated. The precursor to acceptance criteria and test cases. Introduced by CMP-DOC-02 §5. | FACT |
| **Absolute-rule requirement (‡)** | A requirement implementing one of the absolute business rules of CMP-DOC-01 §14. **Not subject to descoping.** 24 identified in CMP-DOC-02 §10.2. | FACT |
| **MoSCoW** | The prioritisation scheme used by CMP-DOC-02: Must / Should / Could / Won't (this release). | FACT |
| **`UC-nnn`** | A **traceable use case** issued by CMP-DOC-03, realising one or more `BRD-REQ`. 83 issued at v0.1. | FACT |
| **Specified / Partial / Outlined** | The three use case specification tiers (CMP-DOC-03 §4.4). *Specified* = complete flows; *Partial* = complete except named blocked steps; *Outlined* = scope recorded, **flows deliberately not written** pending a decision. | FACT |
| **Blocked step** | A step inside an otherwise specified flow whose behaviour a named business decision governs, marked `[BLOCKED — BAD-DEC-nnn]` in place rather than guessed. | FACT |
| **Primary / Supporting / Offstage / System actor** | The four actor classes (CMP-DOC-03 §2.1). *System* denotes the Platform acting on its own initiative. | FACT |
| **Implicit precondition** | A condition assumed by every use case unless overridden (CMP-DOC-03 §8.1) — authenticated session, permitting account state, permitting role, audit record, authoritative values. | FACT |
| **Integrity-critical use case** | A use case enforcing an absolute business rule; **not subject to descoping**. 12 identified in CMP-DOC-03 Appendix A.5. | FACT |
| **`FRD-FR-nnn`** | A **traceable functional requirement** issued by CMP-DOC-04, decomposed from a use case. 260 issued at v0.1. | FACT |
| **Functional gap (`FRD-GAP-nnn`)** | Behaviour that cannot be specified because a named business decision does not exist. Recorded in the requirement register as a `— GAP —` row **in place of** a requirement, never filled by invention. 29 recorded at v0.1, 9 Critical. | FACT |
| **Verification method (T / D / I / A)** | How satisfaction of a functional requirement will be demonstrated: **T**est, **D**emonstration, **I**nspection, **A**nalysis. Assigned to every requirement in CMP-DOC-04 as the basis for CMP-DOC-18. | FACT |
| **Integrity-critical requirement (‡)** | A functional requirement implementing an absolute or proposed-absolute business rule. **Not subject to descoping**; requires a negative test proving the rule cannot be violated. 81 marked at v0.1. | FACT |
| **Live product defect** | A gap through which *specified* behaviour can reach a state with no specified way forward — as distinct from an absent feature. Two exist: driver cancellation with confirmed bookings (`GAP-008`), and return of value to a passenger (`GAP-009`). | FACT |
| **`NFR-nnn`** | A **traceable quality requirement** issued by CMP-DOC-05, qualifying one or more functional requirements. 162 issued at v0.1. | FACT |
| **Quality attribute** | A category of non-functional concern. Thirteen are defined in CMP-DOC-05 §4, adapted from ISO/IEC 25010 with two product-specific additions: **Cost Efficiency** and **Mobile Resource Consumption**. | FACT |
| **Absolute target** | A quality target fixed by an already-approved absolute business rule rather than chosen — typically zero or 100%. **Not an invented target.** 44 exist, marked ‡ and listed in CMP-DOC-05 Appendix B. | FACT |
| **Stated target** | A non-numeric quality target decidable today without a business decision — *sub-linear*, *configurable*, *full compliance*, *instrumented from R1*. 49 exist. | FACT |
| **Measurement point** | Where a metric is measured: **Server**, **Device**, **End-to-end**, **Operational** or **Build**. Named on every quality requirement so that the measurement is unambiguous. | FACT |
| **Target-setting route (R1–R4)** | How an undecided target will be arrived at: R1 rule-derived, R2 measured baseline, R3 user tolerance, R4 commercial ceiling. CMP-DOC-05 §19.1. | FACT |
| **Quality trade-off** | A pair of quality attributes that cannot both be maximised, requiring a named owner to decide the balance. Four identified in CMP-DOC-05 §4.3. | FACT |
| **`SRS-REQ-nnn`** | A **traceable software requirement** issued by CMP-DOC-06, stating an obligation on a software element. 184 issued at v0.1. | FACT |
| **Software element** | One of the six logical parts the system divides into for allocation purposes — Mobile Client, Platform Services, Administrative Application, Persistence, Integration, Cross-Element. **A logical decomposition, not a deployment unit or code structure.** | FACT |
| **Accountable / Contributing** | Allocation roles. Exactly one element is **accountable** for each requirement; others may **contribute**. Accountable counts sum exactly to the requirement totals. | FACT |
| **Trust boundary** | A point at which one element's output must not be trusted by another without validation. Four exist in CMP-DOC-06 §3.4: client→platform, admin→platform, integration→platform, platform→persistence. | FACT |
| **Interaction rule (IR-1…IR-5)** | A constraint on which software element may invoke which. Notably: the client reaches the system only through the platform's versioned interface, and persistence is reachable only from platform services. | FACT |
| **Reserved area** | A functional area with no requirements whose future owning element is named in advance, so that architecture can allow for it. Three exist: Ratings & Reviews, Wallet & Rewards, Recurring Commute. | FACT |
| **Structural verification** | Verification by inspection or analysis that a path, a duplication or an embedded value does **not** exist. Cannot be demonstrated by a passing test. 74 required by CMP-DOC-06. | FACT |
| **`ARCH-nnn`** | A **traceable architecture statement** issued by CMP-DOC-07, specifying a structural obligation. 148 issued at v0.1. | FACT |
| **Architectural driver** | A requirement that would **change the structure** if it changed, as distinct from one the structure must merely satisfy. Twelve identified in CMP-DOC-07 §2. | FACT |
| **Architecture Decision Record (ADR)** | A recorded architecture decision with its context, the alternatives considered and its consequences **including the negative ones**. Twenty-four issued; `ADR-01` … `ADR-24`. | FACT |
| **Port / adapter** | A provider-neutral capability contract (port) and its provider-specific implementation (adapter). **No provider name, type or error appears above the port boundary**, which is what makes the three unselected suppliers non-structural. | FACT |
| **Evidential log** | The append-only, integrity-chained record of every recordable event, held distinctly from mutable operational state. Never updated in place; corrections are new entries. | FACT |
| **Projection** | A derived, non-authoritative read representation maintained from authoritative state. **Never the source for seat availability.** | FACT |
| **Policy configuration** | Versioned, audited, runtime-editable business values and state-model definitions — distinct from *deployment configuration* (deploy-time, not runtime-editable) and from absolute business rules, which are code and configurable by neither. | FACT |
| **Sizing decision** | A decision that cannot be taken until a quality target exists. Eleven are deferred from CMP-DOC-07 to CMP-DOC-19, each with a named blocker. | FACT |
| **Structural exception** | A deliberate, named departure from an architectural decision. **Exactly one exists**: `ADR-24`, permitting safety incident handling alone to deploy independently of the single deployable unit. | FACT |
| **`MOB-nnn`** | A **traceable mobile architecture statement** issued by CMP-DOC-08. 168 issued at v0.1. | FACT |
| **Provenance** | The origin and freshness carried alongside **every** business value in client state: `Authoritative`, `Cached(asOf)` or `Unknown`. A value cannot be rendered without it — this is how `NFR-086` becomes a type-level property rather than a rule developers must remember. | FACT |
| **Presentation cache** | The on-device store of values previously retrieved from the platform, used only for display and always marked `Cached`. **Never authoritative**, and never the source for a value on which a commitment depends. | FACT |
| **Outbox (client)** | The on-device store of user **intents** not yet accepted by the platform, each carrying an idempotency key generated when the intent is recorded. Holds no authoritative value; distinct from the presentation cache. | FACT |
| **Intent (client)** | A recorded user action awaiting platform acceptance — deliberately distinct from an accomplished fact. The client never presents an intent as complete until the platform confirms it. | FACT |
| **Trip-bound acquisition** | Location acquisition whose lifetime is bound to an active trip, disclosed by an ongoing notification, at a cadence the platform supplies. Zero acquisition occurs outside an active trip. | FACT |
| **Version negotiation** | The startup exchange establishing whether the client's interface version is supported, deprecated or unsupported. An unsupported version is an application state, not an error. | FACT |
| **`BE-nnn`** | A **traceable backend architecture statement** issued by CMP-DOC-09. 218 issued at v0.1, of which 76 are integrity-critical. | FACT |
| **Aggregate** | A cluster of domain state with a single entry point that enforces its own invariants and is loaded and persisted whole. Nine exist: `User`, `Vehicle`, `Ride` (owning `SeatAllocation`), `RideRequest`, `Booking`, `Payment`, `Trip`, `SafetyIncident`, `OperatorCase`. | FACT |
| **Application service** | The single unit of work realising one use case. It owns the transaction boundary, evaluates authorisation, and is the **only** route from any caller — REST, Filament, queue worker or safety surface — into the domain. | FACT |
| **Evidential record** | One entry in the evidential log, capturing actor, action, subject, time, outcome and reason, chained to its predecessor so that alteration is detectable. Written by exactly one component, in the same transaction as the operation it evidences, and never updated or deleted. | FACT |
| **Idempotency registry** | The record that makes a repeated operation produce exactly one effect. Written **in the same transaction as the effect it guards** — a registry entry that could commit separately would not guarantee anything. | FACT |
| **Integrity-critical (‡)** | A marker on a statement whose violation would permit an absolute business rule to be broken. 209 exist across the requirement chain; 76 of them in CMP-DOC-09. | FACT |
| **`Unavailable`** | One of four results a port may return, alongside `Verified`, `Reported` and `Rejected`. It means **nothing was decided** — neither success nor failure. Treating it as either is the single most common way an integration produces a wrong business outcome. | FACT |
| **Structural enforcement rule** | A rule checked by static analysis that fails the build. Eight exist (CMP-DOC-09 §17.2); three are non-suppressible. They are the mechanism by which an architecture document remains true after people stop reading it. | FACT |
| **`API-nnn`** | A **traceable API specification statement** issued by CMP-DOC-10. 216 issued at v0.1, of which 100 are integrity-critical. | FACT |
| **Error branch** | One of the four kinds a failure may be: invalid request, business refusal, dependency unavailable, internal fault. Each has its own status range and body shape, so a client distinguishes them by structure rather than by a code list. | FACT |
| **Idempotency key** | A caller-generated opaque value carried on **every** state-changing request, by which a repeated request produces one effect. Generated when the intent is recorded, not when the request is sent. | FACT |
| **As-of marking** | The time at which the platform evaluated a returned value, carried on every response, with a projection's maintenance time carried additionally where the value came from one. It is what makes client-side provenance truthful rather than assumed. | FACT |
| **Refusal reason identifier** | A stable machine-readable identifier accompanying every business refusal, alongside a human-readable default. The client presents its own localised text keyed by the identifier and falls back to the default for one it does not know. | FACT |
| **Withheld resource** | A resource named in CMP-DOC-10 §11.14 as needed but not specified, because the behaviour it would expose is undecided. Eleven exist. Naming it is how a gap stays visible instead of being filled by invention. | FACT |
| **`DB-nnn`** | A **traceable database design statement** issued by CMP-DOC-11. 232 issued at v0.1, of which 103 are integrity-critical. | FACT |
| **Storage domain** | One of six logically distinct groups of tables within the single MySQL schema, identified by name prefix: `op_` operational, `led_` ledger, `ev_` evidential, `proj_` projections, `mch_` machinery, `cfg_` configuration. Separation is by prefix and by grant, not by server. | FACT |
| **Dual keys** | The pairing of an internal monotonic surrogate primary key, used for clustering, foreign keys and cursors and never exposed, with a separate random external identifier that is the only identifier the interface returns. It satisfies non-enumerability and index locality at once. | FACT |
| **Allocation record** | The single narrow row per ride holding seats offered and seats confirmed, lockable independently of every other ride attribute, and carrying the `CHECK` constraint that makes overselling impossible even when the application is wrong. | FACT |
| **Evidential account** | The database grant under which the application may insert evidential and ledger records but may not update or delete them, and holds no `DDL`. The append-only guarantee is a privilege the account lacks, not a convention it observes. | FACT |
| **In-place removal** | Retention that nulls or tokenises the personal columns of a row rather than deleting the row, so that a counterparty's evidence of a shared record and the evidential hash chain both survive. | FACT |
| **Evidential skeleton** | What remains of a shared record after in-place removal: that it happened, between whom by reference, when, and its outcome. The part another party is entitled to keep. | FACT |
| **`SEC-nnn`** | A **traceable security design statement** issued by CMP-DOC-13. 240 issued at v0.1, of which 135 are integrity-critical. | FACT |
| **Keyed chain** | The evidential chain construction in which each record's integrity value is computed with a key held outside the database. An unkeyed chain can be rewritten wholesale by anyone with write access; a keyed one cannot without the key. | FACT |
| **Prevention by construction** | A control that makes a class of defect impossible to express, rather than detecting or filtering it. Parameter binding against injection, and an absent schema field against authority assertion, are the two in use. | FACT |
| **Detection surface** | The set of recorded signals — assertion attempts, refused authorisations, rate-limit breaches, verification failures — from which a fraud capability could later be built. CMP-DOC-13 §16 provides one and states that it is **not** fraud detection, which requires a policy the platform does not have. | FACT |
| **Non-suppressible check** | An automated verification that cannot be waived by a developer justification. Seven exist in CMP-DOC-13 §19, alongside three in CMP-DOC-09 §17.2. | FACT |
| **Capability, never exemption** | The rule governing operator elevation: a role may grant additional actions and may never permit an absolute business rule to be broken. `NFR-059`, `SEC-010`. | FACT |
| **`UX-nnn`** | A **traceable UI/UX specification statement** issued by CMP-DOC-12. 224 issued at v0.1, of which 84 are integrity-critical. | FACT |
| **Commitment surface** | The single screen preceding every commitment, presenting the six values `NFR-084` requires with their provenance. Its commit control is unavailable while any of them is cached or unknown — which means booking is blocked on a poor connection, deliberately. | FACT |
| **Provenance treatment** | The rendering of a value's origin adjacent to the value itself: `Current` unmarked, `Cached` with its retrieval time, `Unknown` as an explicit unavailable indication and never a placeholder. | FACT |
| **Failure treatment** | One of four visually distinct presentations — inline at field, dialog with reason, persistent banner with retry, plain apology — one per interface error branch, so the user's next action is implied by shape before any text is read. | FACT |
| **Withheld screen** | A screen named in CMP-DOC-12 §17 as needed but not designed, because the use case it would serve is Outlined. Fourteen exist. A speculative screen is worse than an absent one because a screen is a promise to the user. | FACT |
| **`PAY-nnn`** | A **traceable payment specification statement** issued by CMP-DOC-14. 208 issued at v0.1, of which 119 are integrity-critical. | FACT |
| **Courier model** | The treatment of the passenger's UPI application as a carrier rather than a party: it takes the passenger to their bank and back, holds no platform trust, and its participation is not required for the platform to learn the outcome. | FACT |
| **Platform-initiated verification** | Verification the platform performs on its own schedule for **every** payment attempt, including one the passenger abandoned, independent of any callback and of whether the passenger returns. | FACT |
| **`pending` (payment)** | The state of a payment whose outcome the platform has not established. It has **no timeout** and never ages into another state; it is left only by successful verification or by a recorded operator determination. A payment may remain `pending` indefinitely, and that is correct. | FACT |
| **Selection gate** | A criterion a payment provider must meet before it is chosen, as distinct from something discovered during integration. Seven exist; gate 1 — that the platform never receives an instrument credential — is the one on which `SADR-10` entirely depends. | FACT |
| **Unresolved counterparty** | The beneficiary of the onward obligation a verified payment records. The books balance and the platform cannot yet say who it owes, because `BAD-RULE-035` is undecided. | FACT |
| **`GPS-nnn`** | A **traceable live trip specification statement** issued by CMP-DOC-15. 196 issued at v0.1, of which 95 are integrity-critical. | FACT |
| **Trip-bound acquisition** | Location acquisition whose lifetime is exactly an active trip, run in a foreground service whose ongoing notification is the disclosure. Zero acquisition occurs outside a trip, and there is no user-level location setting. | FACT |
| **Two instants** | The pairing carried by every position: the platform's **receipt** instant, which is authoritative and orders the record, and the device's **acquisition** instant, which is never authoritative but is what staleness and the user-facing age are computed from. | FACT |
| **Staleness bound** | The configured age beyond which a position is presented as *last known* rather than *current*. Delivered to the client as configuration, never compiled in, and never derived from cadence. | FACT |
| **Per-booking journey** | One passenger's pickup, drop, progress and outcome within a shared trip. A multi-passenger trip is one trip with one vehicle and one position stream, carrying an independent journey per confirmed booking, so that one passenger's absence invalidates nobody else's booking. | FACT |
| **`NOTIF-nnn`** | A **traceable communication specification statement** issued by CMP-DOC-16. 188 issued at v0.1, of which 100 are integrity-critical. | FACT |
| **Derived membership** | Conversation participation computed from a ride relationship at every read and write, never stored as a grant and never editable. A stale membership record cannot exist because no membership record exists. | FACT |
| **Mandatory category** | A notification category — safety or payment — delivered irrespective of a user's preferences and **absent from the preference surface entirely**, rather than shown disabled. Two exist. | FACT |
| **Value-free notification** | A notification carrying only that an event occurred, its category and where to go — no fare, amount, status, standing, position, contact detail or message content. It is the only surface read outside the application, later, with no provenance and no entitlement check at the moment of reading. | FACT |
| **Delivery is not existence** | The rule that a notification is created and recorded before any delivery attempt and remains in history whether or not the channel delivered it. Push is an accelerator, not the mechanism. | FACT |
| **`ADM-nnn`** | A **traceable administrative specification statement** issued by CMP-DOC-17. 204 issued at v0.1, of which 118 are integrity-critical. | FACT |
| **The binding constraint** | The sixteen statements of CMP-DOC-17 §4 realising `BE-074`–`BE-082`. Chief among them, `ADM-002`: **no Filament resource declares an Eloquent model** — removing the mechanism by which `SRS-RISK-003`, the highest-rated integrity risk in the chain, would occur. | FACT |
| **Composing service** | A purpose-built application service operation serving one administrative detail view, replacing the query with joins that `ADMR-01` forbids. One authorisation evaluation, one consistent read. | FACT |
| **No override exists** | The rule that no force flag, supervisor confirmation or support-only endpoint permits an operator to bypass an absolute rule. A genuine emergency is answered by a policy configuration change or a code change, and `ADM-084` states so before it is needed. | FACT |
| **Withheld capability** | An operator capability named in CMP-DOC-17 §15 as needed but not specified, because its use case is Outlined. Five exist. None is presented disabled, hidden behind a role, or marked forthcoming. | FACT |
| **`TC-nnn`** | A **traceable testing specification statement** issued by CMP-DOC-18. 216 issued at v0.1, of which 129 are integrity-critical. Individual test cases are identified by obligation reference, not by `TC-` identifier. | FACT |
| **Consolidated obligation register** | The single register in CMP-DOC-18 §4 holding all 99 verification obligations from nine documents, each retaining its source, its owning level and its suppressibility. It exists because nine authors each protected what they could see and nobody could see the union. | FACT |
| **Owning level** | The one verification level at which an obligation is authoritatively verified — the earliest level that can verify it conclusively. Verification of the same property elsewhere is recorded as secondary rather than duplicated silently. | FACT |
| **Non-suppressible obligation** | One of the 25 verification obligations for which no suppression mechanism exists. Each guards an absolute rule or a zero-tolerance requirement. A false positive in one is fixed, never disabled. | FACT |
| **Measuring is not verifying** | The rule that where no target exists, an activity is a measurement and is reported as such — never as a passing test, never in a pass count, never contributing to a release gate. Five verification categories are affected. | FACT |
| **Untested because undecided** | A report category distinct from untested-because-unfinished: behaviour that has no test because it has no specification, the withheld resources, tables, screens and capabilities across four documents. | FACT |
| **`OPS-nnn`** | A **traceable deployment specification statement** issued by CMP-DOC-19. 208 issued at v0.1, of which 119 are integrity-critical. | FACT |
| **Sizing register** | The single register in CMP-DOC-19 §4 holding all 21 sizing decisions — 11 from CMP-DOC-07, 7 from CMP-DOC-11 and 3 arising in CMP-DOC-19 — each naming its blocking input and what happens if it is taken wrongly. None is resolved. | FACT |
| **Property, not product** | The rule that every deployment statement specifies a property the deployment must have rather than a product that provides it, because hosting is unselected (`BAD-DEP-009`). Zero providers or products are named in CMP-DOC-19. | FACT |
| **Environment-blind artefact** | One artefact per deployment unit per commit, containing no environment value, no endpoint and no secret, promoted unchanged through all four environments. What passed the gates is what runs. | FACT |
| **Deployment unit** | One of the five independently deployable and restartable parts of the platform: application, safety surface, general worker, safety worker, scheduler. | FACT |
| **The safety exception** | The rule that no maintenance action, scaling policy, deployment freeze, rate limit, quota or shared-capacity arrangement applies to the safety units, and that on a shared-code change the safety unit deploys first. | FACT |
| **Instrumented and unalerted** | The platform's position from first release: 18 measurement points exist and no alert threshold does, because every target is unset. Four conditions alert unconditionally; everything else is measured and reported to a human. | FACT |
| **Incomplete procedure** | A procedure this documentation chain requires, specified in structure, that cannot be executed because a decision it depends on does not exist. Two exist, both in CMP-DOC-19. | FACT |
| **`TR-nnn`** | A **traceable traceability and release statement** issued by CMP-DOC-20. 192 issued at v0.1, of which 92 are integrity-critical. | FACT |
| **Citation coverage** | The proportion of upstream requirements that at least one later document cites by identifier. Measured at 747 of 955. It is **not** verification coverage and **not** proof of realisation; CMP-DOC-20 `TRDR-03` forbids reporting it as either. | FACT |
| **The 37** | The integrity-critical requirements that no downstream document cited by identifier. **Reviewed in CMP-DOC-20A**: six were already cited in an abbreviated notation, leaving **31**, of which 27 are realised, 3 need a gap and 1 is superseded. None was closed by assertion. | FACT |
| **Abbreviated linkage** | CMP-DOC-04's practice of recording each functional requirement's business-requirement linkage as bare three-digit numbers in a column rather than as `BRD-REQ-nnn`. It is a real citation and is invisible to an identifier-based measure; it caused CMP-DOC-20 v0.1 to overstate the uncited set by 31. | FACT |
| **Outcome 0** | The fifth review outcome, added by CMP-DOC-20A: the requirement was already cited in a notation the measure could not read. The correction belongs to the measure, not to the documents. | FACT |
| **Forward traceability** | Whether a requirement is cited by a later document. Measurable only across the whole chain, and therefore only once, at the end. Incomplete: 208 uncited. | FACT |
| **Backward traceability** | Whether every citation resolves to a statement that exists. Complete: 0 unresolved across the chain. | FACT |
| **Baseline order** | The rule that a document may be baselined only when every predecessor it cites is baselined, so approval proceeds CMP-DOC-01 first and CMP-DOC-20 last. | FACT |
| **`Incomplete`** | A release-report status distinct from `Specified` and `Not started`: the work is done and cannot be executed. Created by CMP-DOC-20 `TRDR-12` for the two procedures CMP-DOC-19 owes. | FACT |
| **Nine report categories** | The release report's shape: CMP-DOC-18's six, plus open sizing decisions, incomplete procedures and undischarged obligations. **None may be collapsed into another and no single figure may be derived from them.** | FACT |
| **Auxiliary BRD prefixes** | The 10 non-traceable element prefixes introduced by CMP-DOC-02 §0.10.1 (`BRD-SR-`, `BRD-DATA-`, `BRD-RPT-`, `BRD-INT-`, `BRD-CMP-`, `BRD-ASM-`, `BRD-CON-`, `BRD-DEP-`, `BRD-RISK-`, `BRD-OQ-`). Registered in `Documentation_Index.md` §5.2. | ASSUMPTION — pending `BAD-DEC-024` |
| **Auxiliary BAD prefixes** | The 15 non-traceable element prefixes introduced by CMP-DOC-01 §0.10 (`BAD-OBJ-`, `BAD-SC-`, `BAD-SH-`, `BAD-PER-`, `BAD-BP-`, `BAD-RULE-`, `BAD-CAP-`, `BAD-RISK-`, `BAD-CON-`, `BAD-DEP-`, `BAD-OPP-`, `BAD-KPI-`, `BAD-ASM-`, `BAD-OQ-`, `BAD-DEC-`). Registered in `Documentation_Index.md` §5.1. | ASSUMPTION — pending `BAD-DEC-024` |
| **Absolute rule** | A business rule CMP-DOC-01 marks as non-negotiable because it protects money integrity, seat integrity, safety, or the legal position. Ten such rules exist at v0.1. | FACT |
| **Release gate** | A success criterion CMP-DOC-01 §9.4 recommends treating as a pass/fail condition for release rather than a target. | RECOMMENDATION |

## 12. Terms Explicitly Not In Scope

| Term | Note |
|---|---|
| **Supabase** | Explicitly excluded from the CMP architecture. |
| **PostgreSQL** | Explicitly excluded from the CMP architecture. |
| **Spring Boot** | Explicitly excluded from the CMP architecture. |

## 13. Open Questions

| # | Question | Decision Type | Tracked in CMP-DOC-01 |
|---|---|---|---|
| G-Q01 | What is the final product/brand name, and does it replace *Points* / *Beride Points* terminology? | Business Decision Required | `BAD-DEC-023` |
| G-Q02 | Is the Wallet a real-money instrument, a rewards-only instrument, or both? | Business Decision Required | `BAD-DEC-013` |
| G-Q03 | Does a Ride Request always require Driver acceptance, or can it be auto-accepted? | Business Decision Required | `BAD-DEC-007` |
| G-Q04 | What are the permitted values of User Status, Booking Status, and Trip State? | Business Decision Required | `BAD-DEC-006`, `BAD-DEC-015` |
| G-Q05 | Which payment service provider will be used for UPI initiation and verification? | Technical Decision Required | `BAD-DEP-004` |
| G-Q06 | What verification levels exist, and what qualifies a User as a Verified Peer? | Business Decision Required | `BAD-DEC-005` |

> **Note.** All six glossary open questions raised at v0.1 are now tracked against
> identified decision owners in CMP-DOC-01 §27. CMP-DOC-01 raises a further 31 open
> questions (`BAD-OQ-001`…`BAD-OQ-031`) and 24 required business decisions in total.

---

*End of Glossary — CMP*
