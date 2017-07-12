<?php

namespace analizzatore;

require_once join(DIRECTORY_SEPARATOR, [__DIR__, 'utils', 'request.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, 'utils', 'extractors.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, 'common', 'exceptions.php']);

use DOMDocument;
use Exception;
use DateTime;
use analizzatore\exceptions\DenyException;
use function analizzatore\utils\{request, ogp_extractor, metadata_extractor, rel_extractor};

try {
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

  // set nesessary values
  $param_url = $_GET['url'];
  $param_lang = $_GET['lang'];
  $request_header = [
    'Accept-Language' => $param_lang ?? 'en'
  ];

  // clawl
  $result = request('GET', $param_url, $request_header);

  // stop with status code greater than 400
  if ($result['status_code'] >= 400) {
    throw new DenyException('Response status code greater than 400.',
      sprintf('HTTP Error Code %d happened at connected server.', $result['status_code']), 500);
  }

  // stop with getting no HTML
  if (!preg_match('/\/html/', $result['headers']['content-type'])) {
    throw new DenyException("Content isn't HTML.", "Server can't getting informations for this url.", 500);
  }

  // DOM!
  $ROOT_DOM = DOMDocument::loadHTML(mb_convert_encoding($result['body'], 'HTML-ENTITIES'));
  $HTML_DOM = $ROOT_DOM->getElementsByTagName('html')->item(0);
  if (!$HTML_DOM) throw new DenyException("Can't parse HTML.", "Server can't parse HTML from url.", 500);
  $HEAD_DOM = $HTML_DOM->getElementsByTagName('head')->item(0);
  if (!$HEAD_DOM) throw new DenyException("Missing head tag in HTML.",
    "Server can't find head tag in HTML from url.", 500);
  $title_element = $HEAD_DOM->getElementsByTagName('title')->item(0);
  if (!$title_element) throw new DenyException("Missing title tag in HTML.",
    "Server can't find title tag in HTML from url.", 500);

  // extract informations from DOM
  $meta_elements = $HEAD_DOM->getElementsByTagName('meta');
  $ogp = isset($meta_elements) ? ogp_extractor($meta_elements) : [];
  $metadata = isset($meta_elements) ? metadata_extractor($meta_elements) : [];
  $link_elements = $HEAD_DOM->getElementsByTagName('link');
  $rel = isset($link_elements) ? rel_extractor($link_elements) : [];

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
    $response['image'] = $ogp['image'];
  }
  # description
  if (isset($ogp['description']) || isset($metadata['description'])) {
    $response['description'] = $ogp['description'] ?? $metadata['description'];
  }
  # site name
  if (isset($ogp['site_name'])) {
    $response['site_name'] = $ogp['site_name'];
  }

  # set headers
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
  header(sprintf('Cache-control: public, max-age: %d', 1 * 60 * 60));
  header('Content-Type: application/json');

  # send JSONize response
  echo json_encode($response);

// error catch blocks use RFC7807.
} catch (DenyException $e) {
  header('Content-Type: application/problem+json');
  http_response_code($e->getCode());
  echo json_encode([
    'type' => 'about:blank',
    'title' => $e->getTitle(),
    'detail' => $e->getMessage()
  ]);
} catch (Exception $e) {
  header('Content-Type: application/problem+json');
  http_response_code(500);
  $traceback = $e->getTrace();
  echo json_encode([
    'type' => 'about:blank',
    'title' => 'Internal server error.',
    'detail' => $e->getMessage(),
    'path' => basename($e->getFile()),
    'line' => $e->getLine()
  ]);
}

?>
