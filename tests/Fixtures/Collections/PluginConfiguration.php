<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Collections;

use Traversable;

class PluginConfiguration
{
    public ConcreteFileCollection $styleFiles;

    public function __construct(
        public ?ConcreteFileCollection $promotedFiles = null
    ) {
        $this->styleFiles = $promotedFiles ?? new ConcreteFileCollection();
    }

    public function getStyleFiles(): ConcreteFileCollection
    {
        return $this->styleFiles;
    }

    public function setStyleFiles(ConcreteFileCollection $styleFiles): void
    {
        $this->styleFiles = $styleFiles;
    }

    /**
     * @param ConcreteFileCollection $styleFiles
     */
    public function setStyleFilesWithDocblock(ConcreteFileCollection $styleFiles): void
    {
        $this->styleFiles = $styleFiles;
    }

    /**
     * @return ConcreteFileCollection
     */
    public function getStyleFilesWithDocblock(): ConcreteFileCollection
    {
        return $this->styleFiles;
    }

    public function setNullableFiles(?ConcreteFileCollection $files = null): void
    {
        if ($files !== null) {
            $this->styleFiles = $files;
        }
    }

    /**
     * Legitimate generic Traversable parameter that SHOULD be wrapped to validate strings
     *
     * @param Traversable<string> $items
     *
     * @return list<string>
     */
    public function processGenericTraversable(Traversable $items): array
    {
        $collected = [];
        foreach ($items as $item) {
            $collected[] = $item;
        }

        return $collected;
    }
}
