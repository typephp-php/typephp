<?php

declare(strict_types=1);

namespace TypePHP\Internal\Diagnostic;

/**
 * @internal Value object holding validation error messages prior to call-site exception throwing.
 */
final class ErrorMessage
{
    public function __construct(private string $message)
    {
    }

    public function getMessage(): string
    {
        return $this->message;
    }
}
