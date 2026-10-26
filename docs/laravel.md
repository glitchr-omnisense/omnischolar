---
title: Laravel
order: 16
---

# Laravel

omnibridge/laravel, the bridge that hosts omnibase in Laravel, has no Omnischolar provider. None
is needed: the registry, the merger, the collector and the export are plain PHP, and a service
provider of the application builds them once, from a config file.

```php
// config/omnischolar.php
return [
    'sources' => [
        'openalex' => ['factory' => 'openalex', 'options' => ['mailto' => env('OMNISCHOLAR_MAILTO'), 'api_key' => env('OPENALEX_API_KEY')]],
        'hal' => ['factory' => 'hal'],
        'crossref' => ['factory' => 'crossref', 'options' => ['mailto' => env('OMNISCHOLAR_MAILTO')]],
    ],
    'merger' => ['preferences' => ['abstract' => ['hal', 'openalex']], 'editions' => true],
    'export' => ['abstracts' => false],
];
```

```php
// app/Providers/OmnischolarServiceProvider.php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Omnischolar\Collector;
use Omnischolar\Crossref\CrossrefSourceFactory;
use Omnischolar\Export;
use Omnischolar\Hal\HalSourceFactory;
use Omnischolar\Merger;
use Omnischolar\OpenAlex\OpenAlexSourceFactory;
use Omnischolar\Registry;
use Symfony\Component\HttpClient\HttpClient;

final class OmnischolarServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Registry::class, function (): Registry {
            $http = HttpClient::create();

            return new Registry([
                new OpenAlexSourceFactory($http),
                new HalSourceFactory($http),
                new CrossrefSourceFactory($http),
            ], config('omnischolar.sources'));
        });
        $this->app->singleton(Merger::class, fn (): Merger => new Merger(config('omnischolar.merger.preferences'), config('omnischolar.merger.editions')));
        $this->app->singleton(Collector::class, fn ($app): Collector => new Collector($app->make(Registry::class), $app->make(Merger::class)));
        $this->app->singleton(Export::class, fn (): Export => new Export(config('omnischolar.export.abstracts')));
    }
}
```

Register the provider (`bootstrap/providers.php` on Laravel 11 and later), then inject the
collector or the registry:

```php
use Omnischolar\Collector;
use Omnischolar\Export;

Route::get('/publications.bib', function (Collector $collector, Export $export) {
    $works = $collector->collect(['openalex' => 'A5108007452', 'hal' => 'Keitaro Nakatani']);

    return response($export->bibtex($works), 200, ['Content-Type' => Export::contentType('bibtex')]);
});
```

The HTTP client is `symfony/http-client`'s (every provider requires it). List in the provider
every factory the application uses: a hand-built registry knows only the factories it is given.
Cache what is collected (`Cache::remember()`), except when `$collector->incomplete` is not empty.
This page was written from the classes' API; it was not run in a Laravel application.
