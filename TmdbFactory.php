<?php

namespace Omnifilm\Tmdb;

use Nyholm\Psr7\Factory\Psr17Factory;
use Omnifilm\Config;
use Omnifilm\Exception\InvalidConfigException;
use Omnifilm\ProviderFactory;
use Omnifilm\ProviderInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Tmdb\Client;
use Tmdb\Event\BeforeRequestEvent;
use Tmdb\Event\Listener\Request\AcceptJsonRequestListener;
use Tmdb\Event\Listener\Request\ApiTokenRequestListener;
use Tmdb\Event\Listener\Request\ContentTypeJsonRequestListener;
use Tmdb\Event\Listener\Request\UserAgentRequestListener;
use Tmdb\Event\Listener\RequestListener;
use Tmdb\Event\RequestEvent;
use Tmdb\Token\Api\ApiToken;
use Tmdb\Token\Api\BearerToken;

/**
 * "tmdb": php-tmdb/api's client over the application's HTTP client (through
 * Symfony's PSR-18 bridge and nyholm/psr7's factories), its listeners on
 * this package's PSR-14 dispatcher. Options: token (the API Read Access
 * Token, sent as a Bearer header) or api_key (the v3 key, sent in the
 * query) - one of them; language ("en-US"), images (the image server,
 * "https://image.tmdb.org/t/p/"), poster ("w500"), backdrop ("w1280"),
 * still ("w300"), logo ("w92"), cast (20: the cast members kept).
 */
final class TmdbFactory extends ProviderFactory
{
    public function __construct(private readonly ?HttpClientInterface $http = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnifilm.factory_name' => 'tmdb',
            'omnifilm.factory_title' => 'The Movie Database',
            'omnifilm.required_options' => [],
            'token' => null,
            'api_key' => null,
            'language' => 'en-US',
            'images' => 'https://image.tmdb.org/t/p/',
            'poster' => 'w500',
            'backdrop' => 'w1280',
            'still' => 'w300',
            'logo' => 'w92',
            'cast' => 20,
        ]);
    }

    protected function build(Config $c): ProviderInterface
    {
        $token = match (true) {
            null !== $c->string('token') => new BearerToken((string) $c->string('token')),
            null !== $c->string('api_key') => new ApiToken((string) $c->string('api_key')),
            default => throw new InvalidConfigException('The "tmdb" provider needs: token (the API Read Access Token) or api_key.'),
        };
        $psr17 = new Psr17Factory();
        $dispatcher = new EventDispatcher();
        $client = new Client([
            'api_token' => $token,
            'secure' => true,
            'event_dispatcher' => ['adapter' => $dispatcher],
            'http' => [
                'client' => new Psr18Client($this->http ?? HttpClient::create(['timeout' => 20]), $psr17, $psr17),
                'request_factory' => $psr17,
                'response_factory' => $psr17,
                'stream_factory' => $psr17,
                'uri_factory' => $psr17,
            ],
        ]);
        $dispatcher->listen(RequestEvent::class, new RequestListener($client->getHttpClient(), $dispatcher));
        $dispatcher->listen(BeforeRequestEvent::class, new ApiTokenRequestListener($token));
        $dispatcher->listen(BeforeRequestEvent::class, new AcceptJsonRequestListener());
        $dispatcher->listen(BeforeRequestEvent::class, new ContentTypeJsonRequestListener());
        $dispatcher->listen(BeforeRequestEvent::class, new UserAgentRequestListener());

        return new TmdbProvider($client, (string) $c->string('language'), [
            'base' => (string) $c->string('images'),
            'poster' => (string) $c->string('poster'),
            'backdrop' => (string) $c->string('backdrop'),
            'still' => (string) $c->string('still'),
            'logo' => (string) $c->string('logo'),
        ], $c->int('cast', 20));
    }
}
