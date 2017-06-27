<?php

namespace analizzatore;

require_once join(DIRECTORY_SEPARATOR, [__DIR__, 'utils', 'request.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, 'utils', 'extractors.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, 'common', 'exceptions.php']);

use DOMDocument;
use Exception;
use analizzatore\exceptions\DenyException;
use function analizzatore\utils\{request, ogp_extractor, metadata_extractor};

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

  // appear GET only
  if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    throw new DenyException(sprintf("Method '%s' is not allowed.", $_SERVER['REQUEST_METHOD']),
      'you can use GET method only.', 405);
  }

  // appear / only
  if (!isset($_SERVER['REDIRECT_URL']) || $_SERVER['REDIRECT_URL'] !== '/') {
    throw new DenyException('There are no content.', "Haven't you made a mistake?", 404);
  }

  // let's implement!
  http_response_code(204);
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
