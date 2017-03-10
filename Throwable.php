<?php

// When observed via PHP's Reflection API, this is intended to look as close as possible to the native Throwable
// interface that ships with PHP 7. For that reason, please don't add, remove, re-order or change the signature or
// doc comment of any entries in this interface.

interface Throwable {
    public function getMessage();
    public function getCode();
    public function getFile();
    public function getLine();
    public function getTrace();
    public function getPrevious();
    public function getTraceAsString();
    public function __toString();
}
