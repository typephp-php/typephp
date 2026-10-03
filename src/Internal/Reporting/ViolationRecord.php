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
        public string $message
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
     * @return array{
     *     file: string,
     *     line: int,
     *     function: string,
     *     kind: string,
     *     target: string,
     *     expected: string,
     *     given: string,
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
        if (! isset($data['file'], $data['line'], $data['message'])) {
            return null;
        }

        /** @var 'parameter'|'return'|'property'|'variable'|'param-out'|'self-out'|'callback'|'yield'|'send' */
        $kind = \is_string($data['kind'] ?? null) ? $data['kind'] : 'parameter';

        return new self(
            file: (string) $data['file'],
            line: (int) $data['line'],
            function: (string) ($data['function'] ?? 'unknown'),
            kind: $kind,
            target: (string) ($data['target'] ?? 'unknown'),
            expected: (string) ($data['expected'] ?? 'valid type'),
            given: (string) ($data['given'] ?? 'invalid value'),
            message: (string) $data['message']
        );
    }
}