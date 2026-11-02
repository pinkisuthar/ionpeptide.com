<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Exception;

/**
 * Thrown when the Verifacti API rejects the request due to invalid credentials.
 */
class AuthenticationException extends HttpException
{
}
