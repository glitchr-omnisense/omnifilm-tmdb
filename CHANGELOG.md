# Changelog

## Unreleased (1.x)

### Fixed
- `capabilities()->attribution` is the wording TMDB's API terms ask for: "This product uses TMDB
  and the TMDB APIs but is not endorsed, certified, or otherwise approved by TMDB.", where it read
  "uses the TMDB API but is not endorsed or certified by TMDB".
- With the `api_key` option, the key no longer appears in an `UnreachableException`'s message: it
  names the method and the path (`TMDB could not be reached for GET /3/movie/603: ...`), then the
  PSR-18 client's reason without the URL. Needs omnisense/omnifilm's `JsonClient::reason()`.
