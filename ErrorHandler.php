<?php

namespace IVT\ErrorHandler;

use Exception;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

if (\PHP_VERSION_ID < 70000) {
    final class ThrowableException implements Throwable {
        /** @var \Exception */
        private $exc;

        public function __construct(Exception $exc) {
            $this->exc = $exc;
        }

        public function unwrap() {
            return $this->exc;
        }

        public function getMessage() {
            return $this->exc->getMessage();
        }

        public function getCode() {
            return $this->exc->getCode();
        }

        public function getFile() {
            return $this->exc->getFile();
        }

        public function getLine() {
            return $this->exc->getLine();
        }

        public function getTrace() {
            return $this->exc->getTrace();
        }

        public function getTraceAsString() {
            return $this->exc->getTraceAsString();
        }

        public function getPrevious() {
            return ErrorHandler::createThrowable($this->exc->getPrevious());
        }

        public function __toString() {
            return $this->exc->__toString();
        }
    }
}

final class ErrorException extends \ErrorException {
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

    public static function getLast() {
        $error = \error_get_last();
        if (!$error)
            return null;
        return new self(
            $error['message'],
            0,
            $error['type'],
            $error['file'],
            $error['line']
        );
    }

    public function getConstant() {
        return self::$constants[$this->severity];
    }

    public function getName() {
        return self::$names[$this->severity];
    }

    public function isFatal() {
        return $this->isType(\E_ERROR | \E_PARSE | \E_CORE_ERROR | \E_COMPILE_ERROR);
    }

    public function isUserError() {
        return $this->isType(\E_USER_ERROR | \E_USER_WARNING | \E_USER_NOTICE | \E_USER_DEPRECATED);
    }

    public function isXDebugError() {
        return \substr($this->file, 0, 9) !== 'xdebug://';
    }

    public function isReportable() {
        return $this->isType(\error_reporting());
    }

    public function isType($types) {
        return (bool)($this->severity & $types);
    }
}

abstract class ErrorHandler {
    /**
     * @param Throwable|Exception $x
     * @return Throwable
     * @throws Exception
     */
    public final static function createThrowable($x) {
        // In PHP7, both Exceptions and Errors are Throwable
        if ($x instanceof \Throwable)
            return $x;
        // In PHP5, Exceptions are not Throwable, so we have to wrap it to make it Throwable
        if ($x instanceof \Exception)
            return new ThrowableException($x);
        return $x;
    }

    /**
     * If the Throwable wraps an Exception (PHP5), this will unwrap it, but the result is no longer a Throwable.
     * @param Throwable $x
     * @return Exception|Throwable
     */
    public final static function unwrapThrowable(Throwable $x) {
        if ($x instanceof ThrowableException)
            return $x->unwrap();
        return $x;
    }

    private $bound = false;

    public function __construct() {
    }

    public function __destruct() {
        $this->flush();
    }

    public final function bind() {
        $self = $this;

        \set_error_handler(function ($type, $message, $file, $line) use ($self) {
            $self->notifyError(new ErrorException($message, 0, $type, $file, $line));
        });

        \set_exception_handler(function ($e) use ($self) {
            $self->notifyThrowable(ErrorHandler::createThrowable($e));
        });

        if (!$this->bound) {
            \register_shutdown_function(function () use ($self) {
                \ini_set('memory_limit', '-1');

                $error = ErrorException::getLast();

                if ($error && $error->isFatal() && !$error->isXDebugError()) {
                    $self->notifyError($error);
                }

                $self->flush();
            });

            $this->bound = true;
        }
    }

    public function flush() {
    }

    public abstract function notifyThrowable(Throwable $e);

    public abstract function notifyError(ErrorException $e);
}

class WrappedErrorHandler extends ErrorHandler {
    private $handler;

    public function __construct(ErrorHandler $handler) {
        parent::__construct();
        $this->handler = $handler;
    }

    public function flush() {
        parent::flush();
        $this->handler->flush();
    }

    public function notifyError(ErrorException $e) {
        $this->handler->notifyError($e);
    }

    public function notifyThrowable(Throwable $e) {
        $this->handler->notifyThrowable($e);
    }
}

class ThrowErrorExceptionsHandler extends WrappedErrorHandler {
    private static function isPhpBug61767Fixed() {
        if (\PHP_MAJOR_VERSION == 5) {
            if (\PHP_MINOR_VERSION == 3) {
                return \PHP_RELEASE_VERSION >= 18;
            } else if (\PHP_MINOR_VERSION == 4) {
                return \PHP_RELEASE_VERSION >= 8;
            } else {
                return \PHP_MINOR_VERSION > 4;
            }
        } else {
            return \PHP_MAJOR_VERSION > 5;
        }
    }

    public function notifyError(ErrorException $e) {
        if ($e->isFatal()) {
            parent::notifyError($e);
        } else {

            if ($e->isUserError() || self::isPhpBug61767Fixed())
                throw $e;

            $this->notifyThrowable(self::createThrowable($e));
            exit;
        }
    }
}

class ThrowReportableErrorExceptionsHandler extends ThrowErrorExceptionsHandler {
    public function notifyError(ErrorException $e) {
        if ($e->isFatal() || $e->isReportable()) {
            parent::notifyError($e);
        }
    }
}

class IgnoreRepeatedHandler extends WrappedErrorHandler {
    private $seen = array();

    public function notifyError(ErrorException $e) {
        if (!$this->seen('error', $e)) {
            parent::notifyError($e);
        }
    }

    public function notifyThrowable(Throwable $e) {
        if (!$this->seen('throwable', $e)) {
            parent::notifyThrowable($e);
        }
    }

    private function seen($key, Throwable $e) {
        $string = \join(' ', array(
            $key,
            $e instanceof \ErrorException ? $e->getSeverity() : '',
            $e->getCode(),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
        ));

        if (isset($this->seen[$string]))
            return true;
        $this->seen[$string] = true;
        return false;
    }
}

class IgnoreWithChanceHandler extends WrappedErrorHandler {
    /** @var float */
    private $probability;

    public function __construct(ErrorHandler $handler, $probability) {
        parent::__construct($handler);
        $this->probability = $probability;
    }

    public function notifyError(ErrorException $e) {
        if ($e->isFatal() || $this->rand() < $this->probability) {
            parent::notifyError($e);
        }
    }

    private function rand() {
        return \mt_rand() / (\mt_getrandmax() + 1);
    }
}

class PsrLogHandler extends ErrorHandler {
    private static $levels = array(
        \E_ERROR => LogLevel::CRITICAL,
        \E_WARNING => LogLevel::WARNING,
        \E_PARSE => LogLevel::ALERT,
        \E_NOTICE => LogLevel::NOTICE,
        \E_CORE_ERROR => LogLevel::CRITICAL,
        \E_CORE_WARNING => LogLevel::WARNING,
        \E_COMPILE_ERROR => LogLevel::ALERT,
        \E_COMPILE_WARNING => LogLevel::WARNING,
        \E_USER_ERROR => LogLevel::ERROR,
        \E_USER_WARNING => LogLevel::WARNING,
        \E_USER_NOTICE => LogLevel::NOTICE,
        \E_STRICT => LogLevel::NOTICE,
        \E_RECOVERABLE_ERROR => LogLevel::ERROR,
        \E_DEPRECATED => LogLevel::NOTICE,
        \E_USER_DEPRECATED => LogLevel::NOTICE,
    );

    /** @var LoggerInterface */
    private $logger;

    public function __construct(LoggerInterface $logger) {
        parent::__construct();
        $this->logger = $logger;
    }

    public function notifyError(ErrorException $e) {
        $code = $e->getSeverity();
        $class = $e->getConstant();
        $level = self::$levels[$code];

        $this->notify(self::createThrowable($e), $class, $level);
    }

    public function notifyThrowable(Throwable $e) {
        $level = LogLevel::CRITICAL;
        $class = \get_class(self::unwrapThrowable($e));

        $this->notify($e, $class, $level);
    }

    private function notify(Throwable $e, $class, $level) {
        $message = \substr("$class: " . $e->getMessage(), 0, 100);
        $context = array(
            'code' => $e->getCode(),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        );
        $this->logger->log($level, $message, $context);
    }
}

class BrowserLogger extends \Psr\Log\AbstractLogger {
    private $logger;

    public function __construct() {
        $this->logger = new \Monolog\Logger('PHP Errors');
        $this->logger->pushHandler(new \Monolog\Handler\BrowserConsoleHandler());
    }

    public function log($level, $message, array $context = array()) {
        $this->logger->log($level, $message, $context);
    }
}

class BugsnagHandler extends ErrorHandler {
    private static $levels = array(
        \E_ERROR => 'error',
        \E_WARNING => 'warning',
        \E_PARSE => 'error',
        \E_NOTICE => 'info',
        \E_CORE_ERROR => 'error',
        \E_CORE_WARNING => 'warning',
        \E_COMPILE_ERROR => 'error',
        \E_COMPILE_WARNING => 'warning',
        \E_USER_ERROR => 'error',
        \E_USER_WARNING => 'warning',
        \E_USER_NOTICE => 'info',
        \E_STRICT => 'info',
        \E_RECOVERABLE_ERROR => 'error',
        \E_DEPRECATED => 'info',
        \E_USER_DEPRECATED => 'info',
    );

    private static function getContext() {
        if (PHP_SAPI === 'cli' && isset($_SERVER['argv'])) {
            $args = array();
            foreach ($_SERVER['argv'] as $arg) {
                $args[] = \escapeshellarg($arg);
            }
            return \join(' ', $args);
        } else if (isset($_SERVER['REQUEST_METHOD'])) {
            $method = $_SERVER['REQUEST_METHOD'];
            $url = \Bugsnag_Request::getCurrentUrl();

            return "$method $url";
        } else {
            return 'unknown';
        }
    }

    /** @var \Bugsnag_Configuration */
    private $config;
    /** @var \Bugsnag_Notification */
    private $notification;

    public function __construct() {
        $config = new \Bugsnag_Configuration;
        $config->filters = array(); // Defaults to ['password'] and causes slowness in Bugsnag_Error::cleanupObj
        $config->context = self::getContext();
        $config->sendSession = false;

        // cURL by default sends a "Expect: 100-Continue" header and waits for a "100 Continue" response
        // before sending the body. `bugsnag-agent` for some reason takes a whole second to respond "100 Continue",
        // slowing everything down. So disable this behaviour by blanking out the "Expect" header.
        // We need to include "Content-Type: application/json" because that's what the Bugsnag library had
        // there by default.
        $config->curlOptions = array(
            CURLOPT_HTTPHEADER => array(
                'Expect:',
                'Content-Type: application/json',
            ),
        );

        $this->config = $config;
        $this->notification = new \Bugsnag_Notification($config);
    }

    public function setApiKey($key) {
        $this->config->apiKey = $key;
    }

    public function setEndpoint($endpoint) {
        $this->config->endpoint = $endpoint;
    }

    public function setAppVersion($appVersion) {
        $this->config->appVersion = $appVersion;
    }

    public function setProjectRoot($root) {
        $this->config->setProjectRoot($root);
    }

    public function setStripPath($path) {
        $this->config->setStripPath($path);
    }

    public function notifyError(ErrorException $e, BugsnagExtra $extra = null) {
        if ($e->isFatal()) {
            \ini_set('memory_limit', '-1');
        }

        $extra = $extra ?: new BugsnagExtra();

        $config = clone $this->config;
        $config->sendCode = $extra->getSendCode();

        $error = \Bugsnag_Error::fromPHPError(
            $config,
            new \Bugsnag_Diagnostics($config),
            $e->getSeverity(),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            true // Call it fatal so Bugsnag doesn't waste time generating its own stack trace
        );

        // We only want to send code for fatal ErrorExceptions
        $error->setStacktrace(\Bugsnag_Stacktrace::fromBacktrace($config, $e->getTrace(), $e->getFile(), $e->getLine()));
        $error->setSeverity(self::$levels[$e->getSeverity()]);

        $this->notification->addError($error, $extra->getMetaData());
    }

    public function notifyThrowable(\Throwable $e, BugsnagExtra $extra = null) {
        \ini_set('memory_limit', '-1');

        $extra = $extra ?: new BugsnagExtra();

        $config = clone $this->config;
        $config->sendCode = $extra->getSendCode();

        $error = \Bugsnag_Error::fromPHPThrowable($config, new \Bugsnag_Diagnostics($config), self::unwrapThrowable($e));
        $error->setSeverity('error');

        $this->notification->addError($error, $extra->getMetaData());
    }

    public function flush() {
        $this->notification->deliver();
    }
}

final class BugsnagExtra {
    private $metaData = array();
    private $sendCode = true;

    public function getMetaData() {
        return $this->metaData;
    }

    public function setMetaData($metaData) {
        $this->metaData = $metaData;
    }

    public function getSendCode() {
        return $this->sendCode;
    }

    public function setSendCode($sendCode) {
        $this->sendCode = $sendCode;
    }
}


