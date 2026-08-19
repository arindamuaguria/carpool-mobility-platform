<?php

declare(strict_types=1);

/*
 * No browser-facing surface is specified for the platform. The client is the
 * Android application and the operator surface is the administrative interface
 * (CMP-DOC-07 ARCH-016, CMP-DOC-09 BE-013). The REST interface is mounted under
 * routes/api.php at /api/v1 (CMP-DOC-10 §5).
 *
 * The liveness endpoint /up is registered by bootstrap/app.php.
 */
