<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Evidence;

use RuntimeException;

/**
 * The evidential record could not be written.
 *
 * `FRD-FR-248` ‡: *"The system shall not report an action as complete where its
 * auditable record cannot be written."* This is raised rather than swallowed for
 * that reason — a caught-and-ignored failure here would produce exactly the
 * silent, unevidenced action the requirement exists to prevent.
 *
 * It is **not** a business refusal. `BE-186` ‡ keeps a platform fault distinct
 * from a decision the platform made, and failing to write evidence is a fault.
 * It therefore propagates, rolls back its transaction (`BE-190`), and reaches
 * the caller as an internal fault carrying a correlation identity and nothing
 * else (`API-092` ‡).
 */
final class EvidenceNotRecorded extends RuntimeException {}
