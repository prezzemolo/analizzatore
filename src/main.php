<?php

namespace analizzatore;

require_once join(DIRECTORY_SEPARATOR, [__DIR__, 'utils', 'headers.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, 'utils', 'ex-url.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, 'common', 'exceptions.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, 'common', 'meta-clawler.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, 'common', 'response-store.php']);

use DateTime;
use analizzatore\utils\Headers;
use analizzatore\utils\ExUrl;
use analizzatore\exceptions\DenyException;
use analizzatore\common\ResponseStore;
use function analizzatore\common\clawler;

// commonize header sender, and echo response JSON
function send (array $document) {
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
    gmdate('D, d M Y H:i:s', $document['timestamp'])
  ));
  # one day cache
  header(sprintf('Cache-control: public, max-age=%d', 24 * 60 * 60));
  header(sprintf('Expires: %s GMT',
    gmdate('D, d M Y H:i:s', $document['timestamp'] + 24 * 60 * 60)
  ));
  header('Content-Type: application/json');
  header('Vary: Accept-Encoding');

  // returns JSONize response to user
  echo json_encode($document['metadata']);
}

function main () {
  /**
   * a part to accept CORS
   * with OPTIONS access, close conn with 204.
   */
  if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    header(sprintf('Access-Control-Max-Age: %d', 24 * 60 * 60));
    header('Access-Control-Allow-Methods: GET');
    return;
  }

  // appear GET, HEAD accesses only (in addition, OPTIONS allowed in above section)
  if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD')
    throw new DenyException(405,
      sprintf("Method '%s' is not allowed.", $_SERVER['REQUEST_METHOD']),
      'you can use GET or HEAD method only.');

  // appear / only
  if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/')
    throw new DenyException(404,
      'There are no content.',
      "Haven't you made a mistake?");

  // notice missing url parameter
  if (!isset($_GET['url']))
    throw new DenyException(400,
      "Missing 'url' parameter.",
      "you must set 'url' parameter.");

  // deny no HTTP/HTTPS url
  if (!preg_match('/^(http:)|(https:)\/\//', $_GET['url']))
    throw new DenyException(400,
      "Incorrect 'url' parameter.",
      "'url' parameter must start with 'http://' or 'https://'.");

  $headers = new Headers(null, getallheaders());
  // no loop, no clawl my own self
  if ($headers['host'] === parse_url($_GET['url'], PHP_URL_HOST))
    throw new DenyException(400,
      "Incorrect 'url' parameter.",
      "'url' parameter contains hostname that is same as server hostname.");

  // standalize a URL for caching
  $url_elems = parse_url($_GET['url']);
  if (!array_key_exists('path', $url_elems)) $url_elems['path'] = '/';
  if (array_key_exists('fragment', $url_elems)) unset($url_elems['fragment']);
  $url = ExUrl::assemble($url_elems);

  $lang = $_GET['lang'] ?? 'en';

  // use the cache store
  $store = new ResponseStore();
  $document = $store->find($url, $lang);
  if ($document !== null) {
    if ($document['fresh']) {
      // check 'if-modified-since', browser side cache header
      if (
        isset($headers['if-modified-since'])
        and (new DateTime($headers['if-modified-since']))->getTimestamp() === $document['timestamp']
      ) {
        http_response_code(304);
        return;
      }
      return send($document);
    }
  }

  $document = clawler($url, $lang);

  // save to the store
  $store->save(
    $url,
    $lang,
    $document
  );

  return send($document);
}
