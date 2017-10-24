<?php

namespace analizzatore\common;

require_once join(DIRECTORY_SEPARATOR, [__DIR__, 'constants.php']);

use analizzatore\Constants;

class RobotConfigurationStore {
  private $path;

  public function __construct (string $foldername = 'stores/robot-configuration') {
    $this->path = join(DIRECTORY_SEPARATOR, [__DIR__, '..', '..', $foldername]);
  }

  private function gen_path (string $key): string {
    $fname = md5($key);
    return join(DIRECTORY_SEPARATOR, [$this->path, $fname . '.json']);
  }

  public function find (string $key) {
    $path = $this->gen_path($key);
    if (!file_exists($path)) return null;
    $fp = fopen($path, 'rb');
    if (!$fp) return null;
    $raw_content = fread($fp, filesize($path));
    fclose($fp);
    $content = json_decode($raw_content, TRUE);
    // if $content.state.version isn't compatible with Constant::VERSION, reject
    if ($content['state']['version'] !== Constants::VERSION) return null;
    // by default, the max age of stored content is 12 hour.
    $max_age = $content['state']['max_age'] ?? 12 * 60 * 60;
    // check exceptions in cache store newer than $max_age seconds ago
    if (!(time() - $max_age <= $content['state']['timestamp'])) return null;
    return $content['block'];
  }

  public function save (string $key, array $detected_parsed_block, ?int $max_age = null): bool {
    $path = $this->gen_path($key);
    // create directory
    if (!file_exists(dirname($path))) mkdir(dirname($path), 0700, TRUE);
    $fp = fopen($path, 'w');
    $json = json_encode([
      'block' => $detected_parsed_block,
      'state' => [
        'key' => $key,
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
