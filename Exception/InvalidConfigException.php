<?php

namespace Omnischolar\Exception;

/**
 * A source misconfigured: a key missing, an unknown factory. omnisense/contracts'
 * InvalidConfigException, an \InvalidArgumentException (a \LogicException as before).
 */
final class InvalidConfigException extends \Omnisense\Contracts\Exception\InvalidConfigException implements OmnischolarException
{
}
