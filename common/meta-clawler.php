<?php

namespace analizzatore\common;

require_once join(DIRECTORY_SEPARATOR, [__DIR__, '..', 'common', 'constants.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, '..', 'utils', 'request.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, '..', 'utils', 'ex-url.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, '..', 'utils', 'ex-string.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, '..','utils', 'extractors.php']);

use DOMDocument;
use analizzatore\Constants;
use analizzatore\utils\ExUrl;
use analizzatore\utils\ExString;
use function analizzatore\utils\{request, ogp_extractor, metadata_extractor, rel_extractor};

function clawl (string $url, string $lang) {
  $result = request('GET', $url, [
    'Accept-Language' => $lang,
    'User-Agent' => Constants::ANALIZZATORE_UA
  ]);

  // stop with status code greater than 400
  if ($result['status_code'] >= 400)
    throw new DenyException('Response status code greater than 400.',
      sprintf('HTTP Error Code %d happened at connected server.', $result['status_code']), 500);

  // stop with getting no HTML
  if (!preg_match('/\/html/', $result['headers']['content-type']))
    throw new DenyException("Content isn't HTML.", "Clawler can't getting informations from this url.", 500);

  // detect charset
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
  # userlang aliases detection
  $charset = [
    'shift_jis' => 'sjis'
  ][$charset] ?? $charset;
  # check loadable in the running environment
  $encoding =
    isset($charset) && array_key_exists($charset, $encodings)
    ? $encodings[$charset]
    : 'UTF-8';

  // assemble DOM tree
  $root_DOM = DOMDocument::loadHTML(
    # convert to HTML-ENTITIES, see https://www.w3schools.com/html/html_entities.asp
    mb_convert_encoding($result['body'], 'HTML-ENTITIES', $encoding)
  );
  $HTML_DOM = $root_DOM->getElementsByTagName('html')->item(0);
  if (!$HTML_DOM) throw new DenyException("Can't parse the response.", "Clawler can't parse the response from url.", 500);
  $head_DOM = $HTML_DOM->getElementsByTagName('head')->item(0);
  if (!$head_DOM) throw new DenyException("Missing head tag in the response.",
    "Clawler can't find head tag in the response from url.", 500);

  // extract informations from DOM
  $title_element = $head_DOM->getElementsByTagName('title')->item(0);
  $meta_elements = $head_DOM->getElementsByTagName('meta');
  $ogp = isset($meta_elements) ? ogp_extractor($meta_elements) : [];
  $metadata = isset($meta_elements) ? metadata_extractor($meta_elements) : [];
  $link_elements = $head_DOM->getElementsByTagName('link');
  $rel = isset($link_elements) ? rel_extractor($link_elements) : [];
  if (!isset($ogp['title']) && !$title_element)
    throw new DenyException("Missing title in the response.", "Clawler can't find title in the response from url.", 500);

  /**
   * assemble response dict
   * includes title & canonical & type & favicon at least.
   */
  $response = [
    'title' => $ogp['title'] ?? $title_element->textContent,
    'canonical' => $ogp['url'] ?? $rel['canonical'] ?? $result['url'],
    // default value comes from OGP definition
    'type' => $ogp['type'] ?? 'website'
  ];
  # lang
  if ($HTML_DOM->hasAttribute('lang'))
    $response['lang'] = $HTML_DOM->getAttribute('lang');
  # image
  if (isset($ogp['image']))
    $response['image'] = ExUrl::join($response['canonical'], $ogp['image']);
  # description
  if (isset($ogp['description']) || isset($metadata['description']))
    $response['description'] = $ogp['description'] ?? $metadata['description'];
  # site name
  if (isset($ogp['site_name']))
    $response['site_name'] = $ogp['site_name'];
  # icon
  if (isset($rel['icon']) || isset($rel['shortcut icon']))
    $response['icon'] = ExUrl::join($response['canonical'], $rel['icon'] ?? $rel['shortcut icon']);

  return [$response, $result['timestamp']];
}