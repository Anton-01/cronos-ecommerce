<?php

namespace App\Providers;

use Cronos\Shared\Interfaces\GraphQL\UseGraphQLSchema;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Nuwave\Lighthouse\Http\GraphQLController;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerAdminGraphQLEndpoint();
    }

    /** Second endpoint with its own SDL, kept apart from the public one (ADR-03). */
    private function registerAdminGraphQLEndpoint(): void
    {
        $middleware = collect(config('lighthouse.route.middleware', []))
            ->reject(fn ($m) => str_starts_with((string) $m, UseGraphQLSchema::class))
            ->push(UseGraphQLSchema::class.':admin')
            ->all();

        Route::post('/admin/graphql', GraphQLController::class)
            ->middleware($middleware)
            ->name('graphql.admin');
    }
}
