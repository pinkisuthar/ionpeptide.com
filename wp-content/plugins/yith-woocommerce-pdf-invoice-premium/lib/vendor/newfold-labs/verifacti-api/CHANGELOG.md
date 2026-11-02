# Changelog

All notable changes to this project will be documented in this file.

## [0.2.0] - 2026-07-16

### Changed

- **BREAKING:** Minimum PHP version raised from 7.4 to 8.0.
- Modernized codebase with PHP 8.0 features: constructor property promotion, native `mixed` type, `match` expressions, `str_contains` / `str_starts_with`, and short array syntax.
- Added complete PHPDoc coverage across all public API surfaces.

### Security

- Explicit TLS verification (`CURLOPT_SSL_VERIFYPEER`, `CURLOPT_SSL_VERIFYHOST`) in cURL transport.
- Response body truncation in exception messages to reduce sensitive data exposure in logs.
- Query parameter validation in `RecordStatusLookupRequest`.
