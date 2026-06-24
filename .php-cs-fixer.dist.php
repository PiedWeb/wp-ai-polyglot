<?php

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__.'/inc', __DIR__.'/tests'])
    ->append([__DIR__.'/wp-ai-polyglot.php'])
    ->name('*.php')
    ->exclude(['stubs']);

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        '@PSR2' => true,
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'array_syntax' => ['syntax' => 'short'],
        'array_indentation' => true,
        'trim_array_spaces' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'not_operator_with_successor_space' => true,
        'trailing_comma_in_multiline' => true,
        'phpdoc_scalar' => true,
        'unary_operator_spaces' => true,
        'binary_operator_spaces' => true,
        'blank_line_before_statement' => [
            'statements' => ['break', 'continue', 'declare', 'return', 'throw', 'try'],
        ],
        'phpdoc_single_line_var_spacing' => true,
        'phpdoc_var_without_name' => true,
        'class_attributes_separation' => [
            'elements' => ['const' => 'one', 'method' => 'one', 'property' => 'one'],
        ],
        'method_argument_space' => [
            'on_multiline' => 'ensure_fully_multiline',
            'keep_multiple_spaces_after_comma' => true,
        ],
        'single_trait_insert_per_statement' => true,
        // Plugin uses WP-style includes, not PSR-4 autoloading: classes are named
        // by purpose (Polyglot_CLI in cli.php), not by filename. This risky fixer
        // would rename them to match the file and break WP_CLI::add_command().
        'psr_autoloading' => false,
        'modernize_types_casting' => false,
        'phpdoc_to_comment' => false,
        'global_namespace_import' => ['import_classes' => true, 'import_constants' => false, 'import_functions' => false],
    ])
    ->setRiskyAllowed(true)
    ->setFinder($finder);
