<?php

namespace Omnifilm\Tmdb;

use Omnifilm\AvailabilityInterface;
use Omnifilm\Capabilities;
use Omnifilm\EpisodesInterface;
use Omnifilm\Exception\NotSupportedException;
use Omnifilm\Exception\ProviderException;
use Omnifilm\Exception\UnreachableException;
use Omnifilm\Http\Data;
use Omnifilm\Http\JsonClient;
use Omnifilm\LookupInterface;
use Omnifilm\Model\Credit;
use Omnifilm\Model\Identifiers;
use Omnifilm\Model\Image;
use Omnifilm\Model\ImageKind;
use Omnifilm\Model\Offer;
use Omnifilm\Model\OfferKind;
use Omnifilm\Model\Query;
use Omnifilm\Model\Rating;
use Omnifilm\Model\Role;
use Omnifilm\Model\Scheme;
use Omnifilm\Model\Work;
use Omnifilm\Model\WorkRef;
use Omnifilm\Model\WorkType;
use Omnifilm\ProviderInterface;
use Omnifilm\SearchInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Tmdb\Client;
use Tmdb\Exception\TmdbApiException;

/**
 * The Movie Database through php-tmdb/api's raw API calls (its arrays, not
 * its models): search, a film's or a series' record with its credits and
 * cross identifiers in one call (append_to_response), an episode found by
 * its IMDb or TheTVDB number, a season's episodes, and where to watch
 * (TMDB's watch providers, JustWatch's data).
 */
final class TmdbProvider implements ProviderInterface, SearchInterface, LookupInterface, EpisodesInterface, AvailabilityInterface
{
    private const FIND = [Scheme::Imdb->value => 'imdb_id', Scheme::Tvdb->value => 'tvdb_id', Scheme::Wikidata->value => 'wikidata_id'];
    private const OFFERS = ['flatrate' => OfferKind::Stream, 'free' => OfferKind::Free, 'ads' => OfferKind::Ads, 'rent' => OfferKind::Rent, 'buy' => OfferKind::Buy];
    private const COMPOSERS = ['Original Music Composer', 'Music', 'Composer'];

    /** @param array{base: string, poster: string, backdrop: string, still: string, logo: string} $images */
    public function __construct(private readonly Client $client, private readonly string $language, private readonly array $images, private readonly int $cast = 20)
    {
    }

    public function getName(): string
    {
        return 'tmdb';
    }

    public function getTitle(): string
    {
        return 'The Movie Database';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            search: true,
            lookup: true,
            episodes: true,
            availability: true,
            types: [WorkType::Movie, WorkType::Series, WorkType::Episode],
            lookupBy: [
                'movie' => [Scheme::Tmdb, Scheme::Imdb, Scheme::Wikidata],
                'series' => [Scheme::Tmdb, Scheme::Imdb, Scheme::Tvdb, Scheme::Wikidata],
                'episode' => [Scheme::Imdb, Scheme::Tvdb],
            ],
            images: true,
            credits: true,
            attribution: 'This product uses the TMDB API but is not endorsed or certified by TMDB. Where to watch: data from JustWatch.',
            terms: ['https://www.themoviedb.org/api-terms-of-use', 'https://developer.themoviedb.org/docs/faq', 'https://www.justwatch.com'],
        );
    }

    public function search(Query $query): array
    {
        $parameters = ['language' => $query->language ?? $this->language, 'page' => 1, 'include_adult' => 'false'];
        $answer = $this->call(fn () => match ($query->type) {
            WorkType::Movie => $this->client->getSearchApi()->searchMovies($query->text, $parameters + ['primary_release_year' => $query->year]),
            WorkType::Series => $this->client->getSearchApi()->searchTv($query->text, $parameters + ['first_air_date_year' => $query->year]),
            default => $this->client->getSearchApi()->searchMulti($query->text, $parameters),
        });
        $works = [];
        foreach ($answer?->list('results') ?? [] as $result) {
            $type = match ($query->type ?? $result->string('media_type')) {
                WorkType::Movie, 'movie' => WorkType::Movie,
                WorkType::Series, 'tv' => WorkType::Series,
                default => null,
            };
            $work = null === $type ? null : $this->work0($result, $type);
            if (null !== $work && (null === $query->year || null !== $query->type || $query->year === $work->year)) {
                $works[] = $work;
            }
        }

        return \array_slice($works, 0, $query->limit);
    }

    public function work(WorkRef $ref, ?string $language = null): ?Work
    {
        if (!$this->capabilities()->resolves($ref)) {
            throw NotSupportedException::ref($this->getTitle(), $ref);
        }
        $parameters = ['language' => $language ?? $this->language, 'append_to_response' => 'credits,external_ids'];
        if (WorkType::Episode === $ref->type) {
            $found = $this->find($ref, 'tv_episode_results');
            $show = $found?->int('show_id');
            if (null === $found || null === $show) {
                return null;
            }
            $answer = $this->call(fn () => $this->client->getTvEpisodeApi()->getEpisode($show, (int) $found->int('season_number'), (int) $found->int('episode_number'), $parameters));

            return null === $answer ? null : $this->episode($answer, $show);
        }
        $id = Scheme::Tmdb === $ref->scheme ? $ref->id : $this->find($ref, WorkType::Movie === $ref->type ? 'movie_results' : 'tv_results')?->string('id');
        if (null === $id) {
            return null;
        }
        $answer = $this->call(fn () => WorkType::Movie === $ref->type ? $this->client->getMoviesApi()->getMovie((int) $id, $parameters) : $this->client->getTvApi()->getTvshow((int) $id, $parameters));

        return null === $answer ? null : $this->work0($answer, $ref->type);
    }

    public function episodes(WorkRef $series, int $season, ?string $language = null): array
    {
        $id = Scheme::Tmdb === $series->scheme ? $series->id : $this->work(new WorkRef(WorkType::Series, $series->scheme, $series->id))?->identifiers->get(Scheme::Tmdb);
        if (null === $id || WorkType::Series !== $series->type) {
            return [];
        }
        $answer = $this->call(fn () => $this->client->getTvSeasonApi()->getSeason((int) $id, $season, ['language' => $language ?? $this->language]));

        return array_map(fn (Data $episode) => $this->episode($episode, (int) $id), $answer?->list('episodes') ?? []);
    }

    public function offers(WorkRef $ref, string $country): array
    {
        $id = Scheme::Tmdb === $ref->scheme ? $ref->id : $this->work($ref)?->identifiers->get(Scheme::Tmdb);
        if (null === $id || WorkType::Episode === $ref->type) {
            return [];
        }
        $answer = $this->call(fn () => WorkType::Movie === $ref->type ? $this->client->getMoviesApi()->getWatchProviders((int) $id) : $this->client->getTvApi()->getWatchProviders((int) $id));
        $country = strtoupper($country);
        $where = $answer?->data('results')->data($country);
        $offers = [];
        foreach (self::OFFERS as $key => $kind) {
            foreach ($where?->list($key) ?? [] as $service) {
                if (null !== $name = $service->string('provider_name')) {
                    $offers[] = new Offer($kind, $name, $country, $where?->string('link'), $this->image('logo', $service->string('logo_path')));
                }
            }
        }

        return $offers;
    }

    /** The first result of /find for an identifier TMDB keeps. */
    private function find(WorkRef $ref, string $results): ?Data
    {
        $answer = $this->call(fn () => $this->client->getFindApi()->findBy($ref->id, ['external_source' => self::FIND[$ref->scheme->value] ?? 'imdb_id']));

        return $answer?->list($results)[0] ?? null;
    }

    /**
     * A call of php-tmdb/api: its array read through Data, a 404 as null,
     * its failures as omnifilm's exceptions.
     *
     * @param \Closure(): mixed $call
     */
    private function call(\Closure $call): ?Data
    {
        try {
            return Data::of($call());
        } catch (TmdbApiException $e) {
            $status = $e->getResponse()->getStatusCode();
            if (404 === $status || 34 === $e->getCode()) {
                return null;
            }
            throw ProviderException::answered('TMDB', 'GET '.$e->getRequest()->getUri()->getPath(), $status, $e->getMessage());
        } catch (ClientExceptionInterface $e) {
            // The path only: with the api_key option, the key travels in the query the client's message quotes.
            $request = method_exists($e, 'getRequest') ? $e->getRequest() : null;
            $what = $request instanceof RequestInterface ? ' for '.$request->getMethod().' '.($request->getUri()->getPath() ?: '/') : '';

            throw new UnreachableException('TMDB could not be reached'.$what.': '.JsonClient::reason($e->getMessage()), 0, $e);
        }
    }

    private function work0(Data $data, WorkType $type): Work
    {
        $movie = WorkType::Movie === $type;
        $id = $data->string('id');
        $released = $data->date($movie ? 'release_date' : 'first_air_date');
        $ids = $data->data('external_ids');

        return new Work(
            type: $type,
            title: (string) ($data->string($movie ? 'title' : 'name') ?? $data->string($movie ? 'original_title' : 'original_name')),
            identifiers: Identifiers::of(['tmdb' => $id, 'imdb' => $data->string('imdb_id') ?? $ids->string('imdb_id'), 'tvdb' => $ids->string('tvdb_id'), 'wikidata' => $ids->string('wikidata_id')]),
            source: 'tmdb',
            originalTitle: $data->string($movie ? 'original_title' : 'original_name'),
            year: null !== $released ? (int) $released->format('Y') : null,
            released: $released,
            runtime: $data->int('runtime') ?? $data->int('episode_run_time.0'),
            overview: $data->string('overview'),
            genres: $data->strings('genres', 'name'),
            countries: $movie ? $data->strings('production_countries', 'iso_3166_1') : $data->strings('origin_country'),
            languages: $data->strings('spoken_languages', 'iso_639_1') ?: array_filter([$data->string('original_language')]),
            images: array_values(array_filter([$this->picture(ImageKind::Poster, $data->string('poster_path')), $this->picture(ImageKind::Backdrop, $data->string('backdrop_path'))])),
            credits: $this->credits($data->data('credits')),
            ratings: self::rating($data),
            url: null === $id ? null : 'https://www.themoviedb.org/'.($movie ? 'movie' : 'tv').'/'.$id,
            season: $movie ? null : $data->int('number_of_seasons'),
        );
    }

    private function episode(Data $data, int $show): Work
    {
        $released = $data->date('air_date');
        $ids = $data->data('external_ids');
        $season = $data->int('season_number');
        $number = $data->int('episode_number');

        return new Work(
            type: WorkType::Episode,
            title: (string) ($data->string('name') ?? 'Episode '.$number),
            identifiers: Identifiers::of(['tmdb' => $data->string('id'), 'imdb' => $ids->string('imdb_id'), 'tvdb' => $ids->string('tvdb_id'), 'wikidata' => $ids->string('wikidata_id')]),
            source: 'tmdb',
            year: null !== $released ? (int) $released->format('Y') : null,
            released: $released,
            runtime: $data->int('runtime'),
            overview: $data->string('overview'),
            images: null === ($still = $this->picture(ImageKind::Still, $data->string('still_path'))) ? [] : [$still],
            credits: $this->credits($data->data('credits')),
            ratings: self::rating($data),
            url: 'https://www.themoviedb.org/tv/'.$show.'/season/'.$season.'/episode/'.$number,
            series: Identifiers::of(['tmdb' => $show]),
            season: $season,
            number: $number,
        );
    }

    /** @return list<Credit> */
    private function credits(Data $credits): array
    {
        $list = [];
        foreach ($credits->list('crew') as $member) {
            $job = (string) $member->string('job');
            $role = match (true) {
                'Director' === $job => Role::Director,
                'Writing' === $member->string('department') => Role::Writer,
                'Producer' === $job => Role::Producer,
                \in_array($job, self::COMPOSERS, true) => Role::Composer,
                default => null,
            };
            if (null !== $role && null !== $name = $member->string('name')) {
                $list[] = new Credit($name, $role, null, null, Identifiers::of(['tmdb' => $member->string('id')]), $job ?: null);
            }
        }
        foreach (\array_slice($credits->list('cast'), 0, $this->cast) as $member) {
            if (null !== $name = $member->string('name')) {
                $list[] = new Credit($name, Role::Cast, $member->string('character'), $member->int('order'), Identifiers::of(['tmdb' => $member->string('id')]));
            }
        }

        return $list;
    }

    /** @return list<Rating> */
    private static function rating(Data $data): array
    {
        $votes = $data->int('vote_count');

        return null !== $votes && $votes > 0 ? [new Rating('tmdb', (float) $data->float('vote_average'), 10.0, $votes)] : [];
    }

    private function picture(ImageKind $kind, ?string $path): ?Image
    {
        $url = $this->image($kind->value, $path);

        return null === $url ? null : new Image($kind, $url);
    }

    private function image(string $size, ?string $path): ?string
    {
        return null === $path ? null : rtrim($this->images['base'], '/').'/'.($this->images[$size] ?? 'original').'/'.ltrim($path, '/');
    }
}
