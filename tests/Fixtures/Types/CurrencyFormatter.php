<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Types;

class CurrencyFormatter
{
    /**
     * @param non-empty-uppercase-string $currencyCode
     * @param array-key $accountKey
     */
    public function formatAccount(string $currencyCode, mixed $accountKey): string
    {
        return "{$currencyCode}_{$accountKey}";
    }

    /**
     * @param string $code
     *
     * @return uppercase-string
     */
    public static function sanitizeCode(string $code): string
    {
        return $code;
    }
}
