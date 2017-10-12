<?php

namespace analizzatore\utils;

function robots_txt_pathvalue_to_regexp (string $v): string {
  $rv = preg_quote($v, '~');
  // enable '\*'
  $rv = str_replace( '\*', '.*', $rv );
  // enable '\$'
  $rv = str_replace( '\$', '$', $rv );
  return $rv;
}

// return 'regexp' mathcer string, to use with REQUEST_URI
function parse_robots_txt (string $user_agent, string $body) {
  $f_s = explode('User-agent:', $body);
  $s_s = array_map(function ($v) {
    list($ua_in_ss, $content_in_ss) = preg_split('/\r?\n/', $v, 2);
    $ua_in_ss = trim($ua_in_ss);
    if (($ua_hash_position = strpos($ua_in_ss, '#')) !== false) {
      // if user-agent starts with #, maybe comments (non-block)
      if ($ua_hash_position === 0) return null;
      // cut off comment
      $ua_in_ss = trim(substr($ua_in_ss, 0, $ua_hash_position));
    }
    $regexps_disallow_in_ss = array();
    $regexps_allow_in_ss = array();
    foreach (preg_split('/\r?\n/', trim($content_in_ss)) as $v) {
      list($name_in_ra_in_ss, $val_in_ra_in_ss) = array_map(function ($v) {
        return trim($v);
      }, explode(':', $v));
      // check & cut off comments
      if (($val_hash_position = strpos($val_in_ra_in_ss, '#')) !== false) {
        // cut off comment
        $val_in_ra_in_ss = trim(substr($val_in_ra_in_ss, 0, $val_hash_position));
      }
      // check field name be in 'pathmemberfield'
      switch (strtolower($name_in_ra_in_ss)) {
        case 'disallow':
          array_push($regexps_disallow_in_ss, robots_txt_pathvalue_to_regexp($val_in_ra_in_ss));
          break;
        case 'allow':
          array_push($regexps_allow_in_ss, robots_txt_pathvalue_to_regexp($val_in_ra_in_ss));
          break;
      }
    }
    return [
      'User-Agent' => $ua_in_ss,
      'Disallow' =>
        count($regexps_disallow_in_ss) === 0 ? null
          : '~(?:' . implode(')|(?:', $regexps_disallow_in_ss) . ')~',
      'Allow' =>
        count($regexps_allow_in_ss) === 0 ? null
          : '~(?:' . implode(')|(?:', $regexps_allow_in_ss) . ')~'
    ];
  }, $f_s);
  // remove NULL in array
  return array_filter($s_s, function ($v) {
    return !is_null($v);
  });
}
