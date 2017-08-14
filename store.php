<?php

namespace analizzatore;

require_once join(DIRECTORY_SEPARATOR, [__DIR__, 'utils', 'ex-url.php']);

use analizzatore\utils\ExUrl;

class Store {
  private $path;

  public function __construct (string $foldername = 'store') {
    $this->path = join(DIRECTORY_SEPARATOR, [__DIR__, $foldername]);
  }

  private function gen_path (string $url, string $lang) {
    $fname = md5($url) . sha1($url);
    return join(DIRECTORY_SEPARATOR, [$this->path, $fname, $lang . '.json']);
  }

  public function find (string $url, string $lang) {
    $path = $this->gen_path($url, $lang);
    if (!file_exists($path)) return null;
    $fp = fopen($path, 'rb');
    if (!$fp) return null;
    $content = fread($fp, filesize($path));
    fclose($fp);
    return json_decode($content, TRUE);
  }

  public function save (string $url, string $lang, array $response, array $metadata): bool {
    $path = $this->gen_path($url, $lang);
    // create directory
    if (!file_exists(dirname($path))) mkdir(dirname($path), 0700, TRUE);
    $fp = fopen($path, 'w');
    $json = json_encode([
      'response' => $response,
      'metadata' => $metadata
    ], JSON_PRETTY_PRINT);
    $byte = fwrite($fp, $json);
    fclose($fp);
    return $byte !== false;
  }
}
