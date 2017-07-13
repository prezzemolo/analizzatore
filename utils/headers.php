<?php

namespace analizzatore\utils;

require_once join(DIRECTORY_SEPARATOR, [__DIR__, 'bool-wrappers.php']);
require_once join(DIRECTORY_SEPARATOR, [__DIR__, '..', 'common', 'exceptions.php']);

use Exception;
use ArrayAccess;
use analizzatore\exceptions\HeadersReadOnlyException;

class Headers implements ArrayAccess {
  private $headers = [];
  private const E_READ_ONLY = "Headers objects are always read only.";

  private function preen_header_field_name ($raw_header_field_name) {
    return mb_strtolower($raw_header_field_name);
  }

  function __construct ($raw_header, $allheaders) {
    // support request headers array by getallheaders func
    if ($allheaders) {
      foreach ($allheaders as $header_field_name => $header_field_value) {
        $header_field_name = $this->preen_header_field_name($header_field_name);
        $this->headers[$header_field_name] = $header_field_value;
      }
      return;
    }

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
