<?php

namespace analizzatore\exceptions;

use Exception;

class DenyException extends Exception {
  private $title;

  public function __construct (int $status, string $title, ...$rest) {
    $this->status = $status;
    $this->title = $title;
    return parent::__construct(...$rest);
  }

  final public function getTitle (): string {
    return $this->title;
  }

  final public function getStatus (): int {
    return $this->status;
  }
}

class HeadersReadOnlyException extends Exception {}
