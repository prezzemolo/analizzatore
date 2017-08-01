<?php

namespace analizzatore\utils;

class ExString {
  public static function startsWith (string $target, string $prefix) {
    return mb_strpos($target, $prefix) === 0;
  }

  public static function endsWith (string $target, string $suffix) {
    $endPosision = mb_strlen($target) - mb_strlen($suffix);
    if ($endPosision <= 0) return false;
    return mb_strpos($target, $suffix) === $endPosision;
  }
}
