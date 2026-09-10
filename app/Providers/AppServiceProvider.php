<?php

namespace App\Providers;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\ServiceProvider;
use App\Models\Empresa;
use App\Observers\EmpresaObserver;

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
        Empresa::observe(EmpresaObserver::class);

        // A API não envelopa respostas em `{"data": ...}` (ver ProdutoController,
        // ClienteController etc.), então os JsonResource de estoque seguem o mesmo padrão.
        JsonResource::withoutWrapping();
    }
}
