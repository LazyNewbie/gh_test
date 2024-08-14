<?php

namespace Exads\Test\Repos;

use Exads\Repos\Repo;
use Exads\Repos\Repo2;
use PHPUnit\Framework\TestCase;

class Repo2Test extends TestCase
{

    /**
     * @test
     */
    public function getStringMustReturnString(): void
    {
        $r = new Repo2();
        $this->assertEquals("string 1", $r->getString());
    }


}