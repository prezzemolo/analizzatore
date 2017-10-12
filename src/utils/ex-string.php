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

  public static function list_encodings_with_alias () {
    $list = [];
    foreach(mb_list_encodings() as $encoding) {
      array_push($list, $encoding);
      $list = array_merge($list, mb_encoding_aliases($encoding));
    }
    return $list;
  }

  public static function check_encoding_loadable (string $encoding) {
    $encodings = self::list_encodings_with_alias();
    return in_array($encoding, $encodings);
  }
}
