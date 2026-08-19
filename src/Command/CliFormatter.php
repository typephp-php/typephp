<?php

declare(strict_types=1);

namespace TypePHP\Command;

/**
 * @internal Helper class for ANSI color formatting and terminal STDOUT/STDERR output styling.
 */
final class CliFormatter
{
    private static bool $vt100Initialized = false;

    public static function initVT100(): void
    {
        if (! self::$vt100Initialized) {
            if (\function_exists('sapi_windows_vt100_support')) {
                @sapi_windows_vt100_support(STDOUT, enable: true);
                @sapi_windows_vt100_support(STDERR, enable: true);
            }
            self::$vt100Initialized = true;
        }
    }

    public static function color(string $text, string $style = ''): string
    {
        self::initVT100();

        $hasColor = (
            (\function_exists('sapi_windows_vt100_support') && @sapi_windows_vt100_support(STDOUT)) ||
            DIRECTORY_SEPARATOR === '/' ||
            false !== getenv('ANSICON') ||
            'ON' === getenv('ConEmuANSI') ||
            false !== getenv('TERM') ||
            false !== getenv('COLORTERM')
        ) && false === getenv('NO_COLOR');

        if (! $hasColor || $style === '') {
            return $text;
        }

        $styles = [
            'bold' => "\033[1m",
            'green' => "\033[32m",
            'cyan' => "\033[36m",
            'yellow' => "\033[33m",
            'red' => "\033[31m",
            'gray' => "\033[90m",
            'badge' => "\033[44;37;1m",
            'badge_red' => "\033[41;37;1m",
            'badge_green' => "\033[42;30;1m",
            'reset' => "\033[0m",
        ];

        return ($styles[$style] ?? '') . $text . $styles['reset'];
    }
}
