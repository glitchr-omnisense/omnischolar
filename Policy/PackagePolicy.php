<?php

namespace Omnischolar\Policy;

use Omnisense\Contracts\Policy\UsagePolicy;

/**
 * The usage policy a package declares: the policy.json its composer.json
 * names (extra.omnisense.policy), found from one of its classes - in a
 * workspace's checkout as in an application's vendor/. Null when the
 * package declares none.
 */
final class PackagePolicy
{
    /** @var array<string, ?UsagePolicy> by class */
    private static array $read = [];

    /** @param class-string $class */
    public static function of(string $class): ?UsagePolicy
    {
        if (\array_key_exists($class, self::$read)) {
            return self::$read[$class];
        }
        $file = (new \ReflectionClass($class))->getFileName();
        $dir = false === $file ? null : \dirname($file);
        for ($depth = 0; null !== $dir && $depth < 4; ++$depth, $dir = \dirname($dir)) {
            if (!is_file($dir.'/composer.json')) {
                continue;
            }
            $meta = json_decode((string) file_get_contents($dir.'/composer.json'), true);
            $path = \is_array($meta) && \is_array($meta['extra'] ?? null) && \is_array($meta['extra']['omnisense'] ?? null) ? ($meta['extra']['omnisense']['policy'] ?? null) : null;

            return self::$read[$class] = \is_string($path) && '' !== $path ? UsagePolicy::fromFile($dir.'/'.$path) : null;
        }

        return self::$read[$class] = null;
    }
}
