<?php

declare(strict_types=1);

/*
 * The evidential chain key.
 *
 * SEC-106 ‡: held **outside the database** and not readable through a database
 * connection. SADR-14 / OPS-098: injected at deploy time, and never present in
 * an artefact, a repository or a build log.
 *
 * SEC-172 ‡: the key is escrowed. Losing it does not make the records
 * unreadable — it makes the guarantee that they are unaltered unverifiable,
 * which is the whole of what SADR-07 buys. Escrow is an operational obligation
 * and no code here can discharge it.
 *
 * SEC-171 records rotation periods as [TBD – Business Decision Required] and
 * states none. Rotating this key requires a staged change — SEC-174 — and the
 * chain_algorithm column on each record is what makes that stageable.
 */

return [
    'chain_key' => env('EVIDENTIAL_CHAIN_KEY'),
];
