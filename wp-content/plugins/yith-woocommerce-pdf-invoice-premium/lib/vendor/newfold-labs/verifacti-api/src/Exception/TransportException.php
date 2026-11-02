<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Exception;

/**
 * Thrown when the HTTP transport layer fails before a response is received.
 */
class TransportException extends VerifactiException
{
}
