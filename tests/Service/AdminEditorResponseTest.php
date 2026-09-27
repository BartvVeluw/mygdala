<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\AdminEditorResponse;
use PHPUnit\Framework\TestCase;

/**
 * The one answer shape of the dynamic admin editor
 * (App\Service\AdminEditorResponse, admin/assets/admin-editor.js): what the
 * script can rely on whichever endpoint it talks to. Pure, no database.
 */
final class AdminEditorResponseTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_ACCEPT']);
    }

    public function testASuccessIsOkWithEmptyObjectsNeverLists(): void
    {
        $json = json_encode(AdminEditorResponse::body(true, 'Opgeslagen'));

        $this->assertSame('{"ok":true,"message":"Opgeslagen","data":{},"errors":{}}', $json);
    }

    public function testARedirectTravelsInData(): void
    {
        $body = AdminEditorResponse::body(true, 'Product aangemaakt.', ['redirect' => '/admin/product-form.php?id=7&created=1']);

        $this->assertSame(['redirect' => '/admin/product-form.php?id=7&created=1'], $body['data']);
    }

    /**
     * Messages are lists per field name or section key; one without a place
     * (an integer key, the way a validator appends it) goes to the summary
     * under "_form", and the same message twice under one key is said once.
     */
    public function testErrorsAreListsByFieldOrSectionAndPlacelessOnesGoToTheSummary(): void
    {
        $body = AdminEditorResponse::body(false, 'Niet opgeslagen.', [], [
            'price' => 'Prijs is verplicht.',
            'options[new0][name]' => 'Optienaam is verplicht.',
            'variants' => ['Waarde “Noten” is in gebruik.', 'Waarde “Noten” is in gebruik.'],
            0 => 'De SEO-titel is te lang.',
            1 => '',
        ]);

        $this->assertFalse($body['ok']);
        $this->assertSame([
            'price' => ['Prijs is verplicht.'],
            'options[new0][name]' => ['Optienaam is verplicht.'],
            'variants' => ['Waarde “Noten” is in gebruik.'],
            AdminEditorResponse::FORM => ['De SEO-titel is te lang.'],
        ], $body['errors']);
    }

    public function testTheSameMessagesAsOneListForAFormPostedWithoutTheScript(): void
    {
        $this->assertSame(
            ['Prijs is verplicht.', 'In gebruik.', 'Te lang.'],
            AdminEditorResponse::messages(['price' => 'Prijs is verplicht.', 'variants' => ['In gebruik.'], 3 => 'Te lang.', 'x' => 'Prijs is verplicht.'])
        );
    }

    public function testOnlyARequestThatAcceptsJsonGetsJson(): void
    {
        unset($_SERVER['HTTP_ACCEPT']);
        $this->assertFalse(AdminEditorResponse::wantsJson(), 'a plain form post');

        $_SERVER['HTTP_ACCEPT'] = 'text/html,application/xhtml+xml';
        $this->assertFalse(AdminEditorResponse::wantsJson());

        $_SERVER['HTTP_ACCEPT'] = 'Application/JSON';
        $this->assertTrue(AdminEditorResponse::wantsJson(), 'the editor script');
    }
}
