<?php

namespace analizzatore\common;

require_once join(DIRECTORY_SEPARATOR, [__DIR__, '..', 'utils', 'ex-url.php']);

use analizzatore\utils\ExUrl;

class ResponseStore {
  private $path;

  public function __construct (string $foldername = 'cache') {
    $this->path = join(DIRECTORY_SEPARATOR, [__DIR__, '..', '..', $foldername]);
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
    $raw_content = fread($fp, filesize($path));
    fclose($fp);
    $content = json_decode($raw_content, TRUE);
    return array_merge([
      // check document is 'fresh', newer than 1day ago
      'fresh' => (time() - 24 * 60 * 60 <= $content['state']['timestamp'])
    ], $content['document']);
  }

  public function save (string $url, string $lang, array $document): bool {
    $path = $this->gen_path($url, $lang);
    // create directory
    if (!file_exists(dirname($path))) mkdir(dirname($path), 0700, TRUE);
    $fp = fopen($path, 'w');
    $json = json_encode([
      'document' => $document,
      'state' => [
        'timestamp' => time()
      ]
    ], JSON_PRETTY_PRINT);
    $byte = fwrite($fp, $json);
    fclose($fp);
    return $byte !== false;
  }
}
