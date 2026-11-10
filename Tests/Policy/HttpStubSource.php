<?php

namespace Omnischolar\Tests\Policy;

use Omnischolar\Model\Identifier;
use Omnischolar\Model\Work;
use Omnischolar\Model\WorkType;
use Omnischolar\Source\Capability;
use Omnischolar\Source\HttpSource;
use Omnisense\Contracts\Policy\UsagePolicy;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** A source over HTTP that reads a work's title from {"title": ...}, declaring the policy it is given. */
final class HttpStubSource extends HttpSource
{
    public function __construct(HttpClientInterface $http, private readonly ?UsagePolicy $policy = null, float $throttle = 0.0)
    {
        parent::__construct($http, 'https://stub.example.org/', [], $throttle);
    }

    public function getName(): string
    {
        return 'http-stub';
    }

    public function capabilities(): array
    {
        return [Capability::WORK];
    }

    public function usagePolicy(): UsagePolicy
    {
        return $this->policy ?? parent::usagePolicy();
    }

    public function work(Identifier|string $id): ?Work
    {
        $data = $this->getJson('works/'.(string) $id);

        return null === $data ? null : new Work(\is_string($data['title'] ?? null) ? $data['title'] : '', WorkType::ARTICLE, source: $this->getName());
    }
}
