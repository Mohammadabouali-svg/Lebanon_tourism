<?php

namespace App\Support;

final class Search
{
    /*
    | Turns user input into a safe "contains" pattern for LIKE / ILIKE.
    |
    | The query is parameterised (no SQL injection possible), but % and _
    | are wildcards *inside* LIKE patterns. Without escaping, searching for
    | "100%" or "_" would match far more than intended.
    */
    public static function containsPattern(string $term): string
    {
        return '%'.addcslashes(trim($term), '\\%_').'%';
    }
}
