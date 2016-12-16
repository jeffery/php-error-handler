<?php

namespace IVT;

abstract class ErrorHandler {
    private static $constants = array(
        \E_ERROR => 'E_ERROR',
        \E_WARNING => 'E_WARNING',
        \E_PARSE => 'E_PARSE',
        \E_NOTICE => 'E_NOTICE',
        \E_CORE_ERROR => 'E_CORE_ERROR',
        \E_CORE_WARNING => 'E_CORE_WARNING',
        \E_COMPILE_ERROR => 'E_COMPILE_ERROR',
        \E_COMPILE_WARNING => 'E_COMPILE_WARNING',
        \E_USER_ERROR => 'E_USER_ERROR',
        \E_USER_WARNING => 'E_USER_WARNING',
        \E_USER_NOTICE => 'E_USER_NOTICE',
        \E_STRICT => 'E_STRICT',
        \E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
        \E_DEPRECATED => 'E_DEPRECATED',
        \E_USER_DEPRECATED => 'E_USER_DEPRECATED',
        \E_ALL => 'E_ALL',
    );

    private static $names = array(
        \E_ERROR => 'Fatal Error',
        \E_WARNING => 'Warning',
        \E_PARSE => 'Parse Error',
        \E_NOTICE => 'Notice',
        \E_CORE_ERROR => 'Core Error',
        \E_CORE_WARNING => 'Core Warning',
        \E_COMPILE_ERROR => 'Compile Error',
        \E_COMPILE_WARNING => 'Compile Warning',
        \E_USER_ERROR => 'User Error',
        \E_USER_WARNING => 'User Warning',
        \E_USER_NOTICE => 'User Notice',
        \E_STRICT => 'Strict Standards',
        \E_RECOVERABLE_ERROR => 'Recoverable Error',
        \E_DEPRECATED => 'Deprecated',
        \E_USER_DEPRECATED => 'User Deprecated',
    );

    public final static function phpErrorConstant($type) {
        return isset(self::$constants[$type]) ? self::$constants[$type] : 'E_?';
    }

    public final static function phpErrorName($type) {
        return isset(self::$names[$type]) ? self::$names[$type] : 'Unknown Error';
    }

    public final static function isFatal($type) {
        return $type & (\E_ERROR | \E_PARSE | \E_CORE_ERROR | \E_COMPILE_ERROR) ? true : false;
    }

    public function __construct() {
    }

    public function __destruct() {
        $this->flush();
    }

    public final function push() {
        $self = $this;

        \set_error_handler($handleError = function ($type, $message, $file, $line) use ($self) {
            $e = new \ErrorException($message, 0, $type, $file, $line);

            $prop = new \ReflectionProperty('Exception', 'trace');
            $prop->setAccessible(true);
            $prop->setValue($e, \array_slice($e->getTrace(), 0, 1));

            $prop = new \ReflectionProperty('Exception', 'code');
            $prop->setAccessible(true);
            $prop->setValue($e, ErrorHandler::phpErrorConstant($type));

            $self->notifyError($e, ErrorHandler::isFatal($type));
        });

        \set_exception_handler(function ($e) use ($self) {
            $self->notifyException(\throwable_create($e), true);
        });

        \register_shutdown_function(function () use ($handleError, $self) {
            \ini_set('memory_limit', '-1');

            // Only call our error handler if we are the currently bound error handler
            $handler = \set_error_handler(null);
            \restore_error_handler();
            if ($handler !== $handleError) {
                $self->flush();
                return;
            }

            $error = \error_get_last();
            if (
                $error !== null &&
                ErrorHandler::isFatal($error['type']) &&
                (\substr($error['file'], 0, 9) !== 'xdebug://')
            ) {
                $handleError(
                    $error['type'],
                    $error['message'],
                    $error['file'],
                    $error['line']
                );
            }

            $self->flush();
        });
    }

    public final function pop() {
        \restore_error_handler();
        \restore_exception_handler();
    }

    public function flush() {
    }

    public abstract function notifyException(\Throwable $e, $fatal);

    public abstract function notifyError(\ErrorException $e, $fatal);
}


