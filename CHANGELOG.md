# Changelog

## [Unreleased]

### Fixed

- No key in what `HttpSource` throws. An unreachable service's `UnavailableException` quoted the
  HTTP client's message, the whole URL with a key in its query (OpenAlex's `api_key=`), and wrapped
  the client's own exception, and every exception `get()` and `getJson()` threw - unreachable, 429,
  5xx, 4xx, not JSON - kept in its trace the query and headers with the key
  (`zend.exception_ignore_args` off). The message and the previous - now a copy of the client's
  exception, the same class and code - have it masked, and none of them keeps its trace's arguments.

### Added

- `Omnischolar\Http\Redaction`: a request's secrets (`secrets()`), a text without them (`text()`), a
  failure's copy to wrap in its place (`copy()`), a trace without arguments (`withoutArguments()`).
- `Config::bool($key, $default)`: an option read as a boolean whatever its spelling (`'false'`,
  `'0'`, `'no'`, `'off'` false; `'true'`, `'1'`, `'yes'`, `'on'` true), a value that is no boolean
  an `InvalidConfigException`. PHP's `(bool)`, which omnischolar/openalex's `paratext` and
  omnischolar/openlibrary's `editions` were read with, took the string `'false'` for true.
