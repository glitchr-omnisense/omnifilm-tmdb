<?php

namespace Omnifilm\Tmdb\Tests;

use Omnifilm\Testing\BareTestCase;
use Omnifilm\Tmdb\TmdbProvider;

/** omnifilm/tmdb's classes load with omnisense/omnifilm, php-tmdb/api and the PSR interfaces alone. */
final class BareTest extends BareTestCase
{
    protected static function entryClass(): string
    {
        return TmdbProvider::class;
    }

    protected static function requiredPackages(): array
    {
        return ['symfony/http-client-contracts', 'php-tmdb/api', 'psr/event-dispatcher', 'psr/http-client', 'psr/http-message', 'psr/http-factory'];
    }
}
