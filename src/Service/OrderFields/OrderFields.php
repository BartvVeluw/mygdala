<?php

declare(strict_types=1);

namespace App\Service\OrderFields;

use App\Repository\OrderFieldRepository;
use App\Service\Language\SiteText;
use App\Service\ShopLocalization;
use PDO;

/**
 * A product's order questions as a customer meets them — and the one place
 * that checks the answers (Shop Product & Ordering 2.0, MODULES.md
 * "Bestelvelden"). product.php draws the questions from questions();
 * api/checkout.php and api/cart-check.php hand what the browser sent to
 * validate(), which trusts nothing of it.
 *
 * WHAT validate() GUARANTEES. Only the product's own questions, in their own
 * order; a required one answered; a text within its length, without control
 * characters (a long text keeps its line breaks); a choice that is one of
 * THIS question's choices; a tick box yes or no. Anything else sent — a key
 * that is no question of this product, a choice of another question — is
 * refused or ignored, never stored. A product that asks nothing (switch off,
 * or no questions) takes no answers at all, so an old cart line keeps
 * working exactly as it did.
 *
 * WHAT AN ORDER KEEPS is the answer in the DEFAULT website language
 * (snapshot()): the question's label and, for a choice, the choice's label as
 * the owner reads them, with the customer's own typed text as it was. That
 * is what the order screen and the mails print, and it never changes after.
 */
final class OrderFields
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    /**
     * The questions of a product in one language, or [] when it asks none.
     *
     * @return list<array{id: int, type: string, required: bool, max_length: int, label: string, help: string, options: list<array{id: int, label: string}>}>
     */
    public function questions(int $productId, string $languageCode): array
    {
        $repository = new OrderFieldRepository($this->db);
        if (!$repository->isEnabled($productId)) {
            return [];
        }

        $fields = $repository->fieldsForProduct($productId);
        ShopLocalization::preloadOrderFields(array_column($fields, 'id'));

        $questions = [];
        foreach ($fields as $field) {
            $options = [];
            if (OrderFieldType::hasOptions($field['field_type'])) {
                ShopLocalization::preloadOrderFieldOptions(array_column($field['options'], 'id'));
                foreach ($field['options'] as $option) {
                    $options[] = ['id' => $option['id'], 'label' => ShopLocalization::orderFieldOptionLabel($option['id'], $languageCode)];
                }
            }

            $questions[] = [
                'id' => $field['id'],
                'type' => $field['field_type'],
                'required' => $field['is_required'],
                'max_length' => OrderFieldType::maxLength($field['field_type'], $field['max_length']),
                'label' => ShopLocalization::orderFieldWord($field['id'], ShopLocalization::LABEL, $languageCode),
                'help' => ShopLocalization::orderFieldWord($field['id'], ShopLocalization::HELP_TEXT, $languageCode),
                'options' => $options,
            ];
        }

        return $questions;
    }

    /**
     * The answers to a product's questions, checked, in the order asked:
     * [field id => the answer as stored for identity] — a text as typed, a
     * choice as its option id, a tick box "1" or "0". An unanswered optional
     * question is absent.
     *
     * @return array<int, string>
     *
     * @throws OrderFieldValidationException for the first question whose answer cannot be taken
     */
    public function validate(int $productId, mixed $submitted, string $languageCode): array
    {
        $questions = $this->questions($productId, $languageCode);
        if ($questions === []) {
            return [];
        }

        $given = is_array($submitted) ? $submitted : [];
        $answers = [];

        foreach ($questions as $question) {
            $raw = $given[(string) $question['id']] ?? $given[$question['id']] ?? null;
            $label = $question['label'];

            if ($question['type'] === OrderFieldType::CHECKBOX) {
                $checked = $raw === true || $raw === 1 || $raw === '1' || $raw === 'on';
                if ($question['required'] && !$checked) {
                    throw new OrderFieldValidationException($question['id'], self::message('required_checkbox', $label, $languageCode));
                }
                $answers[$question['id']] = $checked ? '1' : '0';
                continue;
            }

            if (OrderFieldType::hasOptions($question['type'])) {
                $optionId = is_int($raw) || (is_string($raw) && ctype_digit($raw)) ? (int) $raw : 0;
                if ($optionId === 0 && ($raw === null || $raw === '')) {
                    if ($question['required']) {
                        throw new OrderFieldValidationException($question['id'], self::message('required_choice', $label, $languageCode));
                    }
                    continue;
                }
                if (!in_array($optionId, array_column($question['options'], 'id'), true)) {
                    throw new OrderFieldValidationException($question['id'], self::message('invalid_choice', $label, $languageCode));
                }
                $answers[$question['id']] = (string) $optionId;
                continue;
            }

            $text = is_string($raw) ? self::cleanText($raw, $question['type'] === OrderFieldType::TEXTAREA) : '';
            if ($text === '') {
                if ($question['required']) {
                    throw new OrderFieldValidationException($question['id'], self::message('required_text', $label, $languageCode));
                }
                continue;
            }
            if (mb_strlen($text) > $question['max_length']) {
                throw new OrderFieldValidationException($question['id'], self::message('too_long', $label, $languageCode, $question['max_length']));
            }
            $answers[$question['id']] = $text;
        }

        return $answers;
    }

    /**
     * The fingerprint of a set of answers: what makes two cart lines of the
     * same product and variant the same line or two ("Luna" and "Kyra" are
     * two lines). '' when there are none, so a line without questions keeps
     * the key it always had.
     *
     * @param array<int, string> $answers
     */
    public static function fingerprint(array $answers): string
    {
        if ($answers === []) {
            return '';
        }
        ksort($answers);

        return hash('sha256', (string) json_encode($answers, JSON_UNESCAPED_UNICODE));
    }

    /**
     * What an order line keeps of the answers (App\Repository\OrderItemFieldRepository):
     * the question and the answer in the DEFAULT website language, the
     * customer's typed text as it was.
     *
     * @param array<int, string> $answers from validate()
     * @return list<array{field_id: int, field_type: string, label: string, value: string, option_id: ?int}>
     */
    public function snapshot(int $productId, array $answers): array
    {
        if ($answers === []) {
            return [];
        }

        $language = ShopLocalization::defaultLanguage();
        $snapshot = [];
        foreach ($this->questions($productId, $language) as $question) {
            if (!array_key_exists($question['id'], $answers)) {
                continue;
            }

            $answer = $answers[$question['id']];
            $optionId = null;
            $value = $answer;

            if ($question['type'] === OrderFieldType::CHECKBOX) {
                $value = SiteText::pick($answer === '1' ? ['nl' => 'Ja', 'en' => 'Yes'] : ['nl' => 'Nee', 'en' => 'No'], $language);
            } elseif (OrderFieldType::hasOptions($question['type'])) {
                $optionId = (int) $answer;
                $value = '';
                foreach ($question['options'] as $option) {
                    if ($option['id'] === $optionId) {
                        $value = $option['label'];
                    }
                }
            }

            $snapshot[] = [
                'field_id' => $question['id'],
                'field_type' => $question['type'],
                'label' => $question['label'],
                'value' => $value,
                'option_id' => $optionId,
            ];
        }

        return $snapshot;
    }

    /** A typed answer without control characters; a long text keeps its line breaks. */
    private static function cleanText(string $text, bool $multiline): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace($multiline ? '/[\x00-\x09\x0B-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u', '', $text);

        return trim($text);
    }

    private static function message(string $kind, string $label, string $languageCode, int $max = 0): string
    {
        $sentence = match ($kind) {
            'required_text' => ['nl' => 'Vul "{label}" in.', 'en' => 'Please fill in "{label}".'],
            'required_choice' => ['nl' => 'Kies een antwoord bij "{label}".', 'en' => 'Please choose an answer for "{label}".'],
            'required_checkbox' => ['nl' => 'Vink "{label}" aan om verder te gaan.', 'en' => 'Please tick "{label}" to continue.'],
            'invalid_choice' => ['nl' => 'Het antwoord bij "{label}" kan niet worden gekozen. Laad de pagina opnieuw.', 'en' => 'The answer for "{label}" cannot be chosen. Please reload the page.'],
            default => ['nl' => '"{label}" mag hooguit {max} tekens zijn.', 'en' => '"{label}" may be at most {max} characters.'],
        };

        return strtr(SiteText::pick($sentence, $languageCode), ['{label}' => $label, '{max}' => (string) $max]);
    }
}
