# Changelog

## [Unreleased]

### Added

- `Config::bool($key, $default)`: an option read as a boolean whatever its spelling (`'false'`,
  `'0'`, `'no'`, `'off'` false; `'true'`, `'1'`, `'yes'`, `'on'` true), a value that is no boolean
  an `InvalidConfigException`. PHP's `(bool)`, which omnischolar/openalex's `paratext` and
  omnischolar/openlibrary's `editions` were read with, took the string `'false'` for true.
