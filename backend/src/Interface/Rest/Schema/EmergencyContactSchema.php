<?php

declare(strict_types=1);

namespace Cmp\Interface\Rest\Schema;

use Cmp\Interface\Rest\RequestSchema;

/**
 * What a nomination or an amendment may contain — **the platform's first
 * request schema**.
 *
 * `RequestSchema` arrived with `CMP-IMP-468` and had no subclass anywhere in
 * `src/` until now, for a reason recorded in `CC-042`: the three operations the
 * surface served accepted no body at all. `RefuseAssertedAuthority` was built as
 * the floor beneath this, on every route; this is the layer above it, and the two
 * do different work.
 *
 * - The middleware refuses a field naming one of `API-037` ‡'s seven
 *   authoritative values, on **every** request, whether or not the operation has
 *   a schema.
 * - A schema refuses **any** field the operation does not define (`API-038` ‡),
 *   which is stricter and needs to know what the operation accepts.
 *
 * ## Two fields, and the honest reason there are only two
 *
 * `phone_number` and `label` are what `op_user_emergency_contacts` holds, and
 * that table's columns were taken at the narrowest reading CMP-DOC-11 §6.2
 * supports — it describes the table in one line and specifies no column. There
 * is no `email`, no `channel` and no `relationship`; see the migration for why
 * each is absent, and `CC-044` for the record.
 *
 * `API-040` ‡ / `API-041`: this checks the **shape** and nothing else. Whether
 * `phone_number` is a number the platform can use is `ContactDetails`', in the
 * application layer, where `FRD-FR-183`'s reason is built — well-formedness
 * *"shall not substitute for"* validation against platform state.
 */
final class EmergencyContactSchema extends RequestSchema
{
    /**
     * @return list<string>
     */
    public function fields(): array
    {
        return ['phone_number', 'label'];
    }

    /**
     * The resource, rather than the method.
     *
     * `API-039` ‡'s record names the operation an assertion was attempted
     * against, and `BE-107` ‡ wants what an operator investigating it would
     * search for. A caller asserting a fare against this resource has done the
     * same thing whether they sent it to the `POST` or the `PUT`.
     */
    public function operation(): string
    {
        return 'profile/emergency-contacts';
    }
}
