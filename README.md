# omnifilm/tmdb

[omnisense/omnifilm](https://github.com/glitchr-omnisense/omnifilm)'s The Movie Database, under any PHP
host, over [php-tmdb/api](https://github.com/php-tmdb/api):

- **search** of films, series or both (`/search/movie`, `/search/tv`, `/search/multi`);
- **a film's or a series' record** with its credits and cross identifiers in one call
  (`append_to_response=credits,external_ids`), by its TMDB number or, through `/find`, by its
  IMDb, TheTVDB or Wikidata identifier; **an episode** by its IMDb or TheTVDB number;
- **a season's episodes**; **where to watch** in a country (TMDB's watch providers).

```php
use Omnifilm\Tmdb\TmdbFactory;

$tmdb = (new TmdbFactory($httpClient))->create(['token' => getenv('TMDB_TOKEN'), 'language' => 'fr-FR']);
$matrix = $tmdb->work(WorkRef::imdb('tt0133093'));
$offers = $tmdb->offers(WorkRef::tmdb(603), 'FR');
```

Options: `token` (the API Read Access Token, sent as a Bearer header) or `api_key` (the v3 key,
in the query); `language` ("en-US"); `images` (the image server), `poster` ("w500"),
`backdrop` ("w1280"), `still` ("w300"), `logo` ("w92"); `cast` (20).

## Why php-tmdb/api, and how it is wired

Plan 0 settled that `php-tmdb/api` serves inside omnifilm/tmdb. Found on 2026-10-24: MIT, kept up
on GitHub (its 4.1 branch accepts Symfony 8 since 2026-05), its last release 4.1.0 (2022-06), which
installs with PHP 8.4 and Symfony 8. Only its raw API calls are used (its arrays, not its models):
the record is omnifilm's. It needs a PSR-18 client, PSR-17 factories and a PSR-14 dispatcher with
five listeners: the factory gives it the application's client through Symfony's `Psr18Client`,
nyholm/psr7's factories, and `EventDispatcher`, this package's own dispatcher (no
symfony/event-dispatcher). php-tmdb requires php-http/discovery, a Composer plugin it does not need
here (every factory is passed): `allow-plugins: {"php-http/discovery": false}` is enough.

## TMDB's terms, as read on 2026-10-24

From the [API terms of use](https://www.themoviedb.org/api-terms-of-use):

- show, prominently, that the application "uses TMDB and the TMDB APIs but is not endorsed,
  certified, or otherwise approved by TMDB", with TMDB's logo, less prominent than your own;
- no commercial use without a written agreement with TMDB (fees, advertising revenue count as
  commercial); no AI training on TMDB content;
- cache nothing "for longer than 6 months"; purge when the licence ends; no excessive bandwidth;
  TMDB is no image host for banners or graphics.

Where to watch is JustWatch's data: TMDB asks that JustWatch be credited where it is shown.

## Verified, and not

- Verified (the core's harness, PHP 8.4): search, films, series, episodes, seasons, offers, a 404
  and a 401 through php-tmdb/api, with the Bearer token or the key; `BareTest`; PHPStan max.
  The answers are **shaped after TMDB's API reference and written by hand**: no TMDB account was
  used.
- Not verified live: no key. The first site with one runs a search and a lookup once.

## Responsibility

An independent, unofficial client, affiliated with, endorsed by or sponsored by none of the services
it reaches. The MIT licence covers this code only and grants no right to the service or its data:
whoever runs it complies with the terms above and obtains what they require. The authors publish
it free of charge, make no commercial use of it and accept no liability for its use.

## Licence

MIT.
