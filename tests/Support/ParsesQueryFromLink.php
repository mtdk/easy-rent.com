<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Helper for parsing query params from application-generated links.
 */
trait ParsesQueryFromLink
{
    /**
     * @return array<string, string>
     */
    protected function queryFromLink(string $link): array
    {
        $queryString = (string) parse_url($link, PHP_URL_QUERY);
        $query = [];
        parse_str($queryString, $query);

        return is_array($query) ? $query : [];
    }
}
