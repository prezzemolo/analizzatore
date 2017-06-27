<?php

namespace analizzatore;

require_once join(DIRECTORY_SEPARATOR, [__DIR__, 'utils', 'request.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, 'utils', 'extractors.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, 'common', 'exceptions.php']);

use DOMDocument;
use Exception;
use analizzatore\exceptions\DenyException;
use function analizzatore\utils\{request, ogp_extractor, metadata_extractor, canonical_extractor};

try {
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
  if (!isset($_SERVER['REDIRECT_URL']) || $_SERVER['REDIRECT_URL'] !== '/') {
    throw new DenyException('There are no content.', "Haven't you made a mistake?", 404);
  }

  // notice missing url parameter
  if (!isset($_GET['url'])) {
    throw new DenyException("Missing 'url' parameter.", "you must set 'url' parameter.", 400);
  }

  // deny no HTTP/HTTPS url
  if (!preg_match('/^(http:)|(https:)\/\/)/', $_GET['url'])) {
    throw new DenyException("Incorrect 'url' parameter.", "'url' parameter must start with 'http://' or 'https://'.", 400);
  }

  // set nesessary values
  $param_url = $_GET['url'];
  $param_lang = $_GET['lang'];
  $request_header = $param_lang ? [
    'Accept-Language' => $param_lang
  ] : [];

  // clawl
  $result = request('GET', $param_url, $request_header);

  // if getting no HTML, raise error
  if (!preg_match('/^.*\/html(:?;.*)?$/', $result['headers']['content-type'])) {
    throw new DenyException("Content isn't HTML.", "Server can't getting informations for this url.", 500);
  }

  // DOM!
  $ROOT_DOM = DOMDocument::loadHTML($result['body']);
  $HTML_DOM->getElementsByTagName('html')->item(0);
  if (!$HTML_DOM) throw new DenyException("Can't parse HTML.". "Server can't parse HTML from url.", 500);
  $HEAD_DOM = $HTML_DOM->getElementsByTagName('head')->item(0);
  if (!$HEAD_DOM) throw new DenyException("Missing head tag in HTML.". "Server can't find head tag in HTML from url.", 500);
  $title_element = $res_body_DOM_head->getElementsByTagName('title')->item(0);
  if (!$title_element) throw new DenyException("Missing title tag in HTML.", "Server can't find title tag in HTML from url.", 500);

  // extract informations from DOM
  $meta_elements = $HEAD_DOM->getElementsByTagName('meta');
  $ogp = ogp_extractor($meta_elements);
  $metadata = metadata_extractor($meta);
  $link_elements = $HEAD_DOM->getElementsByTagName('link');
  $canonical = canonical_extractor($link_elements);

  /**
   * assemble response dict
   * includes title & url & type at least.
   */
  $response = [
    'title' => $ogp['title'] ?? $title_element->textContent,
    'url' => $ogp['url'] ?? $canonical ?? $param_url,
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

die();

?>
