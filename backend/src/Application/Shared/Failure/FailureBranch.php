<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Failure;

/**
 * The four branches, and only four.
 *
 * `BE-185`: exceptions fall into exactly these four. `API-071` ‡: every failure
 * is represented as exactly one of them. `API-072` ‡: each has its own status
 * code range and its own body shape at the interface, distinguishable by
 * structure alone — but the mapping to transport lives at the interface layer
 * (`BADR-17`, `BE-005`), not here.
 *
 * The set is closed. Adding a fifth branch would contradict `API-071` ‡ and is
 * asserted against in ErrorModelStructureTest.
 */
enum FailureBranch
{
    /** The command was malformed. The caller can correct it. */
    case InvalidRequest;

    /** A rule declined the operation. Retry will not help. */
    case BusinessRefusal;

    /** An external capability did not answer. Nothing was decided. */
    case DependencyUnavailable;

    /** The platform failed. Not the caller's concern. */
    case InternalFault;
}
