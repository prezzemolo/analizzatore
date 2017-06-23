<?php
class Headers {
  private $self = [];

  function __construct($headers) {
    $this->self = $headers;
  }

  /**
   * http://php.net/manual/ja/language.oop5.magic.php
   */
  public function __get($name) {
    if ($this->__isset($name) === false) {
      return null;
    }
    return $this->self[$name];
  }

  public function __isset($name) {
    return isset($this->self[$name]);
  }
}

function header_split ($raw_header) {
  $returned = [];
  foreach (preg_split('/\r?\n|\r/', $raw_header) as $header) {
    $coron_splited = preg_split('/: /', $header);
    if (count($coron_splited) !== 2) {
      continue;
    }
    $returned[$coron_splited[0]] = $coron_splited[1];
  };
  return new Headers($returned);
};

function request ($url, $method = 'GET') {
  $curl_ch = curl_init($url);
  curl_setopt_array($curl_ch, [
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true
  ]);
  $response = curl_exec($curl_ch);
  $informations = curl_getinfo($curl_ch);
  $raw_header = substr($response, 0, $informations['header_size']);
  $headers = header_split($raw_header);
  $body = substr($response, $informations['header_size']);
  return [
    'body' => $body,
    'headers' => $headers,
    'info' => $informations
  ];
}

$ooo = request('https://prezzemolo.ga/');
var_dump($ooo['headers']->Date);
?>
