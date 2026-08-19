# `Cmp\Infrastructure\Adapter` — port adapters

**The only place a provider type may appear.** `BE-153` ‡ forbids one above the
adapter, and `PortRulesTest` fails the build if one is found elsewhere.

An adapter:

- implements a port declared in `Cmp\Domain` (`BE-036`, `BE-149`);
- translates a provider error into a {@see \Cmp\Domain\Shared\Port\CapabilityResult}
  **without leaking the provider representation** (`BE-154`);
- **reports what the provider said and decides no business outcome** (`BE-156` ‡)
  — a rejection is a rejection, not a refusal; the domain decides what it means;
- is invoked **outside a transaction** (`BE-155`, `BE-050` ‡);
- is substitutable without change above `Infrastructure` (`BE-162`);
- has a test double exercising **every** one of the four results (`BE-163`).

Timeout, retry and circuit behaviour are the adapter's, governed by
configuration (`BE-157`).

## Nothing is here

`BE-161`: the five providers are `[TBD – Business Decision Required]` except
mapping and notification, which are directed. None of the five ports declares an
operation yet, so there is nothing to adapt.

**`EmergencyDispatchPort` is not merely unimplemented — it is withheld.**
`BAD-DEC-011` is open and no response capability is staffed. `BAD-RISK-005`: a
safety control with no response behind it is a liability. An adapter for it must
not appear, and the build fails if one does.
