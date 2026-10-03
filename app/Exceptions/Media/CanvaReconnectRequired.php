<?php

declare(strict_types=1);

namespace App\Exceptions\Media;

use RuntimeException;

/**
 * Canva refused the stored refresh token (revoked or expired). The
 * connection is already deleted; the user has to sign in again.
 */
class CanvaReconnectRequired extends RuntimeException {}
