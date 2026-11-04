<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Builder;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\Component;
use InvalidArgumentException;
use RuntimeException;
use Thuraaung\Rulecraft\Actions\ActionRegistry;

class BuilderComponent extends Component
{
    /** @var string|null */
    public $group;

    public function __construct(?string $group = null)
    {
        $this->group = $group;
    }

    public function render(): View
    {
        abort_unless(config('rulecraft.builder.enabled'), 404);
        Gate::authorize(config('rulecraft.builder.ability', 'manage-rulecraft'));
        $theme = config('rulecraft.builder.theme', 'custom');
        $stylesheet = config('rulecraft.builder.stylesheet');
        if (! in_array($theme, ['custom', 'bootstrap'], true)) {
            throw new InvalidArgumentException('Builder theme must be custom or bootstrap.');
        }
        if ($stylesheet !== null && (! is_string($stylesheet)
            || preg_match('/^[a-z]+:/i', $stylesheet) || strpos($stylesheet, '//') === 0)) {
            throw new InvalidArgumentException('Builder stylesheet must be a local asset path.');
        }
        $settings = ['group' => $this->group, 'fields' => app(FieldCatalog::class)->all(),
            'functions' => app(FunctionCatalog::class)->all(),
            'actions' => app(ActionRegistry::class)->names(),
            'maxDepth' => config('rulecraft.max_depth'),
            'load' => route('rulecraft.builder.load'),
            'validate' => route('rulecraft.builder.validate'),
            'preview' => route('rulecraft.builder.preview'),
            'save' => route('rulecraft.builder.save'), 'csrf' => csrf_token()];
        $assets = ! request()->attributes->get('rulecraft.builder.assets', false);
        request()->attributes->set('rulecraft.builder.assets', true);
        $assetUrls = [];
        if ($assets) {
            foreach (['builder.css', 'builder.js', 'bootstrap.css'] as $file) {
                $hash = hash_file('sha256', __DIR__.'/../../resources/builder/'.$file);
                if ($hash === false) {
                    throw new RuntimeException('Missing bundled builder asset ['.$file.'].');
                }
                $assetUrls[$file] = route('rulecraft.builder.asset', [
                    'file' => $file, 'v' => substr($hash, 0, 12),
                ]);
            }
        }

        return view('rulecraft::components.builder', [
            'settings' => $settings, 'theme' => $theme, 'stylesheet' => $stylesheet,
            'assetUrls' => $assetUrls, 'includeAssets' => $assets,
            'editorId' => 'rc-'.bin2hex(random_bytes(8)),
        ]);
    }
}
