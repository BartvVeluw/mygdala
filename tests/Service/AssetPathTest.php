<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\AssetPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shape of a local frontend asset path, shared by PageAssets (before it
 * checks the disk) and ThemeDefinition (which never does). Shape only: the
 * "present on disk" half is PageAssets::isLoadable(), covered by
 * FrontendAssetOwnershipTest.
 */
final class AssetPathTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function wellFormed(): array
    {
        return [
            'core stylesheet' => ['assets/css/core.css'],
            'core script' => ['assets/js/main.js'],
            'nested' => ['assets/css/blocks/item-gallery.css'],
            'theme folder' => ['assets/css/themes/light-minimal.css'],
            'extension folder' => ['assets/css/extensions/kobold/theme.css'],
            'underscore and digits' => ['assets/js/shop_2/cart.js'],
            'uppercase' => ['assets/css/Theme.css'],
        ];
    }

    #[DataProvider('wellFormed')]
    public function testAWellFormedPathIsAccepted(string $path): void
    {
        $this->assertTrue(AssetPath::isValid($path));
    }

    /** @return array<string, array{string}> */
    public static function malformed(): array
    {
        return [
            'empty' => [''],
            'no extension' => ['assets/css/core'],
            'other extension' => ['assets/images/logo.svg'],
            'php' => ['assets/css/x.php'],
            'double extension' => ['assets/css/x.css.php'],
            'outside assets' => ['src/Service/PageAssets.php'],
            'assets elsewhere' => ['admin/assets/admin.css'],
            'leading slash' => ['/assets/css/core.css'],
            'windows absolute' => ['C:/assets/css/core.css'],
            'traversal' => ['assets/css/../../config/x.css'],
            'traversal at start' => ['../assets/css/core.css'],
            'dotted segment' => ['assets/css/..x.css'],
            'backslash' => ['assets\\css\\core.css'],
            'mixed backslash' => ['assets/css\\..\\x.css'],
            'protocol' => ['https://cdn.example/assets/x.css'],
            'protocol-relative' => ['//cdn.example/x.css'],
            'data url' => ['data:text/css,body{}'],
            'query' => ['assets/css/core.css?v=1'],
            'fragment' => ['assets/css/core.css#a'],
            'space' => ['assets/css/x y.css'],
            'trailing newline' => ["assets/css/core.css\n"],
            'null byte' => ["assets/css/core.css\0"],
            'quote' => ['assets/css/x".css'],
            'angle bracket' => ['assets/css/<x>.css'],
            'non-ascii' => ['assets/css/thé.css'],
        ];
    }

    #[DataProvider('malformed')]
    public function testAMalformedPathIsRefused(string $path): void
    {
        $this->assertFalse(AssetPath::isValid($path));
    }

    /**
     * Pure: it sits below both PageAssets and ThemeDefinition, so it may
     * depend on neither — nor on anything else of the application — and
     * it never touches the disk.
     */
    public function testItDependsOnNothingAndTouchesNoFile(): void
    {
        $tokens = token_get_all((string) file_get_contents(dirname(__DIR__, 2) . '/src/Service/AssetPath.php'));

        $kinds = [];
        $names = [];
        foreach ($tokens as $token) {
            if (!is_array($token)) {
                continue;
            }
            $kinds[] = $token[0];
            if (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $names[] = $token[1];
            }
        }

        $this->assertNotContains(T_USE, $kinds, 'AssetPath imports nothing');
        $this->assertNotContains(T_DOUBLE_COLON, $kinds, 'AssetPath calls no other class');
        $this->assertSame(['App\\Service'], array_values(array_filter($names, static fn (string $name): bool => str_contains($name, '\\'))));

        foreach (['is_file', 'file_exists', 'filemtime', 'file_get_contents', 'fopen', 'realpath', 'stat', 'is_readable', 'glob', 'scandir'] as $io) {
            $this->assertNotContains($io, $names, 'AssetPath must not call ' . $io);
        }
    }
}
