<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Support;

/**
 * Contract for objects that can be converted to API request arrays.
 */
interface Arrayable
{
    /**
     * Convert the object to an associative array suitable for API requests.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
