<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Thuraaung\Rulecraft\Builder\Http\AuthorizeBuilder;
use Thuraaung\Rulecraft\Builder\Http\BuilderController;

Route::prefix(config('rulecraft.builder.route_prefix', 'rulecraft/builder'))
    ->middleware(array_merge(config('rulecraft.builder.middleware', ['web', 'auth']), [
        AuthorizeBuilder::class,
    ]))->group(function (): void {
        Route::get('policy', [BuilderController::class, 'load'])->name('rulecraft.builder.load');
        Route::post('validate', [BuilderController::class, 'validateDraft'])
            ->name('rulecraft.builder.validate');
        Route::post('preview', [BuilderController::class, 'preview'])
            ->name('rulecraft.builder.preview');
        Route::post('save', [BuilderController::class, 'save'])->name('rulecraft.builder.save');
        Route::get('assets/{file}', [BuilderController::class, 'asset'])
            ->name('rulecraft.builder.asset');
    });
