<?php

namespace analizzatore\common;

require_once join(DIRECTORY_SEPARATOR, [__DIR__, 'constants.php']);

use analizzatore\Constants;

class ResponseStore {
  private $path;

  public function __construct (string $foldername = 'stores/response') {
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
    // if $content.state.version isn't compatible with Constant::VERSION, reject
    if ($content['state']['version'] !== Constants::VERSION) return null;
    // by default, the max age of stored content is 1 day.
    $max_age = $content['state']['max_age'] ?? 24 * 60 * 60;
    return array_merge([
      // check document is 'fresh', newer than $max_age seconds ago.
      'fresh' => (time() - $max_age <= $content['state']['timestamp'])
    ], $content['document']);
  }

  public function save (string $url, string $lang, array $document, ?int $max_age = null): bool {
    $path = $this->gen_path($url, $lang);
    // create directory
    if (!file_exists(dirname($path))) mkdir(dirname($path), 0700, TRUE);
    $fp = fopen($path, 'w');
    $json = json_encode([
      'document' => $document,
      'state' => [
        'timestamp' => time(),
        'max_age' => $max_age,
        'version' => Constants::VERSION
      ]
    ], JSON_PRETTY_PRINT);
    $byte = fwrite($fp, $json);
    fclose($fp);
    return $byte !== false;
  }
}
