<?php

namespace analizzatore;

require_once join(DIRECTORY_SEPARATOR, [__DIR__, 'utils', 'headers.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, 'utils', 'ex-url.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, 'common', 'exceptions.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, 'common', 'meta-clawler.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, 'store.php']);

use DateTime;
use analizzatore\utils\Headers;
use analizzatore\utils\ExUrl;
use analizzatore\exceptions\DenyException;
use analizzatore\Store;
use function analizzatore\common\clawl;

// commonize header sender, and echo response JSON
function send (array $response, int $timestamp) {
  // set headers
  /**
   * Q. why gmdate & GMT used?
   * A. for following IMF-fixdate.
   * 'An HTTP-date value represents time as an instance of Coordinated
   *  Universal Time (UTC).    if (isset($matches[1])) {The first two formats indicate UTC by the
   *  three-letter abbreviation for Greenwich Mean Time, "GMT", a
   *  predecessor of the UTC name; values in the asctime format are assumed
   *  to be in UTC.  A sender that generates HTTP-date values from a local
   *  clock ought to use NTP ([RFC5905]) or some similar protocol to
   *  synchronize its clock to UTC.'
   * by RFC7231 (https://tools.ietf.org/html/rfc7231#section-7.1.1.1)
   **/
  header(sprintf('Last-Modified: %s GMT',
    gmdate('D, d M Y H:i:s', $timestamp)
  ));
  # one day cache
  header(sprintf('Cache-control: public, max-age=%d', 24 * 60 * 60));
  header(sprintf('Expires: %s GMT',
    gmdate('D, d M Y H:i:s', $timestamp + 24 * 60 * 60)
  ));
  header('Content-Type: application/json');
  header('Vary: Accept-Encoding');

  // returns JSONize response to user
  echo json_encode($response);
}

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
    return;
  }

  // appear GET, HEAD accesses only (in addition, OPTIONS allowed in above section)
  if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD')
    throw new DenyException(sprintf("Method '%s' is not allowed.", $_SERVER['REQUEST_METHOD']),
      'you can use GET or HEAD method only.', 405);

  // appear / only
  if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/')
    throw new DenyException('There are no content.', "Haven't you made a mistake?", 404);

  // notice missing url parameter
  if (!isset($_GET['url']))
    throw new DenyException("Missing 'url' parameter.", "you must set 'url' parameter.", 400);

  // deny no HTTP/HTTPS url
  if (!preg_match('/^(http:)|(https:)\/\//', $_GET['url']))
    throw new DenyException("Incorrect 'url' parameter.", "'url' parameter must start with 'http://' or 'https://'.", 400);

  $headers = new Headers(null, getallheaders());
  // no loop, no clawl my own self
  if ($headers['host'] === parse_url($_GET['url'], PHP_URL_HOST))
    throw new DenyException("Incorrect 'url' parameter.", "'url' parameter contains hostname that is same as server hostname.", 400);

  // standalize a URL for caching
  $url_elems = parse_url($_GET['url']);
  if (!array_key_exists('path', $url_elems)) $url_elems['path'] = '/';
  if (array_key_exists('fragment', $url_elems)) unset($url_elems['fragment']);
  $url = ExUrl::assemble($url_elems);

  $lang = $_GET['lang'] ?? 'en';

  // use the cache store
  $store = new Store();
  $document = $store->find($url, $lang);
  if ($document !== null) {
    $cache_timestamp = $document['metadata']['timestamp'];
    // check cache newer than 1day ago
    if (time() - 24 * 60 * 60 <= $cache_timestamp) {
      // check 'if-modified-since', browser side cache header
      if (isset($headers['if-modified-since'])
        and (new DateTime($headers['if-modified-since']))->getTimestamp() === $cache_timestamp) {
        http_response_code(304);
        return;
      }
      return send($document['response'], $cache_timestamp);
    }
  }

  list($response, $timestamp) = clawl($url, $lang);

  // save to the store
  $store->save($url, $lang, $response, [
    'timestamp' => $timestamp
  ]);

  return send($response, $timestamp);
}
