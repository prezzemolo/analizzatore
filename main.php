<?php

namespace analizzatore;

require_once join(DIRECTORY_SEPARATOR, [__DIR__, 'utils', 'request.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, 'utils', 'extractors.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, 'common', 'exceptions.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, 'utils', 'headers.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, 'utils', 'ex-url.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, 'utils', 'ex-string.php']);

use DOMDocument;
use DateTime;
use analizzatore\utils\Headers;
use analizzatore\utils\ExUrl;
use analizzatore\utils\ExString;
use analizzatore\exceptions\DenyException;
use function analizzatore\utils\{request, ogp_extractor, metadata_extractor, rel_extractor};

function main () {
  /**
   * remove X-Powered-By header
   */
   header_remove('X-Powered-By');

  /**
   * accept CORS
   * if OPTIONS access, close conn with 204.
   */
  header('Access-Control-Allow-Origin: *');
  if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    header(sprintf('Access-Control-Max-Age: %d', 24 * 60 * 60));
    header('Access-Control-Allow-Methods: GET');
    die();
  }

  // appear GET, HEAD accesses only (in addition, OPTIONS allowed in above section)
  if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    throw new DenyException(sprintf("Method '%s' is not allowed.", $_SERVER['REQUEST_METHOD']),
      'you can use GET or HEAD method only.', 405);
  }

  // appear / only
  if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/') {
    throw new DenyException('There are no content.', "Haven't you made a mistake?", 404);
  }

  // notice missing url parameter
  if (!isset($_GET['url'])) {
    throw new DenyException("Missing 'url' parameter.", "you must set 'url' parameter.", 400);
  }

  // deny no HTTP/HTTPS url
  if (!preg_match('/^(http:)|(https:)\/\//', $_GET['url'])) {
    throw new DenyException("Incorrect 'url' parameter.", "'url' parameter must start with 'http://' or 'https://'.", 400);
  }

  $headers = new Headers(null, getallheaders());
  // no loop, no clawl my own self
  if ($headers['host'] === parse_url($_GET['url'], PHP_URL_HOST)) {
    throw new DenyException("Incorrect 'url' parameter.", "'url' parameter contains hostname that is same as server hostname.", 400);
  }
  // return 304 with if-modified-since header sent, and its value newer than one day ago
  if (isset($headers['if-modified-since'])) {
    $modified = new DateTime($headers['if-modified-since']);
    $modified_timestamp = $modified->getTimeStamp();
    if ($modified_timestamp + 24 * 60 * 60 > time()) {
      http_response_code(304);
      die();
    }
  }

  // clawl
  $result = request('GET', $_GET['url'], [
    'Accept-Language' => $_GET['lang'] ?? 'en'
  ]);

  // stop with status code greater than 400
  if ($result['status_code'] >= 400) {
    throw new DenyException('Response status code greater than 400.',
      sprintf('HTTP Error Code %d happened at connected server.', $result['status_code']), 500);
  }

  // stop with getting no HTML
  if (!preg_match('/\/html/', $result['headers']['content-type'])) {
    throw new DenyException("Content isn't HTML.", "Server can't getting informations for this url.", 500);
  }

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
  if (!$HTML_DOM) throw new DenyException("Can't parse the response.", "Server can't parse the response from url.", 500);
  $head_DOM = $HTML_DOM->getElementsByTagName('head')->item(0);
  if (!$head_DOM) throw new DenyException("Missing head tag in the response.",
    "Server can't find head tag in the response from url.", 500);

  // extract informations from DOM
  $title_element = $head_DOM->getElementsByTagName('title')->item(0);
  $meta_elements = $head_DOM->getElementsByTagName('meta');
  $ogp = isset($meta_elements) ? ogp_extractor($meta_elements) : [];
  $metadata = isset($meta_elements) ? metadata_extractor($meta_elements) : [];
  $link_elements = $head_DOM->getElementsByTagName('link');
  $rel = isset($link_elements) ? rel_extractor($link_elements) : [];
  if (!isset($ogp['title']) && !$title_element) throw new DenyException("Missing title in the response.",
    "Server can't find title in the response from url.", 500);

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
  if ($HTML_DOM->hasAttribute('lang')) {
    $response['lang'] = $HTML_DOM->getAttribute('lang');
  }
  # image
  if (isset($ogp['image'])) {
    $response['image'] = ExUrl::join($response['canonical'], $ogp['image']);
  }
  # description
  if (isset($ogp['description']) || isset($metadata['description'])) {
    $response['description'] = $ogp['description'] ?? $metadata['description'];
  }
  # site name
  if (isset($ogp['site_name'])) {
    $response['site_name'] = $ogp['site_name'];
  }
  # icon
  if (isset($rel['icon']) || isset($rel['shortcut icon'])) {
    $response['icon'] = ExUrl::join($response['canonical'], $rel['icon'] ?? $rel['shortcut icon']);
  }

  // set headers
  /**
   * Q. why gmdate & GMT used?
   * A. for following IMF-fixdate.
   * 'An HTTP-date value represents time as an instance of Coordinated
   *  Universal Time (UTC).  The first two formats indicate UTC by the
   *  three-letter abbreviation for Greenwich Mean Time, "GMT", a
   *  predecessor of the UTC name; values in the asctime format are assumed
   *  to be in UTC.  A sender that generates HTTP-date values from a local
   *  clock ought to use NTP ([RFC5905]) or some similar protocol to
   *  synchronize its clock to UTC.'
   * by RFC7231 (https://tools.ietf.org/html/rfc7231#section-7.1.1.1)
   **/
  header(sprintf('Last-Modified: %s GMT',
    gmdate('D, d M Y H:i:s', $result['timestamp'])
  ));
  # one day cache
  header(sprintf('Cache-control: public, max-age=%d', 24 * 60 * 60));
  header(sprintf('Expires: %s GMT',
    gmdate('D, d M Y H:i:s', $result['timestamp'] + 24 * 60 * 60)
  ));
  header('Content-Type: application/json');
  header('Vary: Accept-Encoding');

  // returns JSONize response to user
  echo json_encode($response);
}
