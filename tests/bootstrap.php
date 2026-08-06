<?php

declare(strict_types=1);

/**
 * The mapping logic is plain PHP and does not need Laravel booted, so the
 * suite runs anywhere. What does touch the framework — routes, Eloquent — is
 * exercised inside a real site instead, which is the only place it means
 * anything.
 */
require_once __DIR__.'/../src/Content/ContentType.php';
require_once __DIR__.'/../src/Support/ConnectorException.php';
