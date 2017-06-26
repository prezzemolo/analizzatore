<?php
function strcasecmpbool ($str1, $str2) {
  return strcasecmp($str1, $str2) === 0;
}

class Headers {
  private $headers = [];

  private function preen_header_field_name ($raw_header_field_name) {
    return mb_strtolower($raw_header_field_name);
  }

  function __construct ($raw_header) {
    foreach (preg_split('/\r?\n|\r/', $raw_header) as $header) {
      $header_pair = preg_split('/: /', $header);
      if (count($header_pair) !== 2) {
        continue;
      }
      list($header_field_name, $header_field_value) = $header_pair;
      $header_field_name = $this->preen_header_field_name($header_field_name);
      // check duplicate field name for appending
      if (array_key_exists($header_field_name, $this->headers)) {
        /**
         * 'Note: In practice, the "Set-Cookie" header field ([RFC6265]) often
         * appears multiple times in a response message and does not use the
         * list syntax, violating the above requirements on multiple header
         * fields with the same name.  Since it cannot be combined into a
         * single field-value, recipients ought to handle "Set-Cookie" as a
         * special case while processing header fields.'
         * https://tools.ietf.org/html/rfc7230//appendix-A.2.3
         */
         if (strcasecmpbool($header_field_name, 'set-cookie')) {
           $this->headers[$header_field_name] .= '; ' . $header_field_value;
           continue;
         }
        /**
         * 'appending each subsequent field value to the combined field value in order, separated by a comma'
         * https://tools.ietf.org/html/rfc7230//section-3.2.2
         */
        $this->headers[$header_field_name] .= ', ' . $header_field_value;
        continue;
      }
      $this->headers[$header_field_name] = $header_field_value;
    }
  }

  /**
   * http://php.net/manual/ja/language.oop5.magic.php
   */
  public function __get ($raw_header_field_name) {
    $header_field_name = $this->preen_header_field_name($raw_header_field_name);
    return $this->headers[$header_field_name] ?? null;
  }

  public function __isset ($raw_header_field_name) {
    $header_field_name = $this->preen_header_field_name($raw_header_field_name);
    return isset($this->headers[$header_field_name]);
  }
}


/**
 * request_merge_headers
 * merge headers for request.
 */
function request_merge_headers ($arr1, $arr2) {
  return array_merge(array_change_key_case($arr1), array_change_key_case($arr2));
}

/**
 * request_assemble_headers
 * assemble an array for cURL CURLOPT_HTTPHEADER from an array key/value request header.
*/
function request_assemble_curl_headers ($request_headers) {
  $headers = array();
  foreach ($request_headers as $request_header_field_name => $request_header_field_value) {
    $header = sprintf('%s: %s', mb_convert_case($request_header_field_name, MB_CASE_TITLE), $request_header_field_value);
    array_push($headers, $header);
  }
  return $headers;
}

/**
 * request: cURL session wrapper.
 */
function request (string $url, array $headers = [], string $method = 'GET', string $body = null): array {
  // create cURL session
  $curl_ch = curl_init($url);

  // set HTTP method
  if (strcasecmpbool($method, 'HEAD')) {
    // OMG! why we can't use CURLOPT_CUSTOMREQUEST for HEAD?
    curl_setopt($curl_ch, CURLOPT_NOBODY, true);
  } else if (strcasecmpbool($method, 'GET') !== true) {
    curl_setopt($curl_ch, CURLOPT_CUSTOMREQUEST, $method);
  }

  // set body
  if ($body !== null) {
    curl_setopt($curl_ch, CURLOPT_POSTFIELDS, $body);
  }

  // set headers
  define('ANALIZZATORE_VERSION', '0.0.0');
  $request_headers = request_merge_headers([
    'User-Agent' => sprintf('Mozilla/5.0 (compatible; analizzatore/%s; +https://github.com/prezzemolo/analizzatore)', ANALIZZATORE_VERSION)
  ], $headers);
  curl_setopt($curl_ch, CURLOPT_HTTPHEADER, request_assemble_curl_headers($request_headers));

  // set option for getting body & header with exec
  curl_setopt_array($curl_ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLINFO_HEADER_OUT => true
  ]);
  $response = curl_exec($curl_ch);
  $informations = curl_getinfo($curl_ch);
  curl_close($curl_ch);
  $raw_header = substr($response, 0, $informations['header_size']);
  $headers = new Headers($raw_header);
  $body = substr($response, $informations['header_size']);
  return [
    'body' => $body,
    'headers' => $headers,
    'info' => $informations
  ];
}

$site_addr = $argv[1] ?? 'https://prezzemolo.ga/';
$res = request($site_addr, [
  'User-Agent' => sprintf('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/59.0.3071.104 Safari/537.36 PHP/%s', PHP_VERSION)
]);
$res_body = $res['body'];
$res_headers = $res['headers'];
$res_isHTML = preg_match('/^.*\/html(:?;.*)?$/', $res_headers->{'content-type'}) === 1;
$res_body_DOM = $res_isHTML ? DOMDocument::loadHTML($res_body) : NULL;
$res_info = $res['info'];
var_dump($res_body_DOM->getElementsByTagName('title')[0] ?? $res_body);
var_dump($res_headers->{'content-type'});
var_dump($res_info['request_header']);
?>
