<?php

namespace Exads\Repos;

class Repo2
{

    public function getString(): string
    {
        return "string 1";
    }

    public function getString2(): string
    {
        return "string 2";
    }


    public function getString3(): string
    {
        return "string 3";
    }


    public function getString4($x=1): string
    {
        if($x==2){
            return "string 4.1";    
        }
        return "string 1";
    }
}