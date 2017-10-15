<?php

namespace analizzatore;

require_once join(DIRECTORY_SEPARATOR, [__DIR__, 'common', 'exceptions.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, 'common', 'deny-exception-store.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, 'main.php']);

use Exception;
use analizzatore\exceptions\DenyException;
use analizzatore\common\DenyExceptionStore;

ob_start();

// server returns RFC7807 based error.
try {
  $main_executable = false;

  /**
   * remove X-Powered-By header
   */
   header_remove('X-Powered-By');

  // a part to accept CORS
  header('Access-Control-Allow-Origin: *');

  /**
   * load DenyException Cache Store
   */
  $store = new DenyExceptionStore();
  $eis = $store->find($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
  if ($eis !== null) throw $eis;

  $main_executable = true;
  main();
} catch (DenyException $e) {
  if ($main_executable) $store->save($e, $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
  # analizzatore deny exception cache status header
  header(sprintf('Az-De-Cache-Status: %s', $main_executable ? 'MISS' : 'HIT'));
  header('Content-Type: application/problem+json');
  http_response_code($e->getStatus());
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

// set Content-Length
header(sprintf('Content-Length: %d', ob_get_length()));

ob_end_flush();
