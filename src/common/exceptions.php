<?php

namespace analizzatore\exceptions;

use Exception;

class DenyException extends Exception {
  private $title;

  public function __construct (int $status, string $title, ...$rest) {
    $this->status = $status;
    $this->title = $title;
    parent::__construct(...$rest);
  }

  final public function getTitle () {
    return $this->title;
  }

  final public function getStatus () {
    return $this->status;
  }
}

class HeadersReadOnlyException extends Exception {}
