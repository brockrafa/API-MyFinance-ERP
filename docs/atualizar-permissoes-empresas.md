# Como atualizar permissões de empresas existentes

Sempre que uma nova permissão é adicionada ao sistema (ex.: o bloco `estoque.*` criado em 2026-09-12), empresas **novas** já recebem tudo automaticamente — quem cuida disso é o `EmpresaObserver`, disparado no momento da criação da empresa. Empresas que **já existiam antes** da mudança não são atualizadas sozinhas: é preciso rodar um comando manualmente para sincronizar as roles delas.

Este documento explica como fazer isso.

## Quando rodar

Rode este procedimento sempre que:
- Uma nova permissão for adicionada em `app/Support/RolePermissionDefinitions.php`.
- Uma role padrão (`admin`, `gestor`, `usuario`, `vendedor`, `financeiro`) ganhar ou perder permissões nessa mesma classe.
- Você perceber que usuários de uma empresa antiga não estão vendo um menu/tela que deveriam ver, mesmo com a role correta.

Não é necessário rodar nada para empresas **criadas depois** da alteração no código — elas já nascem corretas.

## Onde está a lógica

- **Fonte da verdade das permissões por role**: [app/Support/RolePermissionDefinitions.php](../app/Support/RolePermissionDefinitions.php) — é aqui que se define quais permissões cada role (`admin`, `gestor`, `usuario`, `vendedor`, `financeiro`) possui. `admin` sempre usa `'*'`, ou seja, recebe automaticamente **todas** as permissões cadastradas no sistema.
- **Aplicado a empresas novas**: [app/Observers/EmpresaObserver.php](../app/Observers/EmpresaObserver.php) — roda no evento `created` da model `Empresa`.
- **Aplicado a empresas existentes**: [app/Console/Commands/UpdateEmpresaRoles.php](../app/Console/Commands/UpdateEmpresaRoles.php) — comando Artisan `empresa:update-roles`, que é o que você vai usar manualmente.

> Nota: `database/seeders/PermissionSeeder.php` cria as permissões "cruas" (`Permission::firstOrCreate`) e o usuário/empresa demo, mas **não** atribui permissões a roles. Rodar o seeder sozinho não resolve o problema de empresas antigas — é preciso o comando abaixo.

## Passo a passo

### 1. Descobrir o ID da empresa

```bash
php artisan tinker --execute="print_r(DB::table('empresas')->pluck('nome','id')->toArray());"
```

Isso lista todas as empresas cadastradas com seus IDs, por exemplo:

```
Array
(
    [1] => Brock Solution
)
```

### 2. Simular antes de aplicar (opcional, mas recomendado)

O comando não tem um modo "dry-run" nativo, mas se quiser conferir o que vai acontecer antes, basta ler as definições atuais:

```bash
php artisan tinker --execute="print_r(App\Support\RolePermissionDefinitions::all());"
```

Isso mostra exatamente quais permissões cada role vai ficar tendo depois de rodar o comando.

### 3. Rodar o comando para UMA empresa

```bash
php artisan empresa:update-roles {id_da_empresa}
```

Exemplo real (empresa "Brock Solution", id 1):

```bash
php artisan empresa:update-roles 1
```

Saída esperada:

```
Atualizando empresa #1 (Brock Solution)...
  ✓ Role 'admin' atualizada -> 50 permissões
  ✓ Role 'gestor' atualizada -> 45 permissões
  ✓ Role 'usuario' atualizada -> 9 permissões
  ✓ Role 'vendedor' atualizada -> 16 permissões
  ✓ Role 'financeiro' atualizada -> 2 permissões
Empresa atualizada com sucesso.
```

O comando:
1. Garante que todas as permissões definidas em `RolePermissionDefinitions` existem na tabela `permissions` (cria as que faltarem).
2. Para cada role padrão, cria a role na empresa se ela ainda não existir (`firstOrCreate`).
3. Sincroniza (`syncPermissions`) as permissões da role com o que está definido no código.
4. Limpa o cache de permissões do Spatie ao final.

### 4. Rodar para várias empresas de uma vez

Não existe um comando `--all` pronto. Para aplicar em todas as empresas cadastradas, rode um laço simples:

```bash
php artisan tinker --execute="
foreach (App\Models\Empresa::pluck('id') as \$id) {
    Artisan::call('empresa:update-roles', ['empresa_id' => \$id]);
    echo Artisan::output();
}
"
```

Ou, direto no shell (Git Bash / Linux):

```bash
for id in $(php artisan tinker --execute="echo App\Models\Empresa::pluck('id')->implode(' ');"); do
  php artisan empresa:update-roles "$id"
done
```

### 5. Atualizar só uma role específica (opcional)

Se você só quer sincronizar uma role (por exemplo, só `vendedor`), use `--role`:

```bash
php artisan empresa:update-roles 1 --role=vendedor
```

Isso evita mexer nas outras roles da mesma empresa.

## Avisos importantes

- **`syncPermissions` substitui, não soma.** Se alguém deu uma permissão extra a uma role manualmente (fora do que está em `RolePermissionDefinitions.php`), rodar este comando **remove** essa permissão extra, porque a role passa a ficar exatamente igual à definição do código. Se uma empresa tem customizações de permissão fora do padrão, elas não devem ser geridas por roles padrão, ou é preciso primeiro atualizar `RolePermissionDefinitions.php` para refletir essa customização antes de rodar o comando.
- **O comando não apaga roles nem revoga acesso de usuários.** Ele só sincroniza quais permissões cada role tem. Um usuário que já tinha a role `admin` continua com ela; o que muda é o conjunto de permissões que essa role concede.
- **Rodar em produção é seguro e idempotente.** Rodar o comando várias vezes seguidas não causa problema — ele sempre deixa o estado final igual ao que está no código.
- **Depois de rodar, é uma boa prática pedir para o usuário fazer logout/login** (ou você pode forçar um `logout` de sessões antigas), pois o front-end guarda a lista de permissões do usuário no momento do login.

## Checklist resumido

1. `git pull` / deploy do código com a nova permissão em `RolePermissionDefinitions.php`.
2. `php artisan migrate --force` (se a mudança também envolveu migration).
3. `php artisan tinker --execute="print_r(DB::table('empresas')->pluck('nome','id')->toArray());"` para listar empresas.
4. `php artisan empresa:update-roles {id}` para cada empresa existente (ou o laço da seção 4 para todas de uma vez).
5. Pedir para os usuários afetados relogarem.
