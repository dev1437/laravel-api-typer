<?php

namespace Dev1437\LaravelApiTyper;

use Dev1437\LaravelApiTyper\Console\GenerateApiTyper;
use Illuminate\Support\ServiceProvider;

class ApiTyperServiceProvider extends ServiceProvider
{
    public function register()
    {
        //
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateApiTyper::class,
            ]);
        }
    }
}