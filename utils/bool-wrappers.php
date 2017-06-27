<?php

namespace analizzatore\utils;

function strcasecmpbool ($str1, $str2) {
  return strcasecmp($str1, $str2) === 0;
}

?>
