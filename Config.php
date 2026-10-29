<?php

namespace Omnischolar;

use Omnischolar\Exception\InvalidConfigException;

/**
 * A source's configuration while its factory builds it: the options
 * given, the factory's defaults under them, and the keys that must be filled.
 *
 * @extends \ArrayObject<string, mixed>
 */
final class Config extends \ArrayObject
{
    /** Sets each key not set yet (a null counts as not set). */
    public function defaults(array $defaults): self
    {
        foreach ($defaults as $key => $value) {
            if (!$this->offsetExists($key) || null === $this[$key]) {
                $this[$key] = $value;
            }
        }

        return $this;
    }

    /** @param string[] $keys */
    public function validateNotEmpty(array $keys): void
    {
        $missing = array_values(array_filter($keys, fn (string $key) => !isset($this[$key]) || '' === $this[$key] || [] === $this[$key]));
        if ($missing) {
            throw new InvalidConfigException(\sprintf('The "%s" source needs: %s.', $this['omnischolar.factory_name'] ?? '?', implode(', ', $missing)));
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->offsetExists($key) && null !== $this[$key] ? $this[$key] : $default;
    }

    /**
     * An option read as a boolean whatever its spelling: a site's YAML or
     * environment gives a string, and PHP's (bool) reads "false" as true.
     * true, 1, "1", "true", "yes", "on" are true; false, 0, "0", "false",
     * "no", "off" and "" are false; anything else is an InvalidConfigException.
     */
    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);
        $bool = \is_bool($value) ? $value : (\is_scalar($value) ? filter_var($value, \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE) : null);

        return $bool ?? throw new InvalidConfigException(\sprintf('The "%s" source\'s %s is a boolean (true or false), not %s.', $this['omnischolar.factory_name'] ?? '?', $key, json_encode($value)));
    }
}
