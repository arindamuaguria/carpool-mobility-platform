# `Cmp\Domain` — the Domain layer

**Contract:** `BE-002`, `BE-003`, `BE-007`, `BE-010`, `BE-012`, `BE-040`.

- References **no framework type** (`BE-002`). No Illuminate, no Eloquent, no facade,
  no attribute from a package.
- Depends on **nothing** (`BE-003`). Nothing outside `Cmp\Domain` may be imported here.
- Contains aggregates, invariants, domain events, port interfaces and repository
  interfaces — and nothing else (`BE-007`).
- Each business rule lives in **exactly one** Domain component (`BE-010`).
- Absolute business rules live here and are **not reachable for override** (`BE-012`).
- Unit-testable without a database, a framework or a network (`BE-040`, `TC-029`).

Ports are declared here, in domain terms, naming no provider (`BE-036`, `BE-150`),
and every one returns a `CapabilityResult` — `Verified`, `Reported`, `Unavailable`
or `Rejected` (`BE-151`). A provider type appears only in an adapter (`BE-153` ‡).

Organised by domain area (`BE-004`). The nine aggregates are fixed by `BE-017`:
`User`, `Vehicle`, `Ride`, `RideRequest`, `Booking`, `Payment`, `Trip`,
`SafetyIncident`, `OperatorCase`. `Ride` owns `SeatAllocation` (`BE-018`).
`Shared/` holds domain primitives common to more than one area.
