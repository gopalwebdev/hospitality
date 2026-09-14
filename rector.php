<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Isset_\IssetOnPropertyObjectToPropertyExistsRector;
use Rector\Config\RectorConfig;
use Rector\Php74\Rector\Closure\ClosureToArrowFunctionRector;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\SafeDeclareStrictTypesRector;
use Rector\ValueObject\PhpVersion;
use RectorLaravel\Rector\ClassMethod\MakeModelAttributesAndScopesProtectedRector;
use RectorLaravel\Rector\FuncCall\AppToResolveRector;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/bootstrap/app.php',
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/routes',
        __DIR__.'/tests',
    ])
    ->withPhpVersion(PhpVersion::PHP_85)
    ->withPhpSets(php85: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        earlyReturn: true,
    )
    ->withSets([
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_COLLECTION,
        LaravelSetList::LARAVEL_IF_HELPERS,
        LaravelSetList::LARAVEL_ELOQUENT_MAGIC_METHOD_TO_QUERY_BUILDER,
    ])
    ->withImportNames(importShortClasses: false)
    ->withSkip([
        // Migrations are a historical record; rewriting old ones to today's
        // idiom makes diffs that say nothing about what the schema does.
        __DIR__.'/database/migrations/*',

        // This application resolves out of the container with app(), which is
        // what the framework's own skeleton and every file here already uses.
        AppToResolveRector::class,

        // The Laravel skeleton does not declare strict types, and neither does
        // this application. Turning it on is a decision to take deliberately,
        // not a refactor to have applied on the way past.
        SafeDeclareStrictTypesRector::class,

        // Query scopes here are written public on purpose, so that they read
        // the same way from a test as they do from the model.
        MakeModelAttributesAndScopesProtectedRector::class,

        // Pest rebinds the closures it is handed so that $this is the test
        // case or the expectation. An arrow function captures $this where it
        // was written instead, which at file scope is nothing at all.
        ClosureToArrowFunctionRector::class => [
            __DIR__.'/tests',
        ],

        // isset() is the only safe test for a typed property that has never
        // been assigned — comparing it to null raises an Error instead. It is
        // also what the framework itself uses for these properties.
        IssetOnPropertyObjectToPropertyExistsRector::class,
    ]);
