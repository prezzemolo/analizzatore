<?php

namespace analizzatore\utils;

require_once join(DIRECTORY_SEPARATOR, [__DIR__, 'bool-wrappers.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, 'headers.php']);

use Exception;

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
  $headers = [];
  foreach ($request_headers as $request_header_field_name => $request_header_field_value) {
    $header = sprintf('%s: %s', mb_convert_case($request_header_field_name, MB_CASE_TITLE), $request_header_field_value);
    array_push($headers, $header);
  }
  return $headers;
}

/**
 * request: cURL session wrapper.
 */
function request (string $method, string $url, array $headers = [], string $body = null, bool $follow_redirect = true): array {
  // create cURL session
  $curl_ch = curl_init($url);

  // enable HTTP/2 if supported
  if (defined('CURL_HTTP_VERSION_2_0'))
    curl_setopt($curl_ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);

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
    curl_setopt($curl_ch, CURLOPT_POSTFIELDS, $body);
  }

  // set headers
  $request_headers = request_merge_headers([
    'Accept-Encoding' => 'gzip, identity'
  ], $headers);
  curl_setopt($curl_ch, CURLOPT_HTTPHEADER, request_assemble_curl_headers($request_headers));

  // set nesessary options to get header, body & catch timeout error
  curl_setopt_array($curl_ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLINFO_HEADER_OUT => true,
    CURLOPT_TIMEOUT => 5,
    // follow redirection 5 times
    CURLOPT_FOLLOWLOCATION => $follow_redirect,
    CURLOPT_MAXREDIRS => 5
  ]);

  // set response & informations
  $timestamp = time();
  $response = curl_exec($curl_ch);
  $informations = curl_getinfo($curl_ch);

  // error handling
  if (curl_errno($curl_ch) !== 0) {
    throw new Exception(curl_error($curl_ch));
  }

  curl_close($curl_ch);

  // cut header & body from raw response
  $raw_header = substr($response, 0, $informations['header_size']);
  $headers = new Headers($raw_header);
  $body = substr($response, $informations['header_size']);

  // decode gzip if body is encorded
  if ($headers['content-encoding'] === 'gzip') {
    $decoded_body = gzdecode($body);
  }

  return [
    'body' => $decoded_body ?? $body,
    'headers' => $headers,
    'info' => $informations,
    'status_code' => $informations['http_code'],
    'url' => $informations['url'],
    'timestamp' => $timestamp
  ];
}
