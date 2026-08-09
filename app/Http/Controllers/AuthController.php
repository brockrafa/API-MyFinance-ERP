<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class AuthController extends Controller
{
    public function index(Request $request)
    {
        $empresaId = $request->user()->empresa_id;
        $users = User::where('empresa_id', $empresaId)->with('roles', 'permissions')->get();
        return response()->json($users);
    }

    // Cadastro de uma nova empresa + primeiro usuário (admin)
    // Este fluxo agora é controlado exclusivamente via comando artisan, não mais pela API.
    public function registrarEmpresa(Request $request)
    {
        return response()->json([
            'message' => 'A criação de empresas foi desativada para a API. Use o comando artisan empresa:create com a referência do contrato.',
        ], 403);
    }

    // Login
    public function login(Request $request)
    {
        $validado = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $validado['email'])->first();

        if (! $user || ! Hash::check($validado['password'], $user->password)) {
            return response()->json(['message' => 'Credenciais inválidas.'], 401);
        }

        $expiracaoMinutos = (int) env('SANCTUM_EXPIRATION', 60);
        $token = $user->createToken('auth_token',['*'],now()->addMinutes($expiracaoMinutos))->plainTextToken;

        app(PermissionRegistrar::class)->setPermissionsTeamId($user->empresa_id);

        return response()->json(['token' => $token,
        'user' => $user,
        'expires_at' => now()->addMinutes(config('sanctum.expiration', 60))->timestamp * 1000,
        'roles' => $user->getRoleNames(),
        'permissions' => $user->getAllPermissions()->pluck('name'),]);
    }

    public function refreshToken(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Não autenticado.'], 401);
        }

        $tokenAtual = $user->currentAccessToken();

        if (! $tokenAtual) {
            return response()->json(['message' => 'Token atual não encontrado.'], 401);
        }

        $expiracaoMinutos = (int) env('SANCTUM_EXPIRATION', 60);

        $novoToken = $user->createToken(
            'auth_token',
            ['*'],
            now()->addMinutes($expiracaoMinutos)
        )->plainTextToken;

        $tokenAtual->delete();

        app(PermissionRegistrar::class)->setPermissionsTeamId($user->empresa_id);

        return response()->json([
            'token' => $novoToken,
            'expires_at' => now()->addMinutes(config('sanctum.expiration', 60))->timestamp * 1000,
            'user' => $user,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ]);
    }

    // Dados do usuário logado (usado ao recarregar a SPA)
    public function me(Request $request)
    {
        $user = $request->user();
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->empresa_id);

        return response()->json([
            'user' => $user,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ]);
    }

    // Cadastro de novo usuário dentro de uma empresa já existente
    public function criarUsuario(Request $request)
    {
        $validado = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'role' => 'nullable|string|exists:roles,name', // Nullable permite o perfil "Personalizado"
            'ativo' => 'sometimes|boolean',
            'permissions' => 'sometimes|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        $empresaId = $request->user()->empresa_id;

        // Verificar se o usuário logado é admin para criar outro admin
        if(!empty($validado['role']) && $validado['role'] === 'admin' && !$request->user()->hasRole('admin')) {
            return response()->json(['message' => 'Apenas administradores podem criar novos usuários admins.'], 403);
        }

        $user = User::create([
            'empresa_id' => $empresaId,
            'name' => $validado['name'],
            'email' => $validado['email'],
            'password' => Hash::make($validado['password']),
            'ativo' => $validado['ativo'] ?? true,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($empresaId);

        // Atribui uma role base para todos os usuários. Se nenhuma role vier, usa 'usuario'.
        $roleParaAtribuir = !empty($validado['role']) ? $validado['role'] : 'usuario';
        $user->assignRole($roleParaAtribuir);

        // Sincronizar permissões customizadas diretas (mesmo se vier vazio, é seguro)
        if (isset($validado['permissions'])) {
            $user->syncPermissions($validado['permissions']); // O Spatie já aceita o array de nomes direto
        }

        return response()->json([
            'message' => 'Usuário criado com sucesso.',
            'user' => $user,
        ], 201);
    }

    public function atualizarUsuario(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $editandoSiMesmo = $user->id === $request->user()->id;

        if (!$editandoSiMesmo) {
            if ($user->empresa_id !== $request->user()->empresa_id || !$request->user()->can('cadastros.usuarios.edit')) {
                return response()->json(['message' => 'Você não tem permissão para atualizar este usuário.'], 403);
            }
        }

        $validado = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|unique:users,email,' . $user->id,
            'role' => 'nullable|string|exists:roles,name', // Permite trocar para nulo/vazio
            'ativo' => 'sometimes|boolean',
            //'permissions' => 'sometimes|array',
            //'permissions.*' => 'string|exists:permissions,name',
        ]);

        if (isset($validado['name'])) {
            $user->name = $validado['name'];
        }
        if (isset($validado['email'])) {
            $user->email = $validado['email'];
        }
        if (isset($validado['ativo'])) {
            $user->ativo = $validado['ativo'];
        }
        
        $user->save();

        app(PermissionRegistrar::class)->setPermissionsTeamId($user->empresa_id);

        // Gerenciamento de Roles e Permissões (Apenas quem tem autorização pode mudar acessos)
        if ($request->user()->can('cadastros.usuarios.edit') || $request->user()->hasRole(['admin', 'gestor'])) {
            
            // Se a chave 'role' veio na requisição...
            if (array_key_exists('role', $validado)) {
                $roleParaAtribuir = !empty($validado['role']) ? $validado['role'] : 'usuario';

                // Previne que alguém escale para admin sem ser admin
                if($roleParaAtribuir === 'admin' && !$request->user()->hasRole('admin')) {
                    return response()->json(['message' => 'Apenas admins podem promover alguém a admin.'], 403);
                }

                $user->syncRoles([$roleParaAtribuir]);
            }

            // Sincronizar permissões customizadas (seja adicionando ou limpando o array)
            if (array_key_exists('permissions', $validado)) {
                $user->syncPermissions($validado['permissions']);
            }
        }

        return response()->json([
            'message' => 'Usuário atualizado com sucesso.',
            'user' => $user,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ], 200);
    }

    public function editarUsuario(Request $request, $id)
    {
        $user = User::findOrFail($id);

        if ($user->empresa_id !== $request->user()->empresa_id) {
            return response()->json(['message' => 'Você não tem permissão para visualizar este usuário.'], 403);
        }
        
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->empresa_id);
        
        return response()->json([
            'user' => $user,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getDirectPermissions()->pluck('name'), // Melhor usar getDirectPermissions aqui para não misturar com as da Role no frontend
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logout realizado.']);
    }

    public function deletarUsuario(Request $request, $id)
    {
        $user = User::findOrFail($id);

        if ($user->empresa_id !== $request->user()->empresa_id || !$request->user()->can('cadastros.usuarios.delete')) {
            return response()->json(['message' => 'Você não tem permissão para deletar este usuário.'], 403);
        }

        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'Você não pode deletar a si mesmo.'], 400);
        }

        $user->delete();
        return response()->json(['message' => 'Usuário deletado com sucesso.']);
    }
}