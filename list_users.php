<?php
require 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$users = \App\Models\User::all();
echo "Usuários na base de dados:\n";
foreach ($users as $user) {
    echo "Email: " . $user->email . " | ID: " . $user->id . "\n";
}
