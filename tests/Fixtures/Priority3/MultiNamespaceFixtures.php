<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Priority3\SubA {
    use TypePHP\Tests\Fixtures\Domain\Cat;
    use TypePHP\Tests\Fixtures\Domain\Dog;
    use TypePHP\Tests\Fixtures\Domain\Dog as TargetPet;

    class MultiNsCallerA
    {
        /**
         * @return TargetPet
         */
        public function getPet(): object
        {
            return new Dog();
        }

        /**
         * @return TargetPet
         */
        public function getWrongPet(): object
        {
            return new Cat();
        }

        /**
         * @param TargetPet $pet
         *
         * @return Dog
         */
        public function passthroughDog(object $pet): object
        {
            return $pet;
        }

        /**
         * @param TargetPet $pet
         *
         * @return Cat
         */
        public function unsoundPassthrough(object $pet): object
        {
            return $pet;
        }
    }
}

namespace TypePHP\Tests\Fixtures\Priority3\SubB {
    use TypePHP\Tests\Fixtures\Domain\Cat;
    use TypePHP\Tests\Fixtures\Domain\Cat as TargetPet;

    class MultiNsCallerB
    {
        /**
         * @return TargetPet
         */
        public function getPet(): object
        {
            return new Cat();
        }
    }
}
