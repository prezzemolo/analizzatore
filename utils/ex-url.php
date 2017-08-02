<?php

namespace analizzatore\utils;

require_once join(DIRECTORY_SEPARATOR, [__DIR__, 'ex-string.php']);

class ExUrl {
  public static function assemble ($parsed_url) {
    // if false, return value when url_parse failed
    if ($parsed_url === false) return null;

    // mean return value scheme/host/content
    $rvs = $rvh = $rvc = $rv = '';

    if (array_key_exists('scheme', $parsed_url)) $rvs .= $parsed_url['scheme'] . ':';

    // existance flag of user and/or password;
    $account = false;
    if (array_key_exists('user', $parsed_url)) {
      $rvh .= $parsed_url['user'];
      $account = true;
    }
    if (array_key_exists('pass', $parsed_url)) {
      $rvh .= ':' . $parsed_url['pass'];
      $account = true;
    }
    if ($account) $rvh .= '@';
    if (array_key_exists('host', $parsed_url)) $rvh .= $parsed_url['host'];
    if (array_key_exists('port', $parsed_url)) $rvh .= ':' . $parsed_url['port'];

    if (array_key_exists('path', $parsed_url)) $rvc .= $parsed_url['path'];
    if (array_key_exists('query', $parsed_url)) $rvc .= '?' . $parsed_url['query'];
    if (array_key_exists('fragment', $parsed_url)) $rvc .= ':' . $parsed_url['fragment'];

    // assemble
    $rv .= $rvs;
    if ($rvh) $rv .= '//' .$rvh;
    return $rv . $rvc;
  }

  public static function join ($base, $addition) {
    // arguments can't be null
    if ($base === null || $addition === null) return null;

    $base_components = parse_url($base);
    $addition_components = parse_url($addition);

    // no future
    if (!$base_components || !$addition_components) return null;

    if (array_key_exists('scheme', $addition_components) || array_key_exists('host', $addition_components)) {
      var_dump('scheme or host!');
      return self::assemble(
        array_merge([
          'scheme' => $base_components['scheme']
        ], $addition_components)
      );
    }

    // path compilation
    $path = '';
    # copy base & remove path, query and fragment
    $merge_base = $base_components;
    unset($merge_base['path']);
    unset($merge_base['query']);
    unset($merge_base['flagment']);
    $base_path =
      array_key_exists('path', $base_components)
      ? $base_components['path']
      : '/';
    var_dump($base_path);
    $addition_path =
      array_key_exists('path', $addition_components)
      ? $addition_components['path']
      : '';
    var_dump($addition_path);
    if (ExString::startsWith($addition_path, '/')) {
      $path = $addition_path;
    } else {
      if (ExString::endsWith($base_path, '/')) {
        $path = $base_path . $addition_path;
      } else {
        # for relative path: '/abc/cde' + 'fgh' = '/abc/fgh'
        $path_components = explode('/', $base_path, -1);
        array_push($path_components, $addition_path);
        var_dump($path_components);
        $path = implode('/', $path_components);
      }
    }
    return self::assemble(
      array_merge($merge_base, $addition_components, [
        'path' => $path
      ])
    );
  }
}
