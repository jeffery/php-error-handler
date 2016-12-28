PHP error handler abstracting the difference between PHP5 and PHP7 error handling.

## Implementing `Throwable` in PHP5

In PHP7, the class hierarchy is

- `Throwable`
  - `Exception`
  	- `...`
  - `Error`
  	- `ArithmeticError`
  	  - `DivisionByZeroError`
  	- `AssertionError`
  	- `ParseError`
  	- `TypeError`

In PHP5, the class hierarchy is

- `Exception`
  - `...`

- `Throwable`
  - `Error`
  	- `ArithmeticError`
    	- `DivisionByZeroError`
  	- `AssertionError`
  	- `ParseError`
  	- `TypeError`

The difference is that `Exception` does not extend `Throwable`, since `Exception` is defined by the runtime and cannot be made to implement an interface by user code.

In order to pass an `Exception` to code accepting a `Throwable`, two functions are provided:

- `throwable_create(Throwable|Exception $e): Throwable`
- `throwable_unwrap(Throwable $e): Throwable|Exception`

Before checking if a `Throwable` is an instance of a particular `Exception`, or extracting the class name with `get_class()`, it is necessary to call `throwable_unwrap()`.

Since `Exception` extends `Throwable` in PHP7, the type `Throwable|Exception` is equivelant to `Throwable` and these functions do nothing.

The only other difference is that instances of `Throwable` are only `throw`able in PHP7. PHP5's `throw` statement will only accept instances of `Exception`, and `Error` does not extend `Exception` in either PHP5 or PHP7. This cannot be fixed by a polyfill, but `Error` is only intended to be thrown by the runtime, not user code, so this should not be a concern in practice.
