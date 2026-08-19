# `Cmp\Infrastructure` — the Infrastructure layer

**Contract:** `BE-008`, `BE-009`.

Contains repository implementations, port adapters, projection maintenance, the
evidential writer, the policy configuration store and job implementations
(`BE-008`). ORM models are an infrastructure detail and appear **nowhere else**
(`BE-009`).

| Directory | Holds | Source |
|---|---|---|
| `Persistence/` | Repository implementations and ORM models | `BE-037`, `BADR-05` |
| `Evidential/`  | The single evidential writer — the only path into `ev_` | `BADR-09`, `BE-105` |
| `Projection/`  | Projection maintenance and read models | `BADR-10` |
| `Adapter/`     | Port adapters; provider types appear no higher | `BADR-11`, `BE-153` |
| `Policy/`      | Typed, versioned, cached policy configuration store | `BADR-12` |
| `Job/`         | Job implementations for the seven job families | `BADR-07` |
| `Laravel/`     | Framework composition — providers and bootstrapping | `BE-008` |

Depends inward on `Cmp\Application` and `Cmp\Domain`. Nothing depends on it at
compile time; it is wired in at the composition root.
