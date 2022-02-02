<?php

namespace Growthbook;

class Util
{
    public static function hash(string $str): float
    {
        $n = hexdec(hash("fnv1a32", $str));
        return ($n % 1000) / 1000;
    }

    public static function inNamespace(string $userId, array $namespace): bool
    {
        if (count($namespace) < 3) return false;
        $n = static::hash($userId . "__" . $namespace[0]);
        return $n >= $namespace[1] && $n < $namespace[2];
    }

    public static function getEqualWeights(int $numVariations): array
    {
        $weights = [];
        for ($i = 0; $i < $numVariations; $i++) {
            $weights[] = 1 / $numVariations;
        }
        return $weights;
    }

    public static function getBucketRanges(int $numVariations, float $coverage, array $weights): array
    {
        $coverage = max(0, min(1, $coverage));

        if (count($weights) !== $numVariations) {
            $weights = static::getEqualWeights($numVariations);
        }
        $sum = array_sum($weights);
        if ($sum < 0.99 || $sum > 1.01) {
            $weights = static::getEqualWeights($numVariations);
        }

        $cumulative = 0;
        $ranges = [];
        foreach ($weights as $weight) {
            $start = $cumulative;
            $cumulative += $weight;
            $ranges[] = [$start, $start + $coverage * $weight];
        }
        return $ranges;
    }

    public static function chooseVariation(float $n, array $ranges): int
    {
        foreach ($ranges as $i => $range) {
            if ($n >= $range[0] && $n < $range[1]) {
                return (int) $i;
            }
        }
        return -1;
    }

    public static function getQueryStringOverride(string $id, string $url, int $numVariations): ?int
    {
        // Extract the querystring from the url
        /** @var string|false */
        $query = parse_url($url, PHP_URL_QUERY);
        if(!$query) return null;

        // Parse the query string and check if $id is there
        parse_str($query, $params);
        if(!isset($params[$id]) || !is_numeric($params[$id])) {
            return null;
        }

        // Make sure it's a valid variation integer
        $variation = (int) $params[$id];
        if($variation < 0 || $variation >= $numVariations) {
            return null;
        }

        return $variation;
    }



    private static function url_origin(): string
    {
        $ssl      = ($_SERVER['HTTPS'] ?? 'off') === 'on';
        $protocol = $ssl ? 'https' : 'http';
        $port     = $_SERVER['SERVER_PORT'] ?? ($ssl ? '443' : '80');
        $port     = ((!$ssl && $port == '80') || ($ssl && $port == '443')) ? '' : ':' . $port;
        $host     = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? "localhost") . $port;
        return $protocol . '://' . $host;
    }

    private static function full_url(): string
    {
        return static::url_origin() . $_SERVER['REQUEST_URI'];
    }

    public static function urlIsValid(string $urlRegex, string $url = ''): bool
    {
        // Need to escape this twice to catch 2 slashes next to each other (e.g. "http://localhost")
        $escaped = preg_replace('/([^\\\\])\\//', '$1\\/', $urlRegex);
        if (!$escaped) {
            return false;
        }
        $escaped = preg_replace('/([^\\\\])\\//', '$1\\/', $escaped);
        if (!$escaped) {
            return false;
        }

        $url = $url ? $url : static::full_url();
        $pathOnly = $_SERVER['REQUEST_URI'];

        try {
            if (preg_match('/' . $escaped . '/', $url)) {
                return true;
            }
            if (preg_match('/' . $escaped . '/', $pathOnly)) {
                return true;
            }
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
