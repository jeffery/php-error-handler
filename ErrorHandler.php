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
            $prev = $this->exc->getPrevious();
            return $prev ? ErrorHandler::createThrowable($prev) : null;
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
        $self = new self(
            $error['message'],
            0,
            $error['type'],
            $error['file'],
            $error['line']
        );
        // We don't have a trace for the last error
        $self->setTrace(array());
        return $self;
    }

    private $context;

    public function getConstant() {
        return self::$constants[$this->severity];
    }

    public function getName() {
        return self::$names[$this->severity];
    }

    public function getContext() {
        return $this->context;
    }

    public function isFatal() {
        return $this->isType(\E_ERROR | \E_PARSE | \E_CORE_ERROR | \E_COMPILE_ERROR);
    }

    public function isUserError() {
        return $this->isType(\E_USER_ERROR | \E_USER_WARNING | \E_USER_NOTICE | \E_USER_DEPRECATED);
    }

    public function isXDebugError() {
        return \substr($this->file, 0, 9) === 'xdebug://';
    }

    public function isReportable() {
        return $this->isType(\error_reporting());
    }

    public function isType($types) {
        return (bool)($this->severity & $types);
    }

    public function setCode($code) {
        $this->code = $code;
    }

    public function setMessage($message) {
        $this->message = $message;
    }

    public function setFile($file) {
        $this->file = $file;
    }

    public function setLine($line) {
        $this->line = $line;
    }

    public function setSeverity($severity) {
        $this->severity = $severity;
    }

    public function setContext($context) {
        $this->context = $context;
    }

    public function setTrace($trace) {
        $prop = new \ReflectionProperty('Exception', 'trace');
        $prop->setAccessible(true);
        $prop->setValue($this, $trace);
    }

    public function popStackFrame() {
        $trace = $this->getTrace();
        if ($trace) {
            $this->setTrace(\array_slice($trace, 1));
        }
    }
}

/** An abstract means of handling PHP errors and throwables */
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
        // Nothing else is acceptable.
        $type = \is_object($x) ? \get_class($x) : \gettype($x);
        throw new Exception("Can't convert a $type to a Throwable");
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

    private $fatalDone = false;

    public function __construct() {
    }

    public function __destruct() {
    }

    public final function bind() {
        $self = $this;

        // I can't just reference this via $self (or $this) because closures in
        // PHP 5.3 don't have access to private properties.
        $fatalDone =& $this->fatalDone;

        \set_error_handler(function ($type, $message, $file, $line, $context) use ($self) {
            $error = new ErrorException($message, 0, $type, $file, $line);
            $error->popStackFrame();
            $error->setContext($context);
            $error->setCode($error->getConstant());

            $self->notifyError($error);

            // Allow PHP's builtin error handler to continue (for logging to stderr etc)
            return false;
        });

        \set_exception_handler(function ($e) use ($self, &$fatalDone) {
            $self->notifyThrowable(ErrorHandler::createThrowable($e), true);

            $self->flush();

            // Throw the exception again so PHP's builtin error handler handles it too, but
            // set $fatalDone = true so we know to ignore it in the shutdown handler.
            $fatalDone = true;

            throw $e;
        });

        \register_shutdown_function(function () use ($self, &$fatalDone) {
            \ini_set('memory_limit', '-1');

            if (!$fatalDone) {
                $error = ErrorException::getLast();

                if ($error && $error->isFatal() && !$error->isXDebugError()) {
                    $error->setCode($error->getConstant());

                    $self->notifyError($error);
                }
            }

            $self->flush();

            // This is to avoid handling the same fatal error twice if this shutdown function was bound multiple times
            // (eg because ->bind() was called multiple times)
            $fatalDone = true;
        });
    }

    /**
     * Destructors are not called in the case of a fatal error, so if your class has data buffered that it needs to
     * write somewhere, you should override this method and do it there in addition to doing it in __destruct().
     *
     * This method is not called by the destructor by default because error handlers that wrap other error handlers
     * will override flush() and call it recursively, and object destructors are called by PHP for every object in
     * an object graph, which would result in an exponential number of flush() calls.
     */
    public function flush() {
    }

    /**
     * @param Throwable $e Any throwable. May be an ErrorException that was thrown and is thus treated as an exception
     *                     rather than a PHP error.
     * @param bool $fatal True for uncaught throwables, false for throwables given directly to the handler without
     *                     being thrown.
     * @return void
     */
    public abstract function notifyThrowable(Throwable $e, $fatal);

    /**
     * @param ErrorException $e
     * @return void
     */
    public abstract function notifyError(ErrorException $e);
}

class NullHandler extends ErrorHandler {
    public function notifyThrowable(Throwable $e, $fatal) {
    }

    public function notifyError(ErrorException $e) {
    }
}

abstract class DelegateErrorHandler extends ErrorHandler {
    public function notifyThrowable(Throwable $e, $fatal) {
        $this->getHandler()->notifyThrowable($e, $fatal);
    }

    public function notifyError(ErrorException $e) {
        $this->getHandler()->notifyError($e);
    }

    /**
     * @return ErrorHandler
     */
    public abstract function getHandler();
}

class WrappedErrorHandler extends DelegateErrorHandler {
    private $handler;

    public function __construct(ErrorHandler $handler) {
        parent::__construct();
        $this->handler = $handler;
    }

    public function flush() {
        parent::flush();
        $this->handler->flush();
    }

    public function getHandler() {
        return $this->handler;
    }
}

final class AggregateErrorHandler extends ErrorHandler {
    /** @var ErrorHandler[] */
    private $handlers = array();

    public function __construct(array $handlers = array()) {
        $this->handlers = $handlers;
    }

    public function notifyThrowable(Throwable $e, $fatal) {
        foreach ($this->handlers as $handler) {
            $handler->notifyThrowable($e, $fatal);
        }
    }

    public function notifyError(ErrorException $e) {
        foreach ($this->handlers as $handler) {
            $handler->notifyError($e);
        }
    }

    public function flush() {
        parent::flush();
        foreach ($this->handlers as $handler) {
            $handler->flush();
        }
    }

    public function append(ErrorHandler $handler) {
        $this->handlers[] = $handler;
    }

    public function prepend(ErrorHandler $handler) {
        \array_unshift($this->handlers, $handler);
    }
}

/** Throw non-fatal ErrorExceptions */
class ThrowErrorExceptionsHandler extends WrappedErrorHandler {
    private $types;

    /**
     * @param ErrorHandler $handler
     * @param int $types The error types to throw. Defaults to all. Fatal errors are never thrown.
     */
    public function __construct(ErrorHandler $handler, $types = -1) {
        parent::__construct($handler);
        $this->types = $types;
    }

    private static function isPhpBug61767Fixed() {
        // Fixed in 5.4.8 and 5.3.18
        if (\PHP_VERSION_ID >= 50400)
            return \PHP_VERSION_ID >= 50408;
        return \PHP_VERSION_ID >= 50318;
    }

    public function shouldThrow(/** @noinspection PhpUnusedParameterInspection */
        ErrorException $e) {
        return true;
    }

    public function notifyError(ErrorException $e) {
        if (!$e->isFatal() && $e->isType($this->types) && $this->shouldThrow($e)) {
            if ($e->isUserError() || self::isPhpBug61767Fixed())
                throw $e;

            // PHP Bug 61767 prohibits us from throwing exceptions in an error handler.
            // If that bug isn't fixed, bypass all the catch/finally blocks and handle it as
            // an uncaught exception and exit.
            $this->notifyThrowable(self::createThrowable($e), true);
            exit;
        }

        parent::notifyError($e);
    }
}

/** Throw non-fatal ErrorExceptions if covered by error_reporting() */
class ThrowReportableErrorExceptionsHandler extends ThrowErrorExceptionsHandler {
    public function shouldThrow(ErrorException $e) {
        return $e->isReportable() && parent::shouldThrow($e);
    }
}

/** Ignore repeated non-fatal errors and throwables */
class IgnoreRepeatedHandler extends WrappedErrorHandler {
    private $seen = array();

    public function notifyError(ErrorException $e) {
        if ($e->isFatal() || !$this->seen('error', self::createThrowable($e))) {
            parent::notifyError($e);
        }
    }

    public function notifyThrowable(Throwable $e, $fatal) {
        if ($fatal || !$this->seen('throwable', $e)) {
            parent::notifyThrowable($e, $fatal);
        }
    }

    private function seen($key, Throwable $e) {
        $e = self::unwrapThrowable($e);

        $string = \join(' ', array(
            $key,
            \get_class($e),
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

/** Only let non-fatal PHP errors through that match a type mask */
class FilterErrorsHandler extends WrappedErrorHandler {
    /** @var int */
    private $types;

    /**
     * @param ErrorHandler $handler
     * @param int $types
     */
    public function __construct(ErrorHandler $handler, $types) {
        parent::__construct($handler);
        $this->types = $types;
    }

    public function notifyError(ErrorException $e) {
        if ($e->isType($this->types) || $e->isFatal()) {
            parent::notifyError($e);
        }
    }
}

/** Only let non-fatal PHP errors through with a given probability */
class FilterErrorsWithChanceHandler extends WrappedErrorHandler {
    /** @var float */
    private $probability;
    /** @var int */
    private $types;

    /**
     * @param ErrorHandler $handler
     * @param float $probability
     * @param int $types Mask for error types to filter. Errors not matching the mask are let through unconditionally.
     */
    public function __construct(ErrorHandler $handler, $probability, $types = -1) {
        parent::__construct($handler);
        $this->probability = $probability;
        $this->types = $types;
    }

    public function notifyError(ErrorException $e) {
        if ($e->isFatal() || !$e->isType($this->types) || $this->rand() < $this->probability) {
            parent::notifyError($e);
        }
    }

    /** @return float in the range [0,1) */
    private function rand() {
        return \mt_rand() / (\mt_getrandmax() + 1);
    }
}

/** Log errors to a Psr logger */
class PsrLogHandler extends ErrorHandler {
    /** Yanked from Monolog\ErrorHandler::defaultErrorLevelMap() */
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

    public function notifyThrowable(Throwable $e, $fatal) {
        $level = $fatal ? LogLevel::CRITICAL : LogLevel::WARNING;
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

/** Log to the web browser's console */
final class BrowserLogger extends \Psr\Log\AbstractLogger {
    private $logger;

    public function __construct($name = 'PHP Errors') {
        $this->logger = new \Monolog\Logger($name);
        $this->logger->pushHandler(new \Monolog\Handler\BrowserConsoleHandler());
    }

    public function log($level, $message, array $context = array()) {
        $this->logger->log($level, $message, $context);
    }
}

/** Render an error page for errors on screen. */
class ErrorPageHandler extends ErrorHandler {
    public function notifyThrowable(Throwable $e, $fatal) {
        if (!$fatal)
            return;

        $this->printErrorPage($e);
    }

    public function notifyError(ErrorException $e) {
        if (!$e->isFatal())
            return;

        $this->printErrorPage(self::createThrowable($e));
    }

    private function printErrorPage(Throwable $e) {
        if (\PHP_SAPI === 'cli') {
            return;
        }

        while (\ob_get_level() > 0 && \ob_end_clean()) {
            // pass
        }

        $html = $this->generateHtml($e);

        if (!\headers_sent()) {
            \header('HTTP/1.1 500 Internal Server Error', true, 500);
            \header("Content-Type: text/html; charset=UTF-8", true);
            // In principle sending Content-Length could let the browser know
            // that the response has ended once we send that number of bytes.
            // In practice it doesn't make any difference and Chrome's
            // "loading" spinner keeps spinning until the PHP process ends. 
            \header('Content-Length: ' . \strlen($html));
        }

        print $html;

        // Flush the HTML so the user knows what's up as soon as possible.
        \flush();
    }

    public function generateHtml(/** @noinspection PhpUnusedParameterInspection */
        Throwable $e) {
        return '
<!DOCTYPE html>
<html>
	<head>
		<meta charset="UTF-8" />
		<title>An error has occurred. Please try again later.</title>
	</head>
	<body>
		<pre style="
			white-space: pre;
			font-family: \'DejaVu Sans Mono\', \'Consolas\', \'Menlo\', monospace;
			font-size: 10pt;
			color: #000000;
			display: block;
			background: white;
			border: none;
			margin: 0;
			padding: 0;
			line-height: 16px;
			width: 100%;
		">An error has occurred. Please try again later.</pre>
	</body>
</html>
';
    }
}

/**
 * Send errors and throwables to Bugsnag
 */
class BugsnagHandler extends ErrorHandler {
    private static $levels = array(
        // Real fatal errors
        \E_ERROR => 'error',
        \E_PARSE => 'error',
        \E_COMPILE_ERROR => 'error',
        \E_CORE_ERROR => 'error',

        // Warnings and pseudo-fatals
        \E_WARNING => 'warning',
        \E_CORE_WARNING => 'warning',
        \E_COMPILE_WARNING => 'warning',
        \E_USER_ERROR => 'warning', // not actually fatal
        \E_USER_WARNING => 'warning',
        \E_RECOVERABLE_ERROR => 'warning', // not actually fatal

        // Notices
        \E_NOTICE => 'info',
        \E_USER_NOTICE => 'info',
        \E_STRICT => 'info',
        \E_DEPRECATED => 'info',
        \E_USER_DEPRECATED => 'info',
    );

    private static function getContext() {
        if (\PHP_SAPI === 'cli' && isset($_SERVER['argv'])) {
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

    public function __construct($apiKey) {
        $config = new \Bugsnag_Configuration;
        $config->apiKey = $apiKey;
        $config->filters = array(); // Defaults to ['password'] which causes slowness in Bugsnag_Error::cleanupObj
        $config->context = self::getContext();
        $config->sendSession = false;

        // cURL by default sends an "Expect: 100-Continue" header and waits for a "100 Continue" response
        // before sending the body. `bugsnag-agent` for some reason takes a whole second to respond "100 Continue",
        // slowing everything down. So disable this behaviour by blanking out the "Expect" header.
        // We need to include "Content-Type: application/json" because that's what the Bugsnag library had
        // there by default.
        $config->curlOptions = array(
            \CURLOPT_HTTPHEADER => array(
                'Expect:',
                'Content-Type: application/json',
            ),
        );

        $this->config = $config;
        $this->notification = new \Bugsnag_Notification($config);
    }

    public function __destruct() {
        $this->flush();
    }

    public function setEndpoint($endpoint) {
        $this->config->endpoint = $endpoint;
    }

    public function setAppVersion($appVersion) {
        $this->config->appVersion = $appVersion;
    }

    public function setProjectRoot($root) {
        $this->config->setProjectRoot($root);
        // $this->config->setProjectRoot() will call setStripPath() automatically, but only for the first call.
        $this->config->setStripPath($root);
    }

    public function getProjectRoot() {
        return $this->config->projectRoot;
    }

    public function notifyError(ErrorException $e, array $metadata = array()) {
        if ($e->isFatal()) {
            \ini_set('memory_limit', '-1');
        }

        $config = clone $this->config;
        $config->sendCode = $e->isFatal(); // Only send code for fatal errors.

        $error = \Bugsnag_Error::fromPHPError(
            $config,
            new \Bugsnag_Diagnostics($config),
            $e->getSeverity(),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            true // Call it fatal so Bugsnag doesn't waste time generating its own stack trace
        );

        $error->setStacktrace(\Bugsnag_Stacktrace::fromBacktrace($config, $e->getTrace(), $e->getFile(), $e->getLine()));
        $error->setSeverity(self::$levels[$e->getSeverity()]);

        $this->notification->addError($error, $metadata);
    }

    public function notifyThrowable(\Throwable $e, $fatal, array $metadata = array()) {
        if ($fatal) {
            \ini_set('memory_limit', '-1');
        }

        $config = clone $this->config;
        $config->sendCode = true;

        $error = \Bugsnag_Error::fromPHPThrowable($config, new \Bugsnag_Diagnostics($config), self::unwrapThrowable($e));
        $error->setSeverity($fatal ? 'error' : 'warning');

        $this->notification->addError($error, $metadata);
    }

    public function flush() {
        $this->notification->deliver();
    }
}

/**
 * Same as the Bugsnag handler but generates Fail Whale dumps, saves them in an S3 bucket with a random name and
 * attaches a link to it to the Bugsnag error under the "Fail Whale" tab.
 */
class FailWhaleBugsnagHandler extends BugsnagHandler {
    private $s3Client;
    private $bucket;

    public function __construct($apiKey, \Aws\S3\S3Client $s3Client, $bucket) {
        parent::__construct($apiKey);
        $this->s3Client = $s3Client;
        $this->bucket = $bucket;
    }

    public function notifyError(ErrorException $e, array $metadata = array()) {
        if ($e->isFatal())
            $metadata = $this->addFailWhale(self::createThrowable($e), $metadata);

        parent::notifyError($e, $metadata);
    }

    public function notifyThrowable(Throwable $e, $fatal, array $metadata = array()) {
        $metadata = $this->addFailWhale($e, $metadata);

        parent::notifyThrowable($e, $fatal, $metadata);
    }

    private function addFailWhale(Throwable $e, array $metadata) {
        $dumpTime = null;
        $s3Time = null;

        try {
            $secs = \microtime(true);

            $html = _FailWhale::generate($e, $this->getProjectRoot());
            $dumpTime = $this->formatTime(\microtime(true) - $secs);

            $secs = \microtime(true);
            $link = $this->save($html);
            $s3Time = $this->formatTime(\microtime(true) - $secs);
        } catch (\Exception $e) {
            $link = 'error sending fail whale to s3: ' . $e->getMessage();
        }

        $metadata['Fail Whale'] = array(
            'link' => $link,
            'time' => array(
                'generate' => $dumpTime,
                'send to s3' => $s3Time,
            ),
        );

        return $metadata;
    }

    private function formatTime($t) {
        $s = \sprintf('%02d:%02d:%05.3f', $t / 3600, $t / 60 % 60, \fmod($t, 60));
        $s = \ltrim($s, '0:');
        return $s;
    }

    private function randomString($length) {
        $ret = '';
        while (\strlen($ret) < $length) {
            $ret .= \str_pad(\mt_rand(0, 999999999), 9, '0', \STR_PAD_LEFT);
        }
        return \substr($ret, 0, $length);
    }

    private function save($html) {
        $filename = $this->randomString(20) . '.html';

        $this->s3Client->putObject(array(
            'Bucket' => $this->bucket,
            'Key' => $filename,
            'Body' => $html,
            // not sure if this is needed or if S3 will figure it out by itself
            'ContentType' => 'text/html; charset=UTF-8',
            // since we have no control over what data we are storing here, we should probably tell S3 to encrypt it
            'ServerSideEncryption' => 'AES256',
        ));

        return $this->s3Client->getObjectUrl($this->bucket, $filename, '+1 month');
    }
}

/**
 * Shows a Fail Whale dump for the error on screen, and also saves it to /tmp/latest-fail-whale.html if possible.
 */
class FailWhaleErrorPageHandler extends ErrorPageHandler {
    private $projectRoot;

    public function __construct($projectRoot) {
        $this->projectRoot = $projectRoot;
    }

    public function generateHtml(Throwable $e) {
        $dump = _FailWhale::generate($e, $this->projectRoot);

        $this->trySaveLocal($dump);

        return $dump;
    }

    private function trySaveLocal($html) {
        try {
            $file = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'latest-fail-whale.html';
            \file_put_contents($file, $html);
            if ((\fileperms($file) & 0666) !== 0666)
                \chmod($file, 0666);
        } catch (\Exception $e) {
            // best effort
        }
    }
}

/** @internal */
final class _FailWhale {
    public static function generate(\Throwable $e, $projectRoot) {
        $e = ErrorHandler::unwrapThrowable($e);

        $settings = new \FailWhale\IntrospectionSettings;

        $settings->maxArrayEntries = 100;
        $settings->maxStringLength = 10000;
        $settings->fileNamePrefix = \rtrim($projectRoot, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR;

        $html = \FailWhale\Value::introspectException($e, $settings)->toHTML();

        return $html;
    }

    private function __construct() {
    }
}

