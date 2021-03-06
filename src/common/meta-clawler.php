<?php

namespace analizzatore\common;

require_once join(DIRECTORY_SEPARATOR, [__DIR__, '..', 'common', 'constants.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, '..', 'common', 'exceptions.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, '..', 'common', 'check-permission-on-robots-txt.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, '..', 'utils', 'request.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, '..', 'utils', 'ex-url.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, '..', 'utils', 'ex-string.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, '..', 'utils', 'extractors.php']);

use DOMDocument;
use analizzatore\Constants;
use analizzatore\utils\ExUrl;
use analizzatore\utils\ExString;
use analizzatore\exceptions\DenyException;
use function analizzatore\utils\{request, ogp_extractor, metadata_extractor, rel_extractor};

/**
 * metadata clawler
 */
function clawler (string $url, string $lang, string $user_agent = Constants::UA) {
  // create cURL session for keep-alive
  $curl_ch = curl_init();

  // check indexing permission, on robots.txt
  if (!check_indexing_permission_on_robots_txt($url, $user_agent, $curl_ch))
    throw new DenyException(500,
      "Indexing not permitted by 'robots.txt'.",
      "Clawler can't continue indexing because 'robots.txt' has a 'disallow' entry.");

  $result = request('GET', $url, [
    'curl_ch' => $curl_ch,
    'headers' => [
      'Accept-Language' => $lang,
      'User-Agent' => $user_agent
    ]
  ]);

  // close cURL session
  curl_close($curl_ch);

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
  preg_match('[<meta\shttp-equiv="content-type"\scontent="(.*?)"]i', $result['body'], $matches);
  if (isset($matches[1])) {
    preg_match('/charset=([^\s;]*)/i', $matches[1], $matches);
    if (isset($matches[1])) $charset = $matches[1];
  }
  # from charset (override http-equiv)
  preg_match('[<meta\scharset="(.*?)"]i', $result['body'], $matches);
  if (isset($matches[1])) $charset = $matches[1];
  # convert to lowercase for search
  $charset = strtolower($charset);
  $encodings = [];
  foreach (ExString::list_encodings_with_alias() as $encoding) {
    $encodings[strtolower($encoding)] = $encoding;
  }
  # userland aliases detection
  $charset = [
    'shift_jis' => 'sjis',
    'x-sjis' => 'sjis',
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

  // check indexing permission, on meta robots
  $meta_robots = array_map('trim', explode(',', $metadata['robots'] ?? ''));
  if (in_array('noindex', $meta_robots))
    throw new DenyException(500,
      "Indexing not permitted by meta 'robots' tag.",
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
