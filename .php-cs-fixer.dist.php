<?php

$finder = PhpCsFixer\Finder::create()
    ->exclude('vendor')
    ->in(__DIR__);

$config = new PhpCsFixer\Config();
$config
    ->setUsingCache(false)
    ->setRules([
        '@PSR1' => true,
        '@PSR2' => true,
        '@PhpCsFixer' => true,
        'concat_space' => ['spacing' => 'one'],
        'array_syntax' => ['syntax' => 'short'],
        'no_superfluous_phpdoc_tags' => false,
        'trailing_comma_in_multiline_array' => ['after_heredoc' => true],
    ])
    ->setFinder($finder)
    ;
return $config;
