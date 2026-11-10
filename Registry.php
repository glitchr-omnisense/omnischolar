<?php

namespace Omnischolar;

use Omnischolar\Exception\InvalidConfigException;
use Omnischolar\Source\HttpSource;
use Omnischolar\Source\SourceFactoryInterface;
use Omnischolar\Source\SourceInterface;
use Omnisense\Contracts\Policy\InMemoryRateLimiter;
use Omnisense\Contracts\Policy\PolicyGuard;
use Omnisense\Contracts\Policy\RateLimiterInterface;
use Omnisense\Contracts\Policy\UsagePolicy;

/**
 * The sources by name, each built once from its factory and options:
 *
 *   new Registry([new OpenAlexSourceFactory($http), new HalSourceFactory($http)], [
 *       'openalex' => ['factory' => 'openalex', 'options' => ['mailto' => 'support@glitchr.io']],
 *       'droit' => ['factory' => 'hal', 'options' => ['domains' => ['shs.droit']]],
 *   ]);
 *
 * Each source's requests go through its usage policy (docs/policies.md):
 * "policy" tightens it (never looser) and "usage" says what the site is,
 * beside "options" or in them; every source counts in the limiter given (an
 * in-memory one by default; an application served by many processes gives
 * a shared one, omnibridge/symfony's over symfony/rate-limiter).
 */
final class Registry
{
    /** @var array<string, SourceFactoryInterface> */
    private array $factories = [];

    /** @var array<string, SourceInterface> */
    private array $sources = [];

    /** @var array<string, PolicyGuard> the guards of sources that are no HttpSource */
    private array $guards = [];

    private readonly RateLimiterInterface $limiter;

    /**
     * @param iterable<SourceFactoryInterface>                                                                                                  $factories
     * @param array<string, array{factory: string, options?: array<string, mixed>, policy?: array<string, mixed>, usage?: array<string, mixed>}> $config
     */
    public function __construct(iterable $factories, private readonly array $config, ?RateLimiterInterface $limiter = null)
    {
        foreach ($factories as $factory) {
            $this->factories[$factory->getName()] = $factory;
        }
        $this->limiter = $limiter ?? new InMemoryRateLimiter();
    }

    public function get(string $name): SourceInterface
    {
        if (isset($this->sources[$name])) {
            return $this->sources[$name];
        }
        $source = $this->config[$name] ?? throw new InvalidConfigException(\sprintf('No "%s" source; configured: %s.', $name, implode(', ', array_keys($this->config)) ?: 'none'));
        $factory = $this->factories[$source['factory']] ?? throw new InvalidConfigException(\sprintf('No "%s" factory for the "%s" source; installed: %s.', $source['factory'], $name, implode(', ', array_keys($this->factories)) ?: 'none'));

        $options = $source['options'] ?? [];
        foreach (['policy', 'usage'] as $key) {
            if (isset($source[$key]) && !isset($options[$key])) {
                $options[$key] = $source[$key];
            }
        }

        return $this->sources[$name] = $factory->create($options + ['omnischolar.name' => $name, 'omnischolar.limiter' => $this->limiter]);
    }

    /**
     * The guard of a source: its usage policy as the site runs it and the
     * limiter's counts (remaining(), cacheTtl()).
     */
    public function guard(string $name): PolicyGuard
    {
        $source = $this->get($name);
        if ($source instanceof HttpSource) {
            return $source->guard();
        }
        if (!isset($this->guards[$name])) {
            $policy = $this->config[$name]['policy'] ?? $this->options($name)['policy'] ?? [];
            $site = [];
            foreach (\is_array($policy) ? $policy : [] as $key => $value) {
                $site[(string) $key] = $value;
            }
            $this->guards[$name] = new PolicyGuard(UsagePolicy::unknown($source->getName())->tighten($site), $this->limiter);
        }

        return $this->guards[$name];
    }

    /** A source's usage policy as the site runs it: the one its package declares, tightened by the site's. */
    public function policy(string $name): UsagePolicy
    {
        return $this->guard($name)->policy();
    }

    /**
     * Every configured source's policy, by name: what a "sources and their
     * terms" page lists (the credit to show, the licence).
     *
     * @return array<string, UsagePolicy>
     */
    public function policies(): array
    {
        $policies = [];
        foreach ($this->names() as $name) {
            $policies[$name] = $this->policy($name);
        }

        return $policies;
    }

    public function has(string $name): bool
    {
        return isset($this->config[$name]);
    }

    /** @return list<string> the configured sources, in the configured order */
    public function names(): array
    {
        return array_keys($this->config);
    }

    /** @return array<string, SourceInterface> in the configured order */
    public function all(): array
    {
        $all = [];
        foreach (array_keys($this->config) as $name) {
            $all[$name] = $this->get($name);
        }

        return $all;
    }

    /** @return list<string> the factories installed: what `factory:` may name */
    public function factories(): array
    {
        return array_keys($this->factories);
    }

    /** The options a source is configured with (its factory's defaults not included). */
    public function options(string $name): array
    {
        return $this->config[$name]['options'] ?? [];
    }
}
