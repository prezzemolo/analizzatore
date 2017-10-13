<?php

namespace analizzatore\common;

require_once join(DIRECTORY_SEPARATOR, [__DIR__, '..', 'common', 'constants.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, '..', 'common', 'exceptions.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, '..', 'common', 'robot-configuration-store.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, '..', 'utils', 'request.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, '..', 'utils', 'ex-url.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, '..', 'utils', 'ex-string.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, '..', 'utils', 'extractors.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, '..', 'utils', 'robots-txt-parser.php']);

use DOMDocument;
use analizzatore\Constants;
use analizzatore\common\RobotConfigurationStore;
use analizzatore\utils\ExUrl;
use analizzatore\utils\ExString;
use analizzatore\exceptions\DenyException;
use function analizzatore\utils\{request, ogp_extractor, metadata_extractor, rel_extractor, parse_robots_txt};

/**
 * robots.txt checker
 **/
function robots_txt_checker (string $url): void {
  $robots_url = ExUrl::join($url, '/robots.txt');
  $store = new RobotConfigurationStore();
  $robot_configuration = $store->find($robots_url);
  // if non configuration in store, get from internet
  if ($robot_configuration === null) {
    $robots_txt_response = request('GET', $robots_url);
    /**
     * in 4xx, take as "full allow". refer to
     * https://developers.google.com/search/reference/robots_txt
     */
    if (
      (500 > $robots_txt_response['status_code']) and
      ($robots_txt_response['status_code'] >= 400)
    ) {
      $robot_configuration = [
        'User-Agent' => '~.*~',
        'Allow' => '~(?:/.*)~',
        'Disallow' => null,
        'Instant' => true
      ];
    }
    /**
     * in 5xx, take as "full disallow". refer to
     * https://developers.google.com/search/reference/robots_txt
     */
    else if (
      (600 > $robots_txt_response['status_code']) and
      ($robots_txt_response['status_code'] >= 500)
    ) {
      $robot_configuration = [
        'User-Agent' => '~.*~',
        'Allow' => null,
        'Disallow' => '~(?:/.*)~',
        'Instant' => true
      ];
    } else {
      $robots = parse_robots_txt($robots_txt_response['body'], Constants::ANALIZZATORE_UA);
      // todo: improvement detection logic
      $robot_configuration = array_shift($robots) ?? [
        'User-Agent' => '~.*~',
        'Allow' => '~(?:/.*)~',
        'Disallow' => null,
        'Instant' => true
      ];
    }
    $store->save($robots_url, $robot_configuration);
  }
  // regExp match checker
  $robot_allowed = $robot_configuration['Allow'] === null ? false
    : preg_match($robot_configuration['Allow'], $url) === 1;
  $robot_disallowed = $robot_configuration['Disallow'] === null ? false
    : preg_match($robot_configuration['Disallow'], $url) === 1;
  // only 'disallowed' flag enabled, stop indexing.
  if ($robot_disallowed && !$robot_allowed)
    throw new DenyException(500,
      "Indexing denial by 'robots.txt'.",
      "Clawler can't continue indexing because 'robots.txt' has a 'disallow' entry.");
  return;
}

/**
 * metadata clawler
 */
function clawler (string $url, string $lang) {
  // check robots.txt
  robots_txt_checker($url);

  $result = request('GET', $url, [
    'Accept-Language' => $lang,
    'User-Agent' => Constants::ANALIZZATORE_UA
  ]);

  // stop with status code greater than 400
  if ($result['status_code'] >= 400)
    throw new DenyException(500,
      'Response status code greater than 400.',
      sprintf('HTTP Error Code %d happened at connected server.', $result['status_code']));

  // stop with getting no HTML
  if (!preg_match('/\/html/', $result['headers']['content-type']))
    throw new DenyException(500,
      "Content isn't HTML.",
      "Clawler can't getting informations from this url.");

  // detect charset
  $charset = 'UTF-8';
  # from http-equiv
  preg_match('[<meta http-equiv="content-type" content="(.*?)"]i', $result['body'], $matches);
  if (isset($matches[1])) {
    $content = $matches[1];
    preg_match('/charset=([^ ;]*)/', $matches[1], $matches);
    if (isset($matches[1])) $charset = $matches[1];
  }
  # from charset (override http-equiv)
  preg_match('[<meta charset="(.*?)"]', $result['body'], $matches);
  if (isset($matches[1])) $charset = $matches[1];
  # convert to lowercase for search
  $charset = strtolower($charset);
  $encodings = [];
  foreach (ExString::list_encodings_with_alias() as $encoding) {
    $encodings[strtolower($encoding)] = $encoding;
  }
  # userland aliases detection
  $charset = [
    'shift_jis' => 'sjis'
  ][$charset] ?? $charset;
  # check loadable in the running environment
  $encoding = array_key_exists($charset, $encodings)
    ? $encodings[$charset]
    : 'UTF-8';

  // assemble DOM tree
  $root_DOM = DOMDocument::loadHTML(
    # convert to HTML-ENTITIES, see https://www.w3schools.com/html/html_entities.asp
    mb_convert_encoding($result['body'], 'HTML-ENTITIES', $encoding)
  );
  $HTML_DOM = $root_DOM->getElementsByTagName('html')->item(0);
  if (!$HTML_DOM) throw new DenyException(500,
    "Can't parse the response.",
    "Clawler can't parse the response from url.");
  $head_DOM = $HTML_DOM->getElementsByTagName('head')->item(0);
  if (!$head_DOM) throw new DenyException(500,
    "Missing head tag in the response.",
    "Clawler can't find head tag in the response from url.");

  // extract informations from DOM
  $title_element = $head_DOM->getElementsByTagName('title')->item(0);
  $meta_elements = $head_DOM->getElementsByTagName('meta');
  $ogp = isset($meta_elements) ? ogp_extractor($meta_elements) : [];
  $metadata = isset($meta_elements) ? metadata_extractor($meta_elements) : [];
  $link_elements = $head_DOM->getElementsByTagName('link');
  $rel = isset($link_elements) ? rel_extractor($link_elements) : [];
  if (!isset($ogp['title']) && !$title_element)
    throw new DenyException(500,
      "Missing title in the response.",
      "Clawler can't find title in the response from url.");

  // check meta robots
  $meta_robots = array_map('trim', explode(',', $metadata['robots'] ?? ''));
  if (in_array('noindex', $meta_robots))
    throw new DenyException(500,
      "Indexing denial by meta 'robots' tag.",
      "Clawler can't continue indexing because meta 'robots' tag includes 'noindex' in the response from url.");

  /**
   * assemble metadata dict
   * includes title & canonical & type & favicon at least.
   */
  $metadata = [
    'title' => $ogp['title'] ?? $title_element->textContent,
    'canonical' => $ogp['url'] ?? $rel['canonical'] ?? $result['url'],
    // default value comes from OGP definition
    'type' => $ogp['type'] ?? 'website'
  ];
  # lang
  if ($HTML_DOM->hasAttribute('lang'))
    $metadata['lang'] = $HTML_DOM->getAttribute('lang');
  # image
  if (isset($ogp['image']))
    $metadata['image'] = ExUrl::join($metadata['canonical'], $ogp['image']);
  # description
  if (isset($ogp['description']) || isset($metadata['description']))
    $metadata['description'] = $ogp['description'] ?? $metadata['description'];
  # site name
  if (isset($ogp['site_name']))
    $metadata['site_name'] = $ogp['site_name'];
  # icon
  if (isset($rel['icon']) || isset($rel['shortcut icon']))
    $metadata['icon'] = ExUrl::join($metadata['canonical'], $rel['icon'] ?? $rel['shortcut icon']);

  return [
    'metadata' => $metadata,
    'timestamp' => $result['timestamp']
  ];
}