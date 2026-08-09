<?php

declare(strict_types=1);

namespace TypePHP\Internal;

/**
 * @internal Contract defining PHP's native stream wrapper protocol methods.
 *
 * @see https://www.php.net/manual/en/class.streamwrapper.php
 */
interface StreamWrapperInterface
{
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool;

    public function stream_read(int $count): string;

    public function stream_write(string $data): int;

    public function stream_lock(int $operation): bool;

    public function stream_flush(): bool;

    public function stream_truncate(int $new_size): bool;

    public function stream_eof(): bool;

    /**
     * @return array<int|string, int>|false
     */
    public function stream_stat(): array|false;

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool;

    public function stream_set_option(int $option, int $arg1, int $arg2): bool;

    public function stream_close(): void;

    /**
     * @return array<int|string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false;

    public function dir_opendir(string $path, int $options): bool;

    public function dir_readdir(): string|false;

    public function dir_rewinddir(): bool;

    public function dir_closedir(): bool;

    public function mkdir(string $path, int $mode, int $options): bool;

    public function rmdir(string $path, int $options): bool;

    public function unlink(string $path): bool;

    public function rename(string $pathFrom, string $pathTo): bool;

    public function stream_metadata(string $path, int $option, mixed $value): bool;

    /**
     * @return resource|false
     */
    public function stream_cast(int $cast_as);
}
