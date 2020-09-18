<?php

namespace Growthbook;

class Util
{
  public static function checkRule(string $actual, string $op, string $desired): bool
  {
    switch ($op) {
      case "=":
        return $actual === $desired;
      case "!=":
        return $actual !== $desired;
      case ">":
        return strnatcmp($actual, $desired) > 0;
      case "<":
        return strnatcmp($actual, $desired) < 0;
      case "~":
        return !!preg_match("/" . $desired . "/", $actual);
      case "!~":
        return !preg_match("/" . $desired . "/", $actual);
    }

    trigger_error("Unknown targeting rule operator: " . $op);

    return true;
  }

  public static function chooseVariation(string $userId, Experiment $experiment): int
  {
    $testId = $experiment->id;
    $weights = $experiment->getScaledWeights();

    // Hash the user id and testName to a number from 0 to 1
    $n = (hexdec(hash("fnv1a32", $userId . $testId)) % 1000) / 1000;

    $cumulativeWeight = 0;
    foreach ($weights as $i => $weight) {
      $cumulativeWeight += $weight;
      if ($n < $cumulativeWeight) return $i;
    }

    return -1;
  }

  public static function getQueryStringOverride(string $id): ?int
  {
    if (array_key_exists($id, $_GET)) {
      $val = (int) $_GET[$id];
      if ($val >= -1 && $val < 20) return $val;
    }

    return null;
  }
}
