<?php

declare(strict_types=1);

namespace App\Gmail;

use RuntimeException;

/**
 * Gmail OAuth / token hiba (visszavont vagy lejárt kapcsolat, sikertelen csere).
 */
final class GmailAuthException extends RuntimeException
{
}
