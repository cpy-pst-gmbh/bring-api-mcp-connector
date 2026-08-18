<?php

$finder = new PhpCsFixer\Finder()
    ->in(__DIR__)
    ->exclude('var')
    ->notPath(
        [
            'config/bundles.php',
            'config/reference.php',
        ],
    );

return new PhpCsFixer\Config()
    ->setRules(
        [
            '@Symfony' => true,
            'concat_space' => [
                'spacing' => 'one',
            ],
            'global_namespace_import' => [
                'import_classes' => true,
                'import_constants' => true,
                'import_functions' => true,
            ],
            'phpdoc_to_comment' => [
                'ignored_tags' => ['var'],
            ],
        ],
    )
    ->setFinder($finder);
