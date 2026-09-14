<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\ProdutoController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\ServicoController;
use App\Http\Controllers\VendaController;
use App\Http\Controllers\FormaPagamentoController;
use App\Http\Controllers\LancamentoFinanceiroReceberController;
use App\Http\Controllers\LancamentoFinanceiroPagarController;
use App\Http\Controllers\ContaPagarController;
use App\Http\Controllers\DespesaRecorrenteController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\EmpresaController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\AgendaController;
use App\Http\Controllers\ProfissionalController;
use App\Http\Controllers\EstoqueController;
use App\Http\Controllers\EntradaEstoqueController;
use App\Http\Controllers\TransferenciaEstoqueController;
use App\Http\Controllers\BaixaEstoqueController;

Route::middleware('auth:sanctum')->group(function () {

    // ── Roles e Permissões ────────────────────────
    Route::get('/roles', [RoleController::class, 'index']);
    Route::get('/roles/{role}', [RoleController::class, 'show']);

    // ── Clientes ──────────────────────────────────
    Route::resource('clientes', ClienteController::class)
        ->only(['index', 'show'])
        ->middleware('permission:cadastros.clientes.view');

    Route::resource('clientes', ClienteController::class)
        ->only(['store'])
        ->middleware('permission:cadastros.clientes.create');

    Route::resource('clientes', ClienteController::class)
        ->only(['update'])
        ->middleware('permission:cadastros.clientes.edit');
        
    Route::resource('clientes', ClienteController::class)
        ->only(['destroy'])
        ->middleware('permission:cadastros.clientes.delete');

    // ── Produtos ──────────────────────────────────
    Route::resource('produtos', ProdutoController::class)
        ->only(['index', 'show'])
        ->middleware('permission:cadastros.produtos.view');

    Route::resource('produtos', ProdutoController::class)
        ->only(['store'])
        ->middleware('permission:cadastros.produtos.create');

    Route::resource('produtos', ProdutoController::class)
        ->only(['update'])
        ->middleware('permission:cadastros.produtos.edit');

    Route::resource('produtos', ProdutoController::class)
        ->only(['destroy'])
        ->middleware('permission:cadastros.produtos.delete');

    // ── Estoques ──────────────────────────────────
    Route::resource('estoques', EstoqueController::class)
        ->only(['index', 'show'])
        ->middleware('permission:estoque.view');

    Route::get('/estoques/{estoque}/dashboard', [EstoqueController::class, 'dashboard'])
        ->middleware('permission:estoque.view');

    Route::post('/estoques/{estoque}/validar-saldos', [EstoqueController::class, 'validarSaldos'])
        ->middleware('permission:estoque.view');

    Route::get('/estoques/{estoque}/movimentos', [EstoqueController::class, 'movimentos'])
        ->middleware('permission:estoque.view');

    Route::delete('/estoques/movimentos/{movimento}', [EstoqueController::class, 'destroyMovimento'])
        ->middleware('permission:estoque.delete');

    Route::resource('estoques', EstoqueController::class)
        ->only(['store'])
        ->middleware('permission:estoque.create');

    Route::resource('estoques', EstoqueController::class)
        ->only(['update'])
        ->middleware('permission:estoque.edit');

    Route::resource('estoques', EstoqueController::class)
        ->only(['destroy'])
        ->middleware('permission:estoque.delete');

    Route::post('/estoques/{estoque}/entradas', [EntradaEstoqueController::class, 'store'])
        ->middleware('permission:estoque.create');

    Route::post('/estoques/{estoque}/baixas', [BaixaEstoqueController::class, 'store'])
        ->middleware('permission:estoque.edit');

    Route::post('/transferencias-estoque', [TransferenciaEstoqueController::class, 'store'])
        ->middleware('permission:estoque.edit');

    // ── Categorias ────────────────────────────────
    Route::resource('categorias', CategoriaController::class)
        ->only(['index', 'show'])
        ->middleware('permission:cadastros.categorias.view');

    Route::resource('categorias', CategoriaController::class)
        ->only(['store'])
        ->middleware('permission:cadastros.categorias.create');

    Route::resource('categorias', CategoriaController::class)
        ->only(['update'])
        ->middleware('permission:cadastros.categorias.edit');

    Route::resource('categorias', CategoriaController::class)
        ->only(['destroy'])
        ->middleware('permission:cadastros.categorias.delete');

    // ── Serviços ──────────────────────────────────
    Route::resource('servicos', ServicoController::class)
        ->only(['index', 'show'])
        ->middleware('permission:cadastros.servicos.view');

    Route::resource('servicos', ServicoController::class)
        ->only(['store'])
        ->middleware('permission:cadastros.servicos.create');

    Route::resource('servicos', ServicoController::class)
        ->only(['update'])
        ->middleware('permission:cadastros.servicos.edit');

    Route::resource('servicos', ServicoController::class)
        ->only(['destroy'])
        ->middleware('permission:cadastros.servicos.delete');

    // ── Vendas ────────────────────────────────────
    Route::resource('vendas', VendaController::class)
        ->only(['index', 'show'])
        ->middleware('permission:vendas.view');

    Route::resource('vendas', VendaController::class)
        ->only(['store'])
        ->middleware('permission:vendas.create');

    Route::resource('vendas', VendaController::class)
        ->only(['update'])
        ->middleware('permission:vendas.edit');

    Route::resource('vendas', VendaController::class)
        ->only(['destroy'])
        ->middleware('permission:vendas.delete');

    // ── Agenda ────────────────────────────────────
    Route::resource('agendas', AgendaController::class)
        ->only(['index', 'show'])
        ->middleware('permission:agenda.view');

    Route::resource('agendas', AgendaController::class)
        ->only(['store'])
        ->middleware('permission:agenda.create');

    Route::resource('agendas', AgendaController::class)
        ->only(['update'])
        ->middleware('permission:agenda.edit');

    Route::resource('agendas', AgendaController::class)
        ->only(['destroy'])
        ->middleware('permission:agenda.delete');

    // ── Profissionais ─────────────────────────────
    Route::resource('profissionais', ProfissionalController::class)
        ->only(['index', 'show'])
        ->middleware('permission:cadastros.profissionais.view');

    Route::resource('profissionais', ProfissionalController::class)
        ->only(['store'])
        ->middleware('permission:cadastros.profissionais.create');

    Route::resource('profissionais', ProfissionalController::class)
        ->only(['update'])
        ->middleware('permission:cadastros.profissionais.edit');

    Route::resource('profissionais', ProfissionalController::class)
        ->only(['destroy'])
        ->middleware('permission:cadastros.profissionais.delete');

    // ── Forma de Pagamento ────────────────────────
    Route::resource('forma-pagamento', FormaPagamentoController::class)
        ->only(['index', 'show'])
        ->middleware('permission:cadastros.formas-pagamento.view');

    Route::resource('forma-pagamento', FormaPagamentoController::class)
        ->only(['store'])
        ->middleware('permission:cadastros.formas-pagamento.create');

    Route::resource('forma-pagamento', FormaPagamentoController::class)
        ->only(['update'])
        ->middleware('permission:cadastros.formas-pagamento.edit');

    Route::resource('forma-pagamento', FormaPagamentoController::class)
        ->only(['destroy'])
        ->middleware('permission:cadastros.formas-pagamento.delete');

    // ── Contas a Receber (Lançamentos Financeiros) ─
    Route::resource('lancamentos-financeiros', LancamentoFinanceiroReceberController::class)
        ->only(['index', 'show'])
        ->middleware('permission:financeiro.lancamentos.view');

    Route::resource('lancamentos-financeiros', LancamentoFinanceiroReceberController::class)
        ->only(['store'])
        ->middleware('permission:financeiro.lancamentos.create');

    Route::resource('lancamentos-financeiros', LancamentoFinanceiroReceberController::class)
        ->only(['update'])
        ->middleware('permission:financeiro.lancamentos.edit');
        
    Route::resource('lancamentos-financeiros', LancamentoFinanceiroReceberController::class)
        ->only(['destroy'])
        ->middleware('permission:financeiro.lancamentos.delete');

    Route::patch('/lancamentos-financeiros/{id}/baixar', [LancamentoFinanceiroReceberController::class, 'baixar'])
        ->middleware('permission:financeiro.lancamentos.baixar');

    Route::patch('/lancamentos-financeiros/{id}/baixar-com-valor', [LancamentoFinanceiroReceberController::class, 'baixarComValor'])
        ->middleware('permission:financeiro.lancamentos.baixar');

    Route::get('/lancamentos-financeiros/{id}/restante-parcelas', [LancamentoFinanceiroReceberController::class, 'buscarRestanteParcelas'])
        ->middleware('permission:financeiro.lancamentos.baixar');

    // ── Contas a Pagar ────────────────────────────
    Route::get('/lancamentos/contas-pagar', [LancamentoFinanceiroPagarController::class, 'index'])
        ->middleware('permission:financeiro.contas-pagar.view');

    Route::post('/lancamentos-financeiros/lancar-recorrentes-pagar', [LancamentoFinanceiroPagarController::class, 'lancarRecorrentes'])
        ->middleware('permission:financeiro.contas-pagar.create');

    Route::put('/contas-pagar/{id}', [LancamentoFinanceiroPagarController::class, 'update'])
        ->middleware('permission:financeiro.contas-pagar.edit');

    Route::patch('/contas-pagar/{id}/pagar', [LancamentoFinanceiroPagarController::class, 'pagar'])
        ->middleware('permission:financeiro.contas-pagar.edit');

    Route::delete('/contas-pagar/{id}', [LancamentoFinanceiroPagarController::class, 'destroy'])
        ->middleware('permission:financeiro.contas-pagar.delete');

    // ── Despesas Recorrentes ──────────────────────
    Route::get('/despesas-recorrentes', [DespesaRecorrenteController::class, 'index'])
        ->middleware('permission:financeiro.despesas-recorrentes.view');

    Route::get('/despesas-recorrentes/{id}', [DespesaRecorrenteController::class, 'show'])
        ->middleware('permission:financeiro.despesas-recorrentes.view');

    Route::post('/despesas-recorrentes', [DespesaRecorrenteController::class, 'store'])
        ->middleware('permission:financeiro.despesas-recorrentes.create');

    Route::put('/despesas-recorrentes/{id}', [DespesaRecorrenteController::class, 'update'])
        ->middleware('permission:financeiro.despesas-recorrentes.edit');

    Route::delete('/despesas-recorrentes/{id}', [DespesaRecorrenteController::class, 'destroy'])
        ->middleware('permission:financeiro.despesas-recorrentes.delete');

    // ── Autenticação e Usuários ───────────────────
    Route::post('/usuarios', [AuthController::class, 'criarUsuario'])->middleware('permission:cadastros.usuarios.create');
    Route::get('/usuarios', [AuthController::class, 'index'])->middleware('permission:cadastros.usuarios.view');
    Route::get("/usuarios/{id}", [AuthController::class, 'editarUsuario'])->middleware('permission:cadastros.usuarios.edit');
    Route::put("/usuarios/{id}", [AuthController::class, 'atualizarUsuario'])->middleware('permission:cadastros.usuarios.edit');
    Route::delete("/usuarios/{id}", [AuthController::class, 'deletarUsuario'])->middleware('permission:cadastros.usuarios.delete');
    
    // ── Empresa (dados do tenant logado) ──────────
    Route::get('/empresa', [EmpresaController::class, 'show'])->middleware('permission:configuracoes.empresa.edit');
    Route::put('/empresa', [EmpresaController::class, 'update'])->middleware('permission:configuracoes.empresa.edit');

    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/me', [AuthController::class, 'me']);

    
    
    Route::post('/refresh', [AuthController::class, 'refreshToken']);
});


// Rotas públicas
Route::post('/login', [AuthController::class, 'login']);
Route::post('/registrar', [AuthController::class, 'registrarEmpresa']);
