<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

trait ListaPaginavel
{
    /**
     * Lista os registros paginados quando a requisição traz `page` ou `per_page`
     * e a coleção completa caso contrário (selects e telas antigas continuam funcionando).
     * A resposta paginada segue o formato do Laravel: { data, current_page, last_page, per_page, total }.
     *
     * @param callable|null $depois recebe cada registro já carregado, para acrescentar campos calculados
     */
    protected function listar(Request $request, Builder $consulta, ?callable $depois = null): JsonResponse
    {
        if ($request->has('page') || $request->has('per_page')) {
            $porPagina = max(1, min(100, $request->integer('per_page', 15)));
            $pagina = $consulta->paginate($porPagina);

            if ($depois) {
                $pagina->getCollection()->each($depois);
            }

            return response()->json($pagina, Response::HTTP_OK);
        }

        $registros = $consulta->get();

        if ($depois) {
            $registros->each($depois);
        }

        return response()->json($registros, Response::HTTP_OK);
    }

    /** Aplica busca textual (LIKE) em várias colunas com o termo de `busca`. */
    protected function aplicarBusca(Builder $consulta, Request $request, array $colunas): Builder
    {
        $termo = trim((string) $request->query('busca', ''));

        if ($termo === '') {
            return $consulta;
        }

        $termo = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $termo) . '%';

        return $consulta->where(function (Builder $q) use ($colunas, $termo) {
            foreach ($colunas as $coluna) {
                $q->orWhere($coluna, 'like', $termo);
            }
        });
    }
}
