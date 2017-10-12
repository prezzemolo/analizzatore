<?php

namespace analizzatore\common;

require_once join(DIRECTORY_SEPARATOR, [__DIR__, 'exceptions.php']);

use analizzatore\exceptions\DenyException;

class DenyExceptionStore {
  private $path;

  public function __construct (string $foldername = 'stores/deny') {
    $this->path = join(DIRECTORY_SEPARATOR, [__DIR__, '..', '..', $foldername]);
  }

  private function gen_path (string $method, string $request_uri) {
    $fname = md5("$method $request_uri");
    return join(DIRECTORY_SEPARATOR, [$this->path, $fname . '.json']);
  }

  public function find (string $method, string $request_uri) {
    $path = $this->gen_path($method, $request_uri);
    if (!file_exists($path)) return null;
    $fp = fopen($path, 'rb');
    if (!$fp) return null;
    $raw_content = fread($fp, filesize($path));
    fclose($fp);
    $content = json_decode($raw_content, TRUE);
    // check exceptions in cache store newer than 1 hour ago
    if (!(time() - 60 * 60 <= $content['state']['timestamp'])) return null;
    return new DenyException($content['status'], $content['title'], $content['message']);
  }

  public function save (DenyException $error, string $method, string $request_uri): bool {
    $path = $this->gen_path($method, $request_uri);
    // create directory
    if (!file_exists(dirname($path))) mkdir(dirname($path), 0700, TRUE);
    $fp = fopen($path, 'w');
    $json = json_encode([
      'status' => $error->getStatus(),
      'title' => $error->getTitle(),
      'message' => $error->getMessage(),
      'state' => [
        'method' => $method,
        'request_uri' => $request_uri,
        'timestamp' => time()
      ]
    ], JSON_PRETTY_PRINT);
    $byte = fwrite($fp, $json);
    fclose($fp);
    return $byte !== false;
  }
}
