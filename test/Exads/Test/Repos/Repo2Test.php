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

    /**
     * @test
     */
    public function getString2MustReturnString(): void
    {
        $r = new Repo2();
        $this->assertEquals("string 2", $r->getString2());
    }

    /**
     * @test
     */
    public function getString3MustReturnString(): void
    {
        $r = new Repo2();
        $this->assertEquals("string 3", $r->getString3());
    }

    /**
     * @test
     */
    public function getString4MustReturnString(): void
    {
        $r = new Repo2();
        $this->assertEquals("string 4", $r->getString3());
    }
}