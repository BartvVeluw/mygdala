<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\TestCase;

/**
 * `.env.example` is the file every new installation copies to `.env`, so
 * whatever it says becomes that installation's configuration on day one.
 *
 * It used to say this one: APP_URL, MAIL_FROM_ADDRESS, MAIL_FROM_NAME and
 * SHOP_NOTIFICATION_EMAIL all held Van Veluw Laserdesign's live values.
 * Copying the file was enough to make a brand-new site publish canonical
 * tags pointing at this domain and send its transactional mail as this
 * company — no mistake required, just following the instructions.
 *
 * This test is the cheap guard against that coming back. It reads the file
 * the way Dotenv does, key by key, rather than comparing the whole thing
 * against a snapshot: the comments in there are long, useful and expected to
 * change, and a snapshot test would fail on every one of those edits while
 * saying nothing about the values. Comments are ignored entirely, so an
 * explanation is free to mention setup concepts, hosting, or a domain in an
 * example line.
 */
final class ExampleEnvironmentTest extends TestCase
{
    /**
     * The keys whose value reaches the outside world: the URL a new site
     * publishes and the identity its mail is sent under.
     */
    private const IDENTITY_KEYS = [
        'APP_URL',
        'MAIL_FROM_ADDRESS',
        'MAIL_FROM_NAME',
        'SHOP_NOTIFICATION_EMAIL',
    ];

    /**
     * What must never be a VALUE in this file, matched case-insensitively.
     * The company's name and its domain — the two things that make a value
     * belong to this site rather than to whoever installed the software.
     */
    private const LIVE_IDENTITY = [
        'vanveluwlaserdesign',
        'van veluw',
        'vanveluw',
    ];

    /**
     * The one exception, and it is deliberate: the local development database
     * is still named after this site.
     *
     * It is not published anywhere — it is a MySQL schema name on a laptop
     * and in a Docker volume — and it is wired to a matching default in
     * docker-compose.yml, so renaming it in this file alone would leave a new
     * operator with a database the test containers cannot find. SETUP.md's
     * second-site checklist says to rename both together, which is where that
     * decision belongs. Everything a visitor, a crawler or a mail server can
     * see is covered above with no exceptions at all.
     */
    private const LOCAL_ONLY_KEYS = ['DB_DATABASE', 'TEST_DB_DATABASE'];

    /** @var array<string, string>|null */
    private static ?array $values = null;

    public function testTheFileExists(): void
    {
        $this->assertFileExists($this->path(), '.env.example is what a new installation copies; it must ship.');
    }

    public function testEveryIdentityKeyIsPresent(): void
    {
        $values = $this->values();

        foreach (self::IDENTITY_KEYS as $key) {
            $this->assertArrayHasKey(
                $key,
                $values,
                "{$key} is missing from .env.example. It may be empty, but leaving it out means a new "
                . 'operator never learns it exists.'
            );
        }
    }

    public function testNoIdentityKeyCarriesThisSitesLiveValue(): void
    {
        foreach ($this->values() as $key => $value) {
            if (!in_array($key, self::IDENTITY_KEYS, true)) {
                continue;
            }

            foreach (self::LIVE_IDENTITY as $needle) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $needle,
                    $value,
                    "{$key} in .env.example is set to this site's own value (\"{$value}\"). Every installation "
                    . 'copies this file, so that value becomes theirs. Leave it empty, or use an obvious placeholder.'
                );
            }
        }
    }

    public function testNoValueThatReachesTheOutsideWorldNamesThisSite(): void
    {
        // Wider than the four keys above, and still only values: an SMTP
        // host, a Turnstile key or a storage path with this domain in it
        // would be the same mistake in a different line.
        foreach ($this->values() as $key => $value) {
            if (in_array($key, self::LOCAL_ONLY_KEYS, true)) {
                continue;
            }

            foreach (self::LIVE_IDENTITY as $needle) {
                if (stripos($value, $needle) !== false) {
                    $this->fail("{$key} in .env.example names this site: \"{$value}\".");
                }
            }
        }

        $this->assertTrue(true);
    }

    public function testTheBaseUrlIsEmptyOrAnObviousPlaceholder(): void
    {
        $appUrl = trim($this->values()['APP_URL'] ?? '');

        if ($appUrl === '') {
            // App\Service\AppUrl then falls through to the stored setting and
            // finally to https://localhost, which is visibly a placeholder.
            $this->assertTrue(true);

            return;
        }

        $this->assertMatchesRegularExpression(
            '/(localhost|\.example($|[\/:])|example\.(com|org|net)|\.test($|[\/:])|\.invalid($|[\/:]))/i',
            $appUrl,
            'APP_URL in .env.example points at a real-looking domain. It should be empty, or a reserved '
            . 'placeholder domain, so no installation inherits somebody\'s production URL.'
        );
    }

    public function testTheAdminPasswordHashIsStillNotAUsableHash(): void
    {
        // Same family of mistake, already guarded in SETUP.md's prose: a
        // shipped example must never contain a hash anybody could log in with.
        $hash = trim($this->values()['ADMIN_PASSWORD_HASH'] ?? '');

        $this->assertNotSame('', $hash, 'ADMIN_PASSWORD_HASH must be present, as a placeholder.');
        $this->assertStringStartsNotWith('$', $hash, '.env.example must not contain a real bcrypt hash.');
    }

    // ------------------------------------------------------------- fixtures

    private function path(): string
    {
        return dirname(__DIR__, 2) . '/.env.example';
    }

    /**
     * The file's assignments, comments and blank lines dropped, values
     * unquoted — the same shape Dotenv would hand the application.
     *
     * @return array<string, string>
     */
    private function values(): array
    {
        if (self::$values !== null) {
            return self::$values;
        }

        $values = [];

        foreach (file($this->path(), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);

            // A commented-out example (`# MODULE_SHOP_ENABLED=true`) is
            // documentation, not configuration, and is skipped with every
            // other comment.
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if (strlen($value) >= 2) {
                $first = $value[0];
                if (($first === '"' || $first === "'") && str_ends_with($value, $first)) {
                    $value = substr($value, 1, -1);
                }
            }

            $values[$key] = $value;
        }

        return self::$values = $values;
    }
}
