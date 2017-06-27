<?php
function strcasecmpbool ($str1, $str2) {
  return strcasecmp($str1, $str2) === 0;
}

class HeadersReadOnlyException extends Exception {}

class Headers implements ArrayAccess {
  private $headers = [];
  private const E_READ_ONLY = "Headers objects are always read only.";

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

  public function offsetGet ($raw_header_field_name) {
    $header_field_name = $this->preen_header_field_name($raw_header_field_name);
    return $this->headers[$header_field_name] ?? null;
  }

  public function offsetExists ($raw_header_field_name) {
    $header_field_name = $this->preen_header_field_name($raw_header_field_name);
    return isset($this->headers[$header_field_name]);
  }

  /*
   * user can't write to this ArrayLike Object
   */
   public function offsetSet ($raw_header_field_name, $header_field_value) {
     throw new HeadersReadOnlyException($this::E_READ_ONLY);
   }

   public function offsetUnset ($raw_header_field_name) {
     throw new HeadersReadOnlyException($this::E_READ_ONLY);
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
function request (string $method, string $url, array $headers = [], string $body = null): array {
  // create cURL session
  $curl_ch = curl_init($url);

  // set HTTP method
  if (strcasecmpbool($method, 'HEAD')) {
    // OMG! why we can't use CURLOPT_CUSTOMREQUEST for HEAD?
    curl_setopt($curl_ch, CURLOPT_NOBODY, true);
  } else if (!strcasecmpbool($method, 'GET')) {
    curl_setopt($curl_ch, CURLOPT_CUSTOMREQUEST, $method);
  }

  // set body
  if (isset($body)) {
    // block human error!!!!!
    if (strcasecmpbool($method, 'GET')) {
      throw new Exception('You can not set body when uses GET method.');
    }
    var_dump($body);
    curl_setopt($curl_ch, CURLOPT_POSTFIELDS, $body);
  }

  // set headers
  define('ANALIZZATORE_VERSION', '0.0.0-unstage');
  $request_headers = request_merge_headers([
    'User-Agent' => sprintf('Mozilla/5.0 (compatible; analizzatore/%s; +https://github.com/prezzemolo/analizzatore)', ANALIZZATORE_VERSION),
    'Accept-Language' => 'en',
    'Accept-Encoding' => 'gzip, identity'
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
  if ($headers['content-encoding'] === 'gzip') {
    $decoded_body = gzdecode($body);
  }
  return [
    'body' => $decoded_body ?? $body,
    'headers' => $headers,
    'info' => $informations
  ];
}

function extract_ogp ($meta_DOMNodeList) {
  $ogp_tags = [];
  foreach ($meta_DOMNodeList as $meta_DOMNode) {
   /**
    * check existance of attributes
    * http://php.net/manual/en/class.domnode.php#domnode.props.attributes
    * note: no special reason for disuse hasElements method
    */
   if ($meta_DOMNode->attributes === null) {
     continue;
   }
   // skip non OGP tag
   if (!$meta_DOMNode->attributes->getNamedItem('property')
    || substr($meta_DOMNode->attributes->getNamedItem('property')->textContent, 0, 3) !== 'og:') {
     continue;
   }
   // skip no content
   if (!$meta_DOMNode->attributes->getNamedItem('content')
    || !$meta_DOMNode->attributes->getNamedItem('content')->textContent) {
     continue;
   }
   $property = substr($meta_DOMNode->attributes->getNamedItem('property')->textContent, 3);
   $content = $meta_DOMNode->attributes->getNamedItem('content')->textContent;
   $ogp_tags[$property] = $content;
 }
 return $ogp_tags;
}

function extract_metadata ($meta_DOMNodeList) {
  $matadata = [];

  foreach ($meta_DOMNodeList as $meta_DOMNode) {
   /**
    * check existance of attributes
    * http://php.net/manual/en/class.domnode.php#domnode.props.attributes
    * note: no special reason for disuse hasElements method
    */
   if ($meta_DOMNode->attributes === null) {
     continue;
   }
   // skip non metadata tag
   if (!$meta_DOMNode->attributes->getNamedItem('name')
    || !$meta_DOMNode->attributes->getNamedItem('name')->textContent) {
     continue;
   }
   // skip no content
   if (!$meta_DOMNode->attributes->getNamedItem('content')
    || !$meta_DOMNode->attributes->getNamedItem('content')->textContent) {
     continue;
   }
   $name = $meta_DOMNode->attributes->getNamedItem('name')->textContent;
   $content = $meta_DOMNode->attributes->getNamedItem('content')->textContent;
   $metadata[$name] = $content;
  }
  return $metadata;
}

$site_addr = $argv[1] ?? 'https://prezzemolo.ga/';
$res = request('GET', $site_addr);
$res_body = $res['body'];
$res_headers = $res['headers'];
$res_isHTML = preg_match('/^.*\/html(:?;.*)?$/', $res_headers['content-type']) === 1;
$res_body_DOM = $res_isHTML ? DOMDocument::loadHTML($res_body) : NULL;
$res_body_DOM_head = $res_body_DOM->getElementsByTagName('head')->item(0);
if (isset($res_body_DOM_head)) {
  var_dump($res_body_DOM_head->getElementsByTagName('title')->item(0)->textContent);
  $meta = $res_body_DOM_head->getElementsByTagName('meta');
  var_dump(extract_ogp($meta));
  var_dump(extract_metadata($meta));
}
$res_info = $res['info'];
var_dump($res_headers['Content-encoding']);
var_dump((new Headers($res_info['request_header']))['user-AGent']);
var_dump($res_info['http_code']);
?>
