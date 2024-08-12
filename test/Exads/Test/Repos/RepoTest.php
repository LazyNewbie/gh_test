<?php

namespace Exads\Test\Repos;

use Exads\Repos\Repo;
use PHPUnit\Framework\TestCase;

class RepoTest extends TestCase
{

    /**
     * @test
     */
    public function getStringMustReturnString(): void
    {
        $r = new Repo();
        $this->assertEquals("string 1", $r->getString());
    }

    /**
     * @test
     */
    public function getString2MustReturnString(): void
    {
        $r = new Repo();
        $this->assertEquals("string 2", $r->getString2());
    }
}