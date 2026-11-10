<?php

namespace Omnischolar\Tests\Policy;

use Omnischolar\Config;
use Omnischolar\Source\SourceFactory;
use Omnischolar\Source\SourceInterface;
use Omnisense\Contracts\Policy\UsagePolicy;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Builds HttpStubSources declaring the policy it is given. */
final class HttpStubSourceFactory extends SourceFactory
{
    public function __construct(HttpClientInterface $http, private readonly ?UsagePolicy $policy = null)
    {
        parent::__construct($http);
    }

    protected function populate(Config $c): void
    {
        $c->defaults(['omnischolar.factory_name' => 'http-stub']);
    }

    protected function build(Config $c): SourceInterface
    {
        \assert(null !== $this->http);

        return new HttpStubSource($this->http, $this->policy, $c->float('throttle'));
    }
}
