<?php

declare(strict_types=1);

namespace Tests\Repository;

use PHPUnit\Framework\TestCase;

/**
 * An order's public number is made once, when the order is created, and read
 * from `orders.order_number` everywhere after that.
 *
 * The defect this holds off: every screen, e-mail, export and invoice used to
 * rebuild the number from the order's id, its year and the CURRENT
 * `order_number_prefix`. That setting is the owner's to change, so changing
 * it renamed every order that already existed, in the admin, the export, a
 * resent e-mail and a regenerated invoice, while the customer's inbox, their
 * bank statement and Mollie kept the number they were given
 * (db/migrations/20260913120000_snapshot_the_order_number_on_every_order.php).
 *
 * Source contracts, no database. The behaviour is proven by
 * Tests\Repository\OrderNumberSnapshotIntegrationTest and by each reader's
 * own tests; what this adds is that no new reader can quietly go back to
 * formatting a number, or to reading the prefix.
 */
final class OrderNumberSnapshotContractTest extends TestCase
{
    /** The shipped application, as opposed to its history (db/) and its tests. */
    private const RUNTIME_DIRECTORIES = ['src', 'partials', 'api', 'admin'];

    /** The one method that makes a number, inside OrderRepository::create(). */
    private const THE_MAKER = 'src/Repository/OrderRepository.php::assignOrderNumber';

    /**
     * Every file that may name the prefix setting at all: its default, the
     * settings screen and its endpoint, and the repository that creates orders.
     */
    private const PREFIX_SETTING_OWNERS = [
        'admin/shop-settings.php',
        'src/Service/ShopSettings.php',
        'src/Repository/OrderRepository.php',
        'src/Service/SiteSettings.php',
    ];

    /**
     * Inside OrderRepository: the maker reads the setting, and the formatter's
     * fallback names only its generic default.
     */
    private const PREFIX_SETTING_READERS_IN_THE_REPOSITORY = ['assignOrderNumber', 'orderNumberPrefix'];

    public function testOnlyOrderCreationFormatsAnOrderNumber(): void
    {
        $callers = [];

        foreach ($this->runtimeFiles() as $path => $contents) {
            foreach ($this->functionsCalling('formatOrderNumber', $contents) as $function) {
                $callers[] = $path . '::' . $function;
            }
        }

        $this->assertSame(
            [self::THE_MAKER],
            array_values(array_unique($callers)),
            'An existing order shows the number stored on it (OrderRepository::orderNumber()). '
            . 'Only OrderRepository::create() makes one.'
        );
    }

    public function testNothingButItsOwnersNamesThePrefixSetting(): void
    {
        $offenders = [];
        $repositoryReaders = [];

        foreach ($this->runtimeFiles() as $path => $contents) {
            foreach ($this->functionsNaming('order_number_prefix', $contents) as $function) {
                if (!in_array($path, self::PREFIX_SETTING_OWNERS, true)) {
                    $offenders[] = $path . '::' . $function;
                } elseif ($path === 'src/Repository/OrderRepository.php') {
                    $repositoryReaders[] = $function;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Only order creation reads order_number_prefix; a reader that needs a number asks OrderRepository::orderNumber().'
        );

        $this->assertSame(
            [],
            array_values(array_diff(array_unique($repositoryReaders), self::PREFIX_SETTING_READERS_IN_THE_REPOSITORY)),
            'Inside OrderRepository only the method that makes a new number may read the prefix setting.'
        );
    }

    public function testEveryPlaceThatShowsAnOrderNumberReadsTheStoredOne(): void
    {
        foreach ([
            'admin/orders.php' => 1,
            'admin/order.php' => 1,
            'admin/_dashboard_shop.php' => 1,
            'api/order-status.php' => 1,
            'src/Mail/OrderConfirmationBuilder.php' => 1,
            'src/Service/OrderCsvExport.php' => 1,
            // The first render of an invoice and the regeneration of a missing PDF.
            'src/Service/InvoiceService.php' => 2,
            'src/Service/MolliePaymentData.php' => 1,
        ] as $file => $reads) {
            $this->assertSame(
                $reads,
                substr_count($this->code($file), 'OrderRepository::orderNumber('),
                $file . ' shows an order number, so it must read the stored one through OrderRepository::orderNumber().'
            );
        }

        // The Mollie payment is built from the stored order, not assembled in the endpoint.
        $checkout = $this->code('api/checkout.php');
        $this->assertStringContainsString('MolliePaymentData::forOrder(', $checkout);
        $this->assertStringNotContainsString("'order_number'", $checkout);
    }

    /* ------------------------------------------------------------------ */

    /**
     * The named functions in $contents that call $name(...). A call outside
     * any function is reported as "(file scope)".
     *
     * @return list<string>
     */
    private function functionsCalling(string $name, string $contents): array
    {
        $found = [];

        foreach ($this->significantTokens($contents) as $index => [$token, $function, $previous, $next]) {
            if (is_array($token) && $token[0] === T_STRING && $token[1] === $name && $next === '(' && $previous !== T_FUNCTION) {
                $found[] = $function;
            }
        }

        return $found;
    }

    /**
     * The named functions in $contents holding a string literal that is
     * exactly $literal.
     *
     * @return list<string>
     */
    private function functionsNaming(string $literal, string $contents): array
    {
        $found = [];

        foreach ($this->significantTokens($contents) as [$token, $function]) {
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && trim($token[1], '\'"') === $literal) {
                $found[] = $function;
            }
        }

        return $found;
    }

    /**
     * Every token that is not whitespace or a comment, with the name of the
     * function it sits in, the id (or text) of the token before it and the
     * text of the token after it.
     *
     * @return list<array{0: array|string, 1: string, 2: int|string|null, 3: string|null}>
     */
    private function significantTokens(string $contents): array
    {
        $tokens = array_values(array_filter(
            token_get_all($contents),
            static fn ($token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));

        $result = [];
        $function = '(file scope)';

        foreach ($tokens as $index => $token) {
            $previous = $tokens[$index - 1] ?? null;
            $next = $tokens[$index + 1] ?? null;

            if (is_array($previous) && $previous[0] === T_FUNCTION && is_array($token) && $token[0] === T_STRING) {
                $function = $token[1];
            }

            $result[] = [
                $token,
                $function,
                is_array($previous) ? $previous[0] : $previous,
                is_array($next) ? $next[1] : $next,
            ];
        }

        return $result;
    }

    /** $file's PHP without its comments, so a docblock naming a method never counts as using it. */
    private function code(string $file): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents($this->root() . '/' . $file)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /** @return array<string, string> path => contents: the runtime PHP, plus the entry points in the project root */
    private function runtimeFiles(): array
    {
        $files = [];

        foreach (self::RUNTIME_DIRECTORIES as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root() . '/' . $directory, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $relative = $directory . '/' . str_replace('\\', '/', substr($file->getPathname(), strlen($this->root() . '/' . $directory) + 1));
                    $files[$relative] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        foreach (glob($this->root() . '/*.php') ?: [] as $path) {
            $files[basename($path)] = (string) file_get_contents($path);
        }

        return $files;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
