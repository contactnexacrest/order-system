<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\UserRepository;
use App\Services\AuthService;

/** docs/schema.sql Section AI — self-service staff account page (email signature today; a natural home for other self-service settings later). */
final class AccountController
{
    public function edit(array $params): void
    {
        $user = UserRepository::findById((int) AuthService::currentUser()['id']);
        View::render('account/edit', ['user' => $user], 'layout/base');
    }

    public function updateSignature(array $params): void
    {
        $userId = (int) AuthService::currentUser()['id'];
        $signature = trim((string) ($_POST['email_signature'] ?? ''));
        UserRepository::updateSignature($userId, $signature !== '' ? $signature : null);
        Flash::set('success', 'Signature saved — it will be used on every email you send through the system from now on.');
        header('Location: /account');
    }
}
