<?php
require 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = \App\Models\User::first();
if ($user) {
    $token = $user->createToken('API')->plainTextToken;
    echo "Token: " . $token . "\n";
} else {
    echo "No users found\n";
}
