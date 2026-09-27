<?php

/**
 * Test support, never part of the application: prepended by PHP's built-in
 * server (`-d auto_prepend_file=tests/Support/fake-mollie.php`) so every
 * Mollie client that server builds is Tests\Support\FakeMollie, reading the
 * scenario file FAKE_MOLLIE_SCENARIO names. Without that variable it does
 * nothing at all.
 *
 * It exists for the HTTP tests of Shop → Betalingen, the webhook and the
 * checkout, and for the browser harness: nothing in them may reach
 * api.mollie.com.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$fakeMollieScenario = getenv('FAKE_MOLLIE_SCENARIO');

if (is_string($fakeMollieScenario) && $fakeMollieScenario !== '') {
    \Tests\Support\FakeMollie::install($fakeMollieScenario);
}

unset($fakeMollieScenario);
