# omnifilm/tmdb

The Movie Database for [omnisense/omnifilm](https://github.com/glitchr-omnisense/omnifilm): search,
a film's or a series' record with its credits and cross identifiers, an episode, a season's
episodes, and where to watch in a country. It runs over
[php-tmdb/api](https://github.com/php-tmdb/api) (its raw API calls, not its models) on TMDB's API v3,
`https://api.themoviedb.org/3`:

| Operation | TMDB API |
|---|---|
| search | `GET /search/movie`, `/search/tv`, `/search/multi` (no type) |
| a film, a series | `GET /movie/{id}`, `/tv/{id}` with `append_to_response=credits,external_ids` |
| by another register | `GET /find/{id}?external_source=imdb_id\|tvdb_id\|wikidata_id`, then the record |
| an episode | `GET /find/{id}` (`tv_episode_results`), then `GET /tv/{id}/season/{n}/episode/{m}` |
| a season | `GET /tv/{id}/season/{n}` |
| where to watch | `GET /movie/{id}/watch/providers`, `/tv/{id}/watch/providers` |

TMDB has no sandbox. Pictures are served from `https://image.tmdb.org/t/p/{size}/...`.

## Installation

```sh
composer require omnifilm/tmdb
```

Requires PHP 8.2, `omnisense/omnifilm` (1.x), `php-tmdb/api` (^4.1), `nyholm/psr7` (^1.8),
`psr/event-dispatcher` (^1.0) and `symfony/http-client` (6.4, 7 or 8). php-tmdb/api requires
`php-http/discovery`, a Composer plugin it does not need here (every factory is passed): leaving
it disabled is enough -

```json
"config": { "allow-plugins": { "php-http/discovery": false } }
```

How it is wired: the factory gives php-tmdb's `Client` the application's HTTP client through
Symfony's `Psr18Client`, nyholm/psr7's factories, and `Omnifilm\Tmdb\EventDispatcher` - a PSR-14
dispatcher of this package's own (listeners by the event's own class, in the order added, until one
stops the event), which keeps `symfony/event-dispatcher` out. Five php-tmdb listeners are added: the
request listener, the token, `Accept: application/json`, `Content-Type: application/json` and
php-tmdb's User-Agent.

## Credentials

A TMDB account's API settings give two credentials; either does:

- the **API Read Access Token** (`token`), sent as `Authorization: Bearer` - preferred;
- the **v3 API key** (`api_key`), sent in the query string as `api_key=`.

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `token` | string | `null` | one of the two | the read access token; used when both are given |
| `api_key` | string | `null` | one of the two | the v3 key |
| `language` | string | `en-US` | no | titles, overviews and genres when a call names none: `fr-FR`, `fr` |
| `images` | string | `https://image.tmdb.org/t/p/` | no | the image server |
| `poster` | string | `w500` | no | posters' size |
| `backdrop` | string | `w1280` | no | backdrops' size |
| `still` | string | `w300` | no | episodes' stills' size |
| `logo` | string | `w92` | no | services' logos' size (where to watch) |
| `cast` | int | `20` | no | the cast members kept, in billing order |

## Capabilities

| Contract | |
|---|---|
| `SearchInterface` | films, series, both |
| `LookupInterface` | film: tmdb, imdb, wikidata; series: tmdb, imdb, tvdb, wikidata; episode: imdb, tvdb |
| `EpisodesInterface` | yes |
| `AvailabilityInterface` | yes (JustWatch's data) |
| images, credits | yes; a key is needed |

`attribution`: `This product uses the TMDB API but is not endorsed or certified by TMDB. Where to
watch: data from JustWatch.`

> [!WARNING]
> Known defect: the `attribution` string is not the wording TMDB's terms ask for, which the
> package's README quotes: "uses TMDB and the TMDB APIs but is not endorsed, certified, or
> otherwise approved by TMDB", with TMDB's logo, less prominent than the application's own. Show
> the terms' wording.

## Behaviour

**Search.** The language (the query's, else the option), `page=1`, `include_adult=false`; with a
type, the year is sent (`primary_release_year` for films, `first_air_date_year` for series); with
none (`/search/multi`), the year is compared with each answer's and people are dropped. TMDB's
first page only: 20 answers at most, whatever the limit. A search answer carries the TMDB number,
titles, date, overview, poster, backdrop and TMDB rating; no IMDb number, no genres, no cast.

**A record.**

| Field | Read from |
|---|---|
| `title`, `originalTitle` | `title` / `original_title` (a series: `name` / `original_name`) |
| `released`, `year` | `release_date` (a series: `first_air_date`) |
| `runtime` | `runtime`, else the first `episode_run_time` |
| `overview`, `genres` | `overview`, `genres[].name` - in the language asked |
| `countries` | a film: `production_countries[].iso_3166_1`; a series: `origin_country` |
| `languages` | `spoken_languages[].iso_639_1`, else `original_language` |
| `identifiers` | the TMDB number, `imdb_id`, and `external_ids` (IMDb, TheTVDB, Wikidata) |
| `images` | `poster_path` (`Poster`, at `poster`), `backdrop_path` (`Backdrop`, at `backdrop`) |
| `credits` | crew: `Director` (job), the `Writing` department, `Producer` (job), `Original Music Composer` / `Music` / `Composer` (jobs), each with its job and TMDB number; then the cast, `cast` members at most, with character and order |
| `ratings` | `tmdb`, `vote_average` out of 10 with `vote_count`, when there are votes |
| `url` | `https://www.themoviedb.org/movie/{id}` or `/tv/{id}` |
| `season` | a series' `number_of_seasons` |

An episode: its name (else `Episode <n>`), `air_date`, runtime, overview, still (at `still`), its
credits, TMDB rating, `series` the show's TMDB number, its season and number, its page
`https://www.themoviedb.org/tv/{show}/season/{n}/episode/{m}`.

**A season.** One call for a series named by its TMDB number; another register is looked up first
(`/find` and the record). Episodes come in TMDB's order, each with its TMDB number only (a season's
answer lists no external identifiers).

**Where to watch.** TMDB answers every country; the one asked is kept. `flatrate` -> `Stream`,
`free` -> `Free`, `ads` -> `Ads`, `rent` -> `Rent`, `buy` -> `Buy`; each offer's `url` is TMDB's
watch page for that country (`link`), its `logo` the service's at `logo` size. A ref that is not a
TMDB number is looked up first; an episode gives `[]`.

**Not found.** A 404 - or TMDB's status code 34 - is `null` (or `[]`).

## Example

A film's record and where to watch it in France:

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnifilm\Model\WorkRef;
use Omnifilm\Tmdb\TmdbFactory;
use Symfony\Component\HttpClient\HttpClient;

$tmdb = (new TmdbFactory(HttpClient::create()))->create(['token' => getenv('TMDB_TOKEN'), 'language' => 'fr-FR']);

$matrix = $tmdb->work(WorkRef::tmdb(603));
echo $matrix->title, ' / ', $matrix->originalTitle, ', ', $matrix->released?->format('Y-m-d'), ', ', $matrix->runtime, " min\n";
echo json_encode($matrix->identifiers->toArray()), "\n";
foreach ($matrix->credits as $c) {
    echo '  ', $c->role->value, ': ', $c->name, $c->character ? ' as '.$c->character : '', $c->job ? ' ('.$c->job.')' : '', "\n";
}
foreach ($tmdb->offers(WorkRef::tmdb(603), 'FR') as $o) {
    echo '  ', $o->kind->value, ' ', $o->service, ' ', $o->url, "\n";
}
```

Run in the core's harness on 2026-10-26 with `HttpClient::create()` replaced by a `MockHttpClient`
answering the package's test answers (written after TMDB's reference), it printed:

```
The Matrix / The Matrix, 1999-03-31, 136 min
{"imdb":"tt0133093","tmdb":"603","wikidata":"Q83495"}
  director: Lana Wachowski (Director)
  director: Lilly Wachowski (Director)
  writer: Lana Wachowski (Writer)
  producer: Joel Silver (Producer)
  composer: Don Davis (Original Music Composer)
  cast: Keanu Reeves as Thomas A. Anderson / Neo
  cast: Laurence Fishburne as Morpheus
  cast: Carrie-Anne Moss as Trinity
  stream Example Stream https://www.themoviedb.org/movie/603-the-matrix/watch?locale=FR
  rent Example Video https://www.themoviedb.org/movie/603-the-matrix/watch?locale=FR
  buy Example Video https://www.themoviedb.org/movie/603-the-matrix/watch?locale=FR
```

The calls made:

```
GET https://api.themoviedb.org/3/movie/603?append_to_response=credits%2Cexternal_ids&language=fr-FR
GET https://api.themoviedb.org/3/movie/603/watch/providers
```

The values are the test answers', not TMDB's: no TMDB account was used. In Symfony:

```yaml
services:
    Omnifilm\Tmdb\TmdbFactory:
        arguments: ['@http_client']
    Omnifilm\Registry:
        arguments:
            $factories: ['@Omnifilm\Tmdb\TmdbFactory']
            $config:
                tmdb: { factory: tmdb, options: { token: '%env(TMDB_TOKEN)%', language: fr-FR } }
```

## Errors

- a status of 400 or more but 404: `ProviderException` naming the path and TMDB's message (`TMDB
  answered 401 for GET /3/movie/603. ...`);
- a network error: `UnreachableException` naming the method and the path, then the HTTP client's
  reason without the URL (`TMDB could not be reached for GET /3/movie/603: Could not resolve host:
  api.themoviedb.org.`): the `api_key` stays out of it;
- neither `token` nor `api_key`: `InvalidConfigException` when the provider is built;
- an episode by its TMDB number, a film by its TheTVDB or Trakt number: `NotSupportedException`
  before any call.

The exception's previous, the PSR-18 client's, still quotes the URL; `token`, which travels in a
header, keeps the key out of URLs altogether.

## Verified, and not

Tested offline (`Tests/TmdbProviderTest.php`, in the core's harness, PHP 8.4), through php-tmdb/api
on a `MockHttpClient`: a search of films with the Bearer token (the year and the language in the
URL, no `api_key`), a search of every type keeping films and series, a film with its credits and
cross identifiers in one call, a series by its TheTVDB number through `/find`, an episode by its
IMDb number and a season, where to watch in three countries, not found as `null` and a 401 as an
error, the token or the key required. The answers are shaped after TMDB's API reference and
written by hand. `Tests/BareTest.php` loads every class with the package's requirements alone.

Not verified: no TMDB key was used; nothing was called on TMDB.

## Responsibility

An independent, unofficial client, affiliated with TMDB in no way. Whoever runs it complies with
TMDB's [API terms of use](https://www.themoviedb.org/api-terms-of-use) - the attribution and logo,
no commercial use without an agreement, no cache older than six months - and credits JustWatch
where offers are shown: see the README.
