<?php

interface Throwable {
    /** @return string */
    public function getMessage();

    /** @return int */
    public function getCode();

    /** @return string */
    public function getFile();

    /** @return int */
    public function getLine();

    /** @return array */
    public function getTrace();

    /** @return string */
    public function getTraceAsString();

    /** @return Throwable */
    public function getPrevious();

    /** @return string */
    public function __toString();
}
