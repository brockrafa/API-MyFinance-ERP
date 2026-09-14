<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

class CreateEmpresaCommand extends Command
{
    protected $signature = 'empresa:create
                            {--nome= : Nome da empresa}
                            {--cnpj= : CNPJ da empresa}
                            {--contrato= : Referência do contrato que autoriza a criação}
                            {--user-name= : Nome do usuário administrador (opcional; padrão: admin)}
                            {--email= : E-mail do usuário administrador (opcional; padrão: admin@nome-da-empresa)}
                            {--password= : Senha do usuário administrador}';

    protected $description = 'Cria uma nova empresa e um usuário administrador, exigindo a referência de contrato';

    public function handle(): int
    {
        $nome = $this->option('nome');
        $cnpj = $this->option('cnpj');
        $contrato = $this->option('contrato');
        $userName = $this->option('user-name') ?: 'admin';
        $password = $this->option('password');

        if (empty($nome) || empty($cnpj) || empty($contrato) || empty($password)) {
            $this->error('Os campos --nome, --cnpj, --contrato e --password são obrigatórios.');
            return self::FAILURE;
        }

        $email = $this->option('email') ?: 'admin@' . $this->normalizeEmailDomain($nome) . '.com';

        if (Empresa::where('cnpj', $cnpj)->exists()) {
            $this->error("Já existe uma empresa cadastrada com o CNPJ {$cnpj}.");
            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->error("Já existe um usuário cadastrado com o e-mail {$email}.");
            return self::FAILURE;
        }

        $this->info('Criando empresa e usuário administrador...');

        $empresa = Empresa::create([
            'nome' => $nome,
            'cnpj' => $cnpj,
            'contrato_ref' => $contrato,
            'ativo' => true,
        ]);

        $user = User::create([
            'empresa_id' => $empresa->id,
            'name' => $userName,
            'email' => $email,
            'password' => Hash::make($password),
            'ativo' => true,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($empresa->id);

        $user->assignRole('admin');

        $this->info("Empresa criada com sucesso. ID: {$empresa->id} | Contrato: {$empresa->contrato_ref}");
        $this->info("Usuário administrador criado: {$user->email}");

        return self::SUCCESS;
    }

    private function normalizeEmailDomain(string $nome): string
    {
        $normalizado = mb_strtolower(trim($nome));
        $normalizado = preg_replace('/[^a-z0-9]+/', '', $normalizado) ?? $normalizado;

        return $normalizado;
    }
}
