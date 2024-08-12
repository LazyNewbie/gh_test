<?php

require __DIR__ . '/../vendor/autoload.php';

$repo = new \Exads\Repos\Repo();

var_dump([
    $repo->getString(),
    $repo->getString2(),
]);