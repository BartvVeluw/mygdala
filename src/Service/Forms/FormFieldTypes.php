<?php

declare(strict_types=1);

namespace App\Service\Forms;

use App\Service\Forms\FieldTypes\CheckboxFieldType;
use App\Service\Forms\FieldTypes\ConsentFieldType;
use App\Service\Forms\FieldTypes\EmailFieldType;
use App\Service\Forms\FieldTypes\FormFieldType;
use App\Service\Forms\FieldTypes\RadioFieldType;
use App\Service\Forms\FieldTypes\SelectFieldType;
use App\Service\Forms\FieldTypes\TelephoneFieldType;
use App\Service\Forms\FieldTypes\TextareaFieldType;
use App\Service\Forms\FieldTypes\TextFieldType;

/**
 * THE list of field types a form can be built from — the one place a new
 * type is registered, and the only shared file adding one has to touch.
 *
 * Explicit and closed, for exactly the reason
 * App\Service\Blocks\BlockDefinitions is: `field_type` arrives from an admin
 * form and comes back out of a database row, and the only thing it may ever
 * do is hit or miss a key of this array. A miss is a miss — it never becomes
 * a class name. There is no directory scanning, no reflection and no type
 * defined in data.
 *
 * The order is the order the CMS offers them in, as cards in "Veld
 * toevoegen": the ordinary text-like ones first, then the choices, then the
 * two boxes.
 *
 * WHAT A TYPE IS CALLED lives in the admin catalogue and nowhere else:
 * `formfieldtype.<key>.label` and `.description` in
 * src/Service/Language/messages/, printed by admin/_form_fields.php. A
 * content block keeps a Dutch name in its own class because a MODULE may
 * bring one, and a module should not have to know this CMS has two
 * languages. No module brings a field type — this list is Core and closed —
 * so there is no second copy of the words to drift from the first.
 * Tests\Service\FormFieldTypeTest fails when a registered type has no name
 * or description in a catalogue.
 *
 * V1 STOPS HERE ON PURPOSE. No file upload, no date or time picker, no
 * address composite, no repeater, no rich text, no hidden value, no
 * calculated or payment field — FORMS.md lists them as deferred and says
 * why. Adding one later is one class next to the others plus one line here;
 * that is the whole extension point, and Tests\Service\FormFieldTypeTest
 * walks every registered type against the contract automatically.
 */
final class FormFieldTypes
{
    /** @var array<string, class-string<FormFieldType>> */
    private const MAP = [
        'text' => TextFieldType::class,
        'textarea' => TextareaFieldType::class,
        'email' => EmailFieldType::class,
        'tel' => TelephoneFieldType::class,
        'select' => SelectFieldType::class,
        'radio' => RadioFieldType::class,
        'checkbox' => CheckboxFieldType::class,
        'consent' => ConsentFieldType::class,
    ];

    /**
     * Types are stateless, so one instance per key per request is enough.
     *
     * @var array<string, FormFieldType>
     */
    private static array $instances = [];

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::MAP);
    }

    /**
     * The type for one key, or null when it is not registered — the single
     * lookup every caller goes through, so an unknown `field_type` fails the
     * same way everywhere.
     */
    public static function get(string $key): ?FormFieldType
    {
        if (!array_key_exists($key, self::MAP)) {
            return null;
        }

        return self::$instances[$key] ??= new (self::MAP[$key])();
    }

    /**
     * Every registered type, in registration order.
     *
     * @return array<string, FormFieldType>
     */
    public static function all(): array
    {
        $types = [];
        foreach (array_keys(self::MAP) as $key) {
            $types[$key] = self::get($key);
        }

        return $types;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::MAP);
    }
}
