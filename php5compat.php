<?php

if (!\interface_exists('Throwable', false)) {
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
}

if (!\class_exists('Error', false)) {
    class Error extends Exception implements Throwable {
        // TODO make this not extend \Exception because it doesn't in PHP7,
        // but still make it instantiable and implement the same semantics.
    }
}

if (!\class_exists('ArithmeticError', false)) {
    class ArithmeticError extends Error {
    }
}

if (!\class_exists('AssertionError', false)) {
    class AssertionError extends Error {
    }
}

if (!\class_exists('DivisionByZeroError', false)) {
    class DivisionByZeroError extends ArithmeticError {
    }
}

if (!\class_exists('ParseError', false)) {
    class ParseError extends Error {
    }
}

if (!\class_exists('TypeError', false)) {
    class TypeError extends Error {
    }
}

/**
 * @param Throwable|Exception $x
 * @return Throwable
 * @throws Exception
 */
function throwable_create($x) {
    // In PHP7, both Exceptions and Errors are Throwable
    if ($x instanceof \Throwable)
        return $x;
    // In PHP5, only Errors are Throwable (above), so we have to wrap our Exception to make a Throwable
    if ($x instanceof \Exception)
        return new _ExceptionThrowable($x);
    throw new \Exception('Cannot create a Throwable from ' . (\is_object($x) ? \get_class($x) : gettype($x)));
}

/**
 * If the Throwable wraps an Exception (PHP5), this will unwrap it, but the result is no longer a Throwable.
 * @param Throwable $x
 * @return Exception|Throwable
 */
function throwable_unwrap(Throwable $x) {
    while ($x instanceof _ExceptionThrowable)
        $x = $x->unwrap();
    return $x;
}

// In PHP7, user classes can't implement Throwable.
if (\PHP_VERSION_ID >= 70000) {
    class _ExceptionThrowable {
        /** @var Exception */
        private $e;

        public function __construct(\Exception $e) {
            $this->e = $e;
        }

        public function unwrap() {
            return $this->e;
        }
    }
} else {
    class _ExceptionThrowable implements Throwable {
        /** @var Exception */
        private $e;

        public function __construct(\Exception $e) {
            $this->e = $e;
        }

        public function unwrap() {
            return $this->e;
        }

        public function getMessage() {
            return $this->e->getMessage();
        }

        public function getCode() {
            return $this->e->getCode();
        }

        public function getFile() {
            return $this->e->getFile();
        }

        public function getLine() {
            return $this->e->getLine();
        }

        public function getTrace() {
            return $this->e->getTrace();
        }

        public function getTraceAsString() {
            return $this->e->getTraceAsString();
        }

        public function getPrevious() {
            return throwable_create($this->e->getPrevious());
        }

        public function __toString() {
            return $this->e->__toString();
        }
    }
}
