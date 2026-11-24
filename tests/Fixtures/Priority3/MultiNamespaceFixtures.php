<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Priority3\SubA {
    use TypePHP\Tests\Fixtures\Domain\Dog as TargetPet;

    class MultiNsCallerA
    {
        /**
         * @return TargetPet
         */
        public function getPet(): object
        {
            return new TargetPet();
        }
    }
}

namespace TypePHP\Tests\Fixtures\Priority3\SubB {
    use TypePHP\Tests\Fixtures\Domain\Cat as TargetPet;

    class MultiNsCallerB
    {
        /**
         * @return TargetPet
         */
        public function getPet(): object
        {
            return new TargetPet();
        }
    }
}
