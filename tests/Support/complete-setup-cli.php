<?php

/**
 * Test support, never part of the application: runs the Setup Wizard against
 * whatever database this PROCESS is pointed at, and prints the result as
 * JSON.
 *
 * It exists because App\Database holds one static connection for the whole
 * process. Tests\Support\ScratchInstall therefore runs anything that must
 * talk to a throwaway database in its own process (see its docblock) — and
 * "what does finishing the wizard actually build" is a question only a
 * from-zero database can answer, since the ordinary test database is a copy
 * of development and already contains most of the answer.
 *
 * Usage, through ScratchInstall::runScript():
 *
 *     runScript('tests/Support/complete-setup-cli.php', [json_encode($input)])
 *
 * The single argument is the wizard submission, exactly as
 * api/admin/complete-setup.php would receive it in $_POST. Nothing here
 * validates, guards or decides anything of its own: that is the point — the
 * test exercises App\Install\SetupWizard, not a second implementation of it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Install\SetupWizard;

$raw = $argv[1] ?? '{}';
$input = json_decode($raw, true);

if (!is_array($input)) {
    echo json_encode(['ok' => false, 'error' => 'The submission must be a JSON object.']);
    exit(1);
}

try {
    $created = SetupWizard::complete($input);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit(1);
}

echo json_encode(['ok' => true, 'created' => $created]);
exit(0);
