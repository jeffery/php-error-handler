PHP error and uncaught `Throwable` handler abstracting event binding from event handling and supporting PHP5 >= 5.3 and PHP7.

## Important classes

```php
namespace IVT\ErrorHandler;
```

### `ErrorHandler`

`ErrorHandler` is some means of handling PHP errors (fatal and non-fatal) and uncaught `Throwable`s. It defines three methods implementable by sub classes:

- `notifyError(ErrorException $e): void`

  Handle a PHP error. `$e->isFatal()` can be used to check if the error was fatal.

- `notifyThrowable(Throwable $e, bool $fatal): void`

  Handle an uncaught `Throwable`. A `Throwable` can be given directly to an error handler to log it without throwing by calling `->notifyThrowable($e, false)`.

- `flush(): void`

  Since destructors aren't called in the case of a fatal error, this method can be overriden in addition to `__destruct()` to flush any necessary buffers in the case of a fatal error.

Any `ErrorHandler` can be bound to PHP's error handling events with `$handler->bind()`. `set_error_handler()`, `set_exception_handler()` and `register_shutdown_function()` will be called appropriately. `->bind()` can be safely called multiple times.

For example, to send all your problems to Bugsnag:

```php
(new BugsnagHandler('api key'))->bind();
```

### `ErrorException`

The `ErrorException` class extends `\ErrorException` with additional methods like `isFatal()`, `isUserError()`, `isType(int $type)`, `isReportable()`, `::getLast()`, `getContext()` etc. It will be given to `ErrorHandler::notifyError()` in response to a PHP error.

### `ThrowErrorExceptionsHandler`

Throws non-fatal `ErrorException`s so they go through `catch`/`finally` blocks and hit `ErrorHandler::notifyThrowable()` instead of `ErrorHandler::notifyError()`.

### `ThrowReportableErrorExceptionsHandler`

Same as `ThrowErrorExceptionsHandler` but only throws if the error is covered by `error_reporting` (`$error->isReportable()`), otherwise lets it through to `notifyError()`.

### `AggregateErrorHandler`

Group multiple error handlers into one. The list of handlers is passed into the constructor and can be appended/prepended after construction with `append()`/`prepend()`.

```php
$handler = new AggregateErrorHandler();
$handler->bind();
$handler->append(...);
$handler->append(...);
```

### `ErrorPageHandler`

Display a HTML page in the case of a fatal error or uncaught `Throwable`. The page can be customized by overriding `generateHtml(Throwable $e): string`.

### `FailWhaleErrorPageHandler`

Displays a [Fail Whale](https://github.com/jesseschalken/fail-whale) dump for fatal errors or uncaught `Throwable`s.

### `...` (see the code)

## `Throwable` in PHP5

A polyfill for `Throwable` is included, however on PHP5 `Exception` does not extend it since `Exception` is defined by the runtime and cannot be made to extend an interface by user code.

In PHP7, the class hierarchy is

- `Throwable`
  - `Exception`
  	- `...`
  - `...`

In PHP5, the class hierarchy is

- `Exception`
  - `...`
- `Throwable`
  - `...`

Two static methods are provided to convert between `Throwable` and `Throwable|Exception` for compatibility with PHP5. On PHP7 these types are equivelant (`Exception` extends `Throwable`) and so these methods do nothing.

- `ErrorHandler::createThrowable(Throwable|Exception $e): Throwable`

  Make a `Throwable` from an `Exception`. Since the resulting object is of a different type, you must unwrap it with `::unwrapThrowable()` before doing any RTTI such as getting the class name with `get_class()` or testing the type with `instanceof`.

- `ErrorHandler::unwrapThrowable(Throwable $e): Throwable|Exception`

  Unwrap an `Exception` that might have been wrapped by `::createThrowabe()`.
