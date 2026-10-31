<?php

declare(strict_types=1);

namespace Observability\Http;

use function is_string;
use function parse_url;
use function str_contains;
use function str_starts_with;
use function strpos;
use function substr;
use function trim;

/**
 * Menentukan label route dari request. Nilai yang dipakai harus template route
 * seperti /users/{id}, bukan URL mentah, supaya jumlah label metrics tetap
 * terbatas.
 */
final class RouteTemplateResolver
{
    public const UNMATCHED = 'unmatched';

    /** @var callable(mixed): (string|null)|null */
    private $resolver;

    /**
     * @param callable(mixed): (string|null)|null $resolver
     */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver;
    }

    public function resolve(mixed $source = null): string
    {
        if ($this->resolver === null) {
            return self::UNMATCHED;
        }

        $value = ($this->resolver)($source);

        return self::normalize(is_string($value) ? $value : null);
    }

    public static function normalize(?string $route): string
    {
        $route = trim((string) $route);
        if ($route === '') {
            return self::UNMATCHED;
        }

        if (str_contains($route, '://')) {
            $path = parse_url($route, PHP_URL_PATH);
            $route = is_string($path) ? $path : '';
        }

        if (!str_starts_with($route, '/')) {
            return self::UNMATCHED;
        }

        $query = strpos($route, '?');
        if ($query !== false) {
            $route = substr($route, 0, $query);
        }

        $fragment = strpos($route, '#');
        if ($fragment !== false) {
            $route = substr($route, 0, $fragment);
        }

        return $route === '' ? self::UNMATCHED : $route;
    }
}
