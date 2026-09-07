<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| PHP CS Fixer (opcional)
|--------------------------------------------------------------------------
|
|   composer require --dev friendsofphp/php-cs-fixer
|   php vendor/bin/php-cs-fixer fix --dry-run --diff
|
| El proyecto ya sigue PSR-12 a mano; este fichero existe para que el que lo
| instale no se encuentre con un susto de 400 cambios. Las reglas más exigentes
| van comentadas: se activan si te gustan.
|
 */

$finder = (new PhpCsFixer\Finder())
    ->in(['src', 'app', 'config', 'public', 'routes', 'database', 'tests', 'bin'])
    ->notPath(['Fixtures/views']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'binary_operator_spaces' => ['default' => 'single_space'],
        'blank_line_after_opening_tag' => true,
        'cast_spaces' => true,
        'concat_space' => ['spacing' => 'none'],
        'declare_strict_types' => true,
        'function_typehint_space' => true,
        'lowercase_cast' => true,
        'method_argument_space' => ['on_multiline' => 'ensure_fully_multiline'],
        'native_function_casing' => true,
        'no_blank_lines_after_class_opening' => true,
        'no_empty_phpdoc' => true,
        'no_leading_import_slash' => true,
        'no_multiline_whitespace_around_double_arrow' => true,
        'no_superfluous_phpdoc_tags' => ['allow_mixed' => true, 'remove_inheritdoc' => true],
        'no_trailing_comma_in_singleline' => true,
        'no_unused_imports' => true,
        'no_whitespace_in_blank_line' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'phpdoc_scalar' => true,
        'phpdoc_single_quote_var' => true,
        'phpdoc_trim' => true,
        'return_type_declaration' => ['space_before' => 'none'],
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
        'yoda_style' => ['equal' => false, 'identical' => false, 'less_and_greater' => false],
    ])
    ->setFinder($finder);
