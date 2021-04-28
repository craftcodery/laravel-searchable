<?php

namespace CraftCodery\Searchable;

use Illuminate\Support\ServiceProvider;

class SearchableServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $configPath = __DIR__ . '/../config/searchable.php';
        $this->publishes([$configPath => config_path('searchable.php')]);
        $this->mergeConfigFrom($configPath, 'searchable');
    }
}
