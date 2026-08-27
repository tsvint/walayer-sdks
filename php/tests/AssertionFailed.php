<?php

declare(strict_types=1);

namespace WALayer\Tests;

use Exception;

/** Thrown by {@see TestCase::fail()}; caught by the runner. */
final class AssertionFailed extends Exception
{
}
