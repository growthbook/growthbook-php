<?php

namespace GrowthBook;

/**
 * @deprecated
 */
class Util
{
    /**
     * @param string $userId
     * @param Experiment<mixed> $experiment
     * @return int
     */
    public static function chooseVariation(string $userId, Experiment $experiment): int
    {
        $testId = $experiment->key;
        $weights = $experiment->getScaledWeights();

        // Hash the user id and testName to a number from 0 to 1
        $n = (hexdec(hash("fnv1a32", $userId . $testId)) % 1000) / 1000;

        $cumulativeWeight = 0;
        foreach ($weights as $i => $weight) {
            $cumulativeWeight += $weight;
            if ($n < $cumulativeWeight) {
                return $i;
            }
        }

        return -1;
    }

    public static function getQueryStringOverride(string $id): ?int
    {
        if (array_key_exists($id, $_GET)) {
            $val = (int) $_GET[$id];
            if ($val >= -1 && $val < 20) {
                return $val;
            }
        }

        return null;
    }

    private static function url_origin(): string
    {
        $ssl      = ($_SERVER['HTTPS']??'off')==='on';
        $protocol = $ssl ? 'https' : 'http';
        $port     = $_SERVER['SERVER_PORT'] ?? ($ssl ? '443' : '80');
        $port     = ((!$ssl && $port=='80') || ($ssl && $port=='443')) ? '' : ':'.$port;
        $host     = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME']??"localhost").$port;
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
            if (preg_match('/'.$escaped.'/', $url)) {
                return true;
            }
            if (preg_match('/'.$escaped.'/', $pathOnly)) {
                return true;
            }
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
