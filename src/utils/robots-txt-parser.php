<?php

namespace analizzatore\utils;

require_once join(DIRECTORY_SEPARATOR, [__DIR__, 'ex-string.php']);

function robots_txt_pathvalue_to_regexp (string $v): string {
  $rv = preg_quote($v, '~');
  // enable '\*'
  $rv = str_replace( '\*', '.*', $rv );
  // enable '\$'
  $rv = str_replace( '\$', '$', $rv );
  return $rv;
}

// return 'regexp' mathcer string, to use with REQUEST_URI
function parse_robots_txt (string $body, ?string $user_agent = null) {
  $f_s = explode('User-agent:', $body);
  // drop first value, only need values related to 'User-agent'
  array_shift($f_s);
  $s_s = array_map(function ($v) use ($user_agent) {
    list($ua_in_ss, $content_in_ss) = array_map('trim', preg_split('/\r?\n/', trim($v), 2));

    // create user-agent matcher, only enables '*' element
    $uam = '~' . str_replace('\*', '.*', preg_quote($ua_in_ss)) . '~';
    // only continue with matched user-agent
    if ($user_agent && !preg_match($uam, $user_agent)) return null;

    $regexps_disallow_in_ss = array();
    $regexps_allow_in_ss = array();
    foreach (preg_split('/\r?\n/', trim($content_in_ss)) as $v) {
      $line_in_content = trim($v);
      # there are no content in a line, skip
      if (!$line_in_content) continue;
      # a line starts with '#', skip
      if (strpos($line_in_content, '#') === 0) continue;
      list($name_in_ra_in_ss, $val_in_ra_in_ss) = array_map('trim', explode(':', $line_in_content));
      # check & cut off comments
      if (($val_hash_position = strpos($val_in_ra_in_ss, '#')) !== false)
        $val_in_ra_in_ss = trim(substr($val_in_ra_in_ss, 0, $val_hash_position));
      # if value is empty, skip
      if (empty($val_in_ra_in_ss)) continue;
      # if value is not started with '/', add
      if (!ExString::startsWith($val_in_ra_in_ss, '/'))
        $val_in_ra_in_ss = '/' . $val_in_ra_in_ss;
      # check field name be in 'pathmemberfield'
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
      'User-Agent' => $uam,
      'Disallow' =>
        count($regexps_disallow_in_ss) === 0 ? null
          : '~(?:^' . implode(')|(?:^', $regexps_disallow_in_ss) . ')~',
      'Allow' =>
        count($regexps_allow_in_ss) === 0 ? null
          : '~(?:^' . implode(')|(?:^', $regexps_allow_in_ss) . ')~',
      'Instant' => false
    ];
  }, $f_s);
  // remove NULL in array
  return array_filter($s_s, function ($v) {
    return !is_null($v);
  });
}
