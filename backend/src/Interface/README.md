# `Cmp\Interface` — the Interface layer

**Contract:** `BE-005`, `BE-011`, `BE-013`.

Adapters only. **No business rule** (`BE-005`, `BE-011`). The four callers reach the
domain only through `Cmp\Application` (`BE-013`).

| Directory | Surface | Source |
|---|---|---|
| `Rest/`    | The versioned REST interface at `/api/v1` | `BADR-01`, `AADR-03` |
| `Admin/`   | Filament resources — call application services, never the ORM | `BADR-15`, `BE-075` |
| `Safety/`  | The separately bootable safety entry point | `BADR-16`, `BE-191` |
| `Console/` | Console commands | `BADR-01` |

Organised by domain area beneath each surface (`BE-004`).
