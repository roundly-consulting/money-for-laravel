<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Exceptions;

use RuntimeException;

/**
 * Base of every exception money-for-laravel throws, so a host can catch the whole family
 * in one place.
 */
abstract class MoneyException extends RuntimeException {}
