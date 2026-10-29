<?php

namespace Omnifilm\Tmdb\Tests;

use Omnifilm\Exception\InvalidConfigException;
use Omnifilm\Exception\ProviderException;
use Omnifilm\Model\Credit;
use Omnifilm\Model\ImageKind;
use Omnifilm\Model\Offer;
use Omnifilm\Model\OfferKind;
use Omnifilm\Model\Query;
use Omnifilm\Model\Role;
use Omnifilm\Model\Work;
use Omnifilm\Model\WorkRef;
use Omnifilm\Model\WorkType;
use Omnifilm\Tmdb\TmdbFactory;
use Omnifilm\Tmdb\TmdbProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * TMDB's answers shaped after its API reference (developer.themoviedb.org),
 * values written by hand: no account was used, nothing here was captured
 * from TMDB itself. What is checked is the way through php-tmdb/api - the
 * requests it makes, how its arrays become omnifilm's records.
 */
final class TmdbProviderTest extends TestCase
{
    private const TOKEN = 'not-a-real-tmdb-read-access-token';

    /** @var list<array{url: string, headers: list<string>}> */
    private static array $requests = [];

    public function testASearchOfFilmsThroughPhpTmdbWithTheBearerToken(): void
    {
        $http = self::http();
        $works = self::provider($http)->search(new Query('matrix', WorkType::Movie, 1999, 'fr-FR'));

        self::assertSame(['Matrix', 'The Matrix Recalibrated'], array_map(static fn (Work $w) => $w->title, $works));
        self::assertSame(['The Matrix', 1999, '1999-03-31'], [$works[0]->originalTitle, $works[0]->year, $works[0]->released?->format('Y-m-d')]);
        self::assertSame('https://image.tmdb.org/t/p/w500/poster-603.jpg', $works[0]->image()?->url);
        self::assertSame('https://image.tmdb.org/t/p/w1280/backdrop-603.jpg', $works[0]->image(ImageKind::Backdrop)?->url);
        self::assertSame(8.2, $works[0]->ratings[0]->value ?? null);
        self::assertSame([], $works[1]->ratings, 'no votes, no rating');
        self::assertNull($works[1]->year);
        self::assertStringStartsWith('https://api.themoviedb.org/3/search/movie?', self::$requests[0]['url']);
        self::assertStringContainsString('primary_release_year=1999', self::$requests[0]['url']);
        self::assertStringContainsString('language=fr-FR', self::$requests[0]['url']);
        self::assertStringNotContainsString('api_key', self::$requests[0]['url']);
        self::assertSame('Bearer '.self::TOKEN, self::header('Authorization'));
    }

    public function testASearchOfEveryTypeKeepsFilmsAndSeries(): void
    {
        $works = self::provider(self::http())->search(new Query('twin peaks'));

        self::assertSame([['Twin Peaks', 'series', 1990], ['Twin Peaks: Fire Walk with Me', 'movie', 1992]], array_map(static fn (Work $w) => [$w->title, $w->type->value, $w->year], $works));
    }

    public function testAFilmWithItsCreditsAndCrossIdentifiersInOneCall(): void
    {
        $http = self::http();
        $work = self::provider($http)->work(WorkRef::tmdb(603));

        self::assertNotNull($work);
        self::assertSame(['tmdb' => '603', 'imdb' => 'tt0133093', 'wikidata' => 'Q83495'], ['tmdb' => $work->identifiers->get('tmdb'), 'imdb' => $work->identifiers->get('imdb'), 'wikidata' => $work->identifiers->get('wikidata')]);
        self::assertSame([136, ['Action', 'Science Fiction'], ['US', 'AU'], ['en']], [$work->runtime, $work->genres, $work->countries, $work->languages]);
        self::assertSame(['Lana Wachowski', 'Lilly Wachowski'], array_map(static fn (Credit $c) => $c->name, $work->credits(Role::Director)));
        self::assertSame(['Lana Wachowski'], array_map(static fn (Credit $c) => $c->name, $work->credits(Role::Writer)));
        self::assertSame(['Don Davis'], array_map(static fn (Credit $c) => $c->name, $work->credits(Role::Composer)));
        self::assertSame(['Thomas A. Anderson / Neo', 0, '6384'], [$work->credits(Role::Cast)[0]->character, $work->credits(Role::Cast)[0]->order, $work->credits(Role::Cast)[0]->identifiers->get('tmdb')]);
        self::assertCount(2, self::provider(self::http(), ['cast' => 2])->work(WorkRef::tmdb(603))?->credits(Role::Cast) ?? []);
        self::assertSame('https://www.themoviedb.org/movie/603', $work->url);
        self::assertStringContainsString('append_to_response=credits%2Cexternal_ids', self::$requests[0]['url']);
    }

    public function testASeriesByItsTvdbNumberThroughFind(): void
    {
        $http = self::http();
        $work = self::provider($http, ['api_key' => 'not-a-real-tmdb-key', 'token' => null])->work(WorkRef::tvdb(70533));

        self::assertNotNull($work);
        self::assertSame(['Twin Peaks', WorkType::Series, 47, 3, '70533', ['US']], [$work->title, $work->type, $work->runtime, $work->season, $work->identifiers->get('tvdb'), $work->countries]);
        self::assertStringContainsString('/find/70533?', self::$requests[0]['url']);
        self::assertStringContainsString('external_source=tvdb_id', self::$requests[0]['url']);
        self::assertStringContainsString('api_key=not-a-real-tmdb-key', self::$requests[0]['url']);
        self::assertNull(self::header('Authorization'));
    }

    public function testAnEpisodeByItsImdbNumberAndASeasonsEpisodes(): void
    {
        $provider = self::provider(self::http());
        $episode = $provider->work(WorkRef::imdb('tt0000001', WorkType::Episode));

        self::assertNotNull($episode);
        self::assertSame(['Pilot', 1, 1, 94, '1920', '100001'], [$episode->title, $episode->season, $episode->number, $episode->runtime, $episode->series?->get('tmdb'), $episode->identifiers->get('tvdb')]);
        self::assertSame(['David Lynch'], array_map(static fn (Credit $c) => $c->name, $episode->credits(Role::Director)));
        self::assertSame('https://image.tmdb.org/t/p/w300/still-90001.jpg', $episode->image(ImageKind::Still)?->url);

        $episodes = $provider->episodes(WorkRef::tmdb(1920, WorkType::Series), 1);
        self::assertSame([[1, 'Pilot', '1990-04-08'], [2, 'Traces to Nowhere', '1990-04-12']], array_map(static fn (Work $w) => [$w->number, $w->title, $w->released?->format('Y-m-d')], $episodes));
        self::assertSame('https://www.themoviedb.org/tv/1920/season/1/episode/2', $episodes[1]->url);
        self::assertSame([], $provider->episodes(WorkRef::tmdb(1920, WorkType::Series), 9), 'a season TMDB does not have');
    }

    public function testWhereToWatchInACountry(): void
    {
        $provider = self::provider(self::http());

        self::assertSame([['stream', 'Example Stream'], ['rent', 'Example Video'], ['buy', 'Example Video']], array_map(static fn (Offer $o) => [$o->kind->value, $o->service], $provider->offers(WorkRef::tmdb(603), 'fr')));
        $us = $provider->offers(WorkRef::imdb('tt0133093'), 'US');
        self::assertCount(1, $us);
        self::assertSame([OfferKind::Free, 'https://www.themoviedb.org/movie/603-the-matrix/watch?locale=US', null], [$us[0]->kind, $us[0]->url, $us[0]->logo]);
        self::assertSame('https://image.tmdb.org/t/p/w92/logo-a.jpg', $provider->offers(WorkRef::tmdb(603), 'FR')[0]->logo ?? null);
        self::assertSame([], $provider->offers(WorkRef::tmdb(603), 'JP'));
    }

    public function testNotFoundIsNullAndARefusalAnError(): void
    {
        self::assertNull(self::provider(self::http())->work(WorkRef::tmdb(999999)));

        $http = self::http(['~~' => [401, '{"status_code": 7, "status_message": "Invalid API key: You must be granted a valid key.", "success": false}']]);
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('TMDB answered 401');
        self::provider($http)->work(WorkRef::tmdb(603));
    }

    /** With the api_key option the key travels in the query: an unreachable TMDB names the path, never the key. */
    public function testTheKeyStaysOutOfAnUnreachableMessage(): void
    {
        $http = new MockHttpClient(static function (string $method, string $url): never {
            throw new \Symfony\Component\HttpClient\Exception\TransportException(\sprintf('Could not resolve host: api.themoviedb.org for "%s".', $url));
        });

        try {
            self::provider($http, ['api_key' => 'not-a-real-tmdb-key', 'token' => null])->work(WorkRef::tmdb(603));
            self::fail('no exception');
        } catch (\Omnifilm\Exception\UnreachableException $e) {
            self::assertStringNotContainsString('not-a-real-tmdb-key', $e->getMessage());
            self::assertStringNotContainsString('api_key', $e->getMessage());
            self::assertStringContainsString('GET /3/movie/603', $e->getMessage());
        }
    }

    /**
     * Nothing of the key or the token in a log of the whole chain: the messages, the previous
     * exceptions (copies of the PSR-18 client's and Symfony's), the traces' arguments - which
     * held the request, headers and query - when zend.exception_ignore_args is off.
     */
    public function testNoPartOfAnUnreachableChainHoldsTheKeyOrTheToken(): void
    {
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        $http = new MockHttpClient(static function (string $method, string $url): never {
            throw new \Symfony\Component\HttpClient\Exception\TransportException(\sprintf('Could not resolve host: api.themoviedb.org for "%s".', $url));
        });

        try {
            foreach ([['api_key' => 'not-a-real-tmdb-key', 'token' => null], ['token' => 'not-a-real-tmdb-token']] as $options) {
                try {
                    self::provider($http, $options)->work(WorkRef::tmdb(603));
                    self::fail('no exception');
                } catch (\Omnifilm\Exception\UnreachableException $e) {
                    self::assertNotNull($e->getPrevious());
                    for ($one = $e; null !== $one; $one = $one->getPrevious()) {
                        foreach (['not-a-real-tmdb-key', 'not-a-real-tmdb-token'] as $secret) {
                            self::assertStringNotContainsString($secret, $one->getMessage());
                            self::assertStringNotContainsString($secret, print_r($one->getTrace(), true));
                        }
                    }
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', false !== $ignoreArgs ? $ignoreArgs : '1');
        }
    }

    /**
     * TMDB refusing the key or the token: the ProviderException keeps no argument in its trace (the
     * closure there held the provider, its client and its token), so a log of it
     * (zend.exception_ignore_args off) holds neither.
     */
    public function testARefusalKeepsNoCredentialInItsTrace(): void
    {
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse('{"status_code": 7, "status_message": "Invalid API key: You must be granted a valid key.", "success": false}', ['http_code' => 401, 'response_headers' => ['content-type' => 'application/json']]));
        try {
            foreach ([['api_key' => 'not-a-real-tmdb-key', 'token' => null], ['token' => 'not-a-real-tmdb-token']] as $options) {
                try {
                    self::provider($http, $options)->work(WorkRef::tmdb(603));
                    self::fail('no exception');
                } catch (\Omnifilm\Exception\ProviderException $e) {
                    foreach (['not-a-real-tmdb-key', 'not-a-real-tmdb-token'] as $secret) {
                        self::assertFalse(str_contains($e->getMessage(), $secret), $e->getMessage());
                        self::assertFalse(str_contains(print_r($e->getTrace(), true), $secret), 'the trace holds '.$secret);
                    }
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', false !== $ignoreArgs ? $ignoreArgs : '1');
        }
    }

    /** The attribution is the wording TMDB's API terms ask for (https://www.themoviedb.org/api-terms-of-use, the README quotes it). */
    public function testTheAttributionIsTheTermsWording(): void
    {
        self::assertStringStartsWith('This product uses TMDB and the TMDB APIs but is not endorsed, certified, or otherwise approved by TMDB.', (string) self::provider(self::http())->capabilities()->attribution);
    }

    public function testATokenOrAKeyIsRequired(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('token (the API Read Access Token) or api_key');
        (new TmdbFactory(self::http()))->create();
    }

    /**
     * Symfony's MockHttpClient (Psr18Client reads the body as a stream),
     * answering by route as omnifilm's RecordedHttpClient does, the
     * requests kept in $requests.
     *
     * @param ?array<string, array{0: int, 1: string}> $routes
     */
    private static function http(?array $routes = null): MockHttpClient
    {
        self::$requests = [];

        return new MockHttpClient(static function (string $method, string $url, array $options) use ($routes): MockResponse {
            $headers = $options['headers'] ?? [];
            self::$requests[] = ['url' => $url, 'headers' => array_values(array_filter(\is_array($headers) ? $headers : [], 'is_string'))];
            foreach ($routes ?? self::routes() as $pattern => [$status, $body]) {
                if (preg_match($pattern, $method.' '.$url)) {
                    return new MockResponse(str_starts_with($body, '@') ? (string) file_get_contents(substr($body, 1)) : $body, ['http_code' => $status, 'response_headers' => ['content-type: application/json']]);
                }
            }

            return new MockResponse('{"status_code": 34}', ['http_code' => 404]);
        });
    }

    /** @return array<string, array{0: int, 1: string}> */
    private static function routes(): array
    {
        $f = '@'.__DIR__.'/Fixtures/';
        $api = '~^GET https://api\.themoviedb\.org/3/';

        return [
            $api.'search/movie\?~' => [200, $f.'search-movie.json'],
            $api.'search/multi\?~' => [200, $f.'search-multi.json'],
            $api.'movie/603/watch/providers~' => [200, $f.'watch-603.json'],
            $api.'movie/603\?~' => [200, $f.'movie-603.json'],
            $api.'tv/1920/season/1/episode/1\?~' => [200, $f.'episode-1920-1-1.json'],
            $api.'tv/1920/season/1\?~' => [200, $f.'season-1920-1.json'],
            $api.'tv/1920\?~' => [200, $f.'tv-1920.json'],
            $api.'find/70533\?~' => [200, $f.'find-tvdb.json'],
            $api.'find/tt0000001\?~' => [200, $f.'find-episode.json'],
            $api.'find/tt0133093\?~' => [200, '{"movie_results": [{"id": 603, "title": "The Matrix", "media_type": "movie"}], "tv_results": [], "tv_episode_results": []}'],
            $api.'~' => [404, '{"success": false, "status_code": 34, "status_message": "The resource you requested could not be found."}'],
        ];
    }

    private static function header(string $name): ?string
    {
        foreach (self::$requests[0]['headers'] ?? [] as $header) {
            if (str_starts_with(strtolower($header), strtolower($name).':')) {
                return trim(substr($header, \strlen($name) + 1));
            }
        }

        return null;
    }

    /** @param array<string, mixed> $options */
    private static function provider(MockHttpClient $http, array $options = []): TmdbProvider
    {
        $provider = (new TmdbFactory($http))->create($options + ['token' => self::TOKEN]);
        self::assertInstanceOf(TmdbProvider::class, $provider);

        return $provider;
    }
}
