<?php

declare(strict_types=1);

namespace Bluehost\VerifactiApi\Support;

/**
 * Helpers for reading nested values from decoded API response arrays.
 */
final class ResponseAccessor
{
    /**
     * Return the first non-null value found across multiple dot-paths.
     *
     * @param array<string, mixed> $data    Response data.
     * @param array<int, string>   $paths   Dot-paths to inspect in order.
     * @param mixed                $default Default value when no path matches.
     *
     * @return mixed
     */
    public static function first(array $data, array $paths, mixed $default = null): mixed
    {
        foreach ($paths as $path) {
            $value = self::get($data, $path);
            if ($value !== null) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * Return a nested value from a dot-path within a response array.
     *
     * @param array<string, mixed> $data    Response data.
     * @param string               $path    Dot-separated path.
     * @param mixed                $default Default value when the path is missing.
     *
     * @return mixed
     */
    public static function get(array $data, string $path, mixed $default = null): mixed
    {
        $segments = explode('.', $path);
        $value = $data;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
