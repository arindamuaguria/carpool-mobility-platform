<?php

declare(strict_types=1);

namespace Cmp\Domain\Shared\Notification;

use Cmp\Domain\Shared\Port\Port;

/**
 * Delivery of a notification to a person's device.
 *
 * **No operation is declared yet.** The notification categories, their payload
 * and the delivery record belong to CMP-DOC-16 and FEAT-023, and `CMP-IMP-334`
 * is the work item that implements this port's operations.
 *
 * `BE-161` records this provider as directed rather than undecided; CMP-DOC-01 §2
 * is where it is named. It is **not** named here: `BE-150` forbids a port naming
 * a provider, and `BE-162` requires the adapter to be substitutable without
 * change above `Infrastructure` — neither of which survives a port that has the
 * supplier written into its own documentation.
 *
 * `NOTIF-187` (`TC-037` rule 12) attaches here: **no business value type reaches
 * a notification payload.** A fare or a seat count in a push message is a
 * business value the client would then hold outside the platform's authority.
 */
interface NotificationPort extends Port {}
