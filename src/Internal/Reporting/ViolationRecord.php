<?php

declare(strict_types=1);

namespace TypePHP\Internal\Reporting;

use JsonSerializable;

/**
 * @internal Immutable value object representing a single type contract violation.
 */
final readonly class ViolationRecord implements JsonSerializable
{
    /**
     * @param 'parameter'|'return'|'property'|'variable'|'param-out'|'self-out'|'callback'|'yield'|'send' $kind
     */
    public function __construct(
        public string $file,
        public int $line,
        public string $function,
        public string $kind,
        public string $target,
        public string $expected,
        public string $given,
        public string $message,
        public int $count = 1,
        public ?string $caller = null,
        public ?string $declaredIn = null
    ) {
    }

    /**
     * Computes a deterministic deduplication hash for this violation.
     */
    public function getHash(): string
    {
        return hash('xxh128', "{$this->file}:{$this->line}:{$this->function}:{$this->kind}:{$this->target}:{$this->expected}:{$this->given}");
    }

    /**
     * Returns a new instance with the count incremented by the specified amount.
     */
    public function withIncrementedCount(int $amount = 1): self
    {
        return new self(
            file: $this->file,
            line: $this->line,
            function: $this->function,
            kind: $this->kind,
            target: $this->target,
            expected: $this->expected,
            given: $this->given,
            message: $this->message,
            count: $this->count + $amount,
            caller: $this->caller,
            declaredIn: $this->declaredIn
        );
    }

    /**
     * @return array{
     *     file: string,
     *     line: int,
     *     function: string,
     *     kind: string,
     *     target: string,
     *     expected: string,
     *     given: string,
     *     count: int,
     *     caller: ?string,
     *     declared_in: ?string,
     *     message: string
     * }
     */
    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'line' => $this->line,
            'function' => $this->function,
            'kind' => $this->kind,
            'target' => $this->target,
            'expected' => $this->expected,
            'given' => $this->given,
            'count' => $this->count,
            'caller' => $this->caller,
            'declared_in' => $this->declaredIn,
            'message' => $this->message,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        if (
            ! isset($data['file'], $data['line'], $data['message'])
            || ! \is_string($data['file'])
            || ! is_numeric($data['line'])
            || ! \is_string($data['message'])
        ) {
            return null;
        }

        /** @var 'parameter'|'return'|'property'|'variable'|'param-out'|'self-out'|'callback'|'yield'|'send' $kind */
        $kind = isset($data['kind']) && \is_string($data['kind']) ? $data['kind'] : 'parameter';

        $count = isset($data['count']) && \is_int($data['count']) && $data['count'] > 0 ? $data['count'] : 1;
        $caller = isset($data['caller']) && \is_string($data['caller']) ? $data['caller'] : null;
        $declaredIn = isset($data['declared_in']) && \is_string($data['declared_in'])
            ? $data['declared_in']
            : (isset($data['declaredIn']) && \is_string($data['declaredIn']) ? $data['declaredIn'] : null);

        return new self(
            file: $data['file'],
            line: (int) $data['line'],
            function: isset($data['function']) && \is_string($data['function']) ? $data['function'] : 'unknown',
            kind: $kind,
            target: isset($data['target']) && \is_string($data['target']) ? $data['target'] : 'unknown',
            expected: isset($data['expected']) && \is_string($data['expected']) ? $data['expected'] : 'valid type',
            given: isset($data['given']) && \is_string($data['given']) ? $data['given'] : 'invalid value',
            message: $data['message'],
            count: $count,
            caller: $caller,
            declaredIn: $declaredIn
        );
    }
}
