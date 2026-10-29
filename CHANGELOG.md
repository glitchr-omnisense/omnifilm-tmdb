# Changelog

## Unreleased (1.x)

### Fixed
- With the `api_key` option, the key no longer appears in an `UnreachableException`'s message: it
  names the method and the path (`TMDB could not be reached for GET /3/movie/603: ...`), then the
  PSR-18 client's reason without the URL. Needs omnisense/omnifilm's `JsonClient::reason()`.
