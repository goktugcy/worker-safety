<?php

declare(strict_types=1);

// Top-level bootstrap code normally runs once per worker boot, so the severity
// is reduced rather than reported as a per-request mutation.
putenv('APP_BOOTED=1');
ini_set('display_errors', '0');
