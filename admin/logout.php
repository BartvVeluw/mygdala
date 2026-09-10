<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;

AdminAuth::start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validate($_POST['csrf_token'] ?? null)) {
    header('Location: /admin/index.php');
    exit;
}

AdminAuth::logout();
header('Location: /admin/login.php');
exit;
