<?php

namespace analizzatore\exceptions;

use Exception;

class DenyException extends Exception {
  private $title;

  public function __construct (string $title, string $message, $code = 500, Exception $previous = null) {
    $this->title = $title;
    parent::__construct($message, $code, $previous);
  }

  final public function getTitle () {
    return $this->title;
  }
}

class HeadersReadOnlyException extends Exception {}

?>
