<?php

declare(strict_types=1);

namespace App\Service\OrderFields;

use App\Repository\OrderFieldRepository;
use App\Repository\OrderFieldUploadRepository;
use App\Repository\OrderItemFieldRepository;
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
 * THIS question's choices; a tick box yes or no; a picture as the token of an
 * upload made for THIS question of THIS product, unclaimed, unexpired, with
 * its file (OrderFieldUploads::resolve()). Anything else sent — a key
 * that is no question of this product, a choice of another question — is
 * refused or ignored, never stored. A product that asks nothing (switch off,
 * or no questions) takes no answers at all, so an old cart line keeps
 * working exactly as it did.
 *
 * WHAT AN ORDER KEEPS is the answer in the DEFAULT website language
 * (snapshot()): the question's label and, for a choice, the choice's label as
 * the owner reads them, with the customer's own typed text as it was. That
 * is what the order screen and the mails print, and it never changes after.
 * A picture's answer is its original filename, and record() binds the
 * private file to that answer in the checkout's transaction.
 */
final class OrderFields
{
    /** @var array<string, array<string, mixed>> uploads validate() resolved, by token */
    private array $resolvedUploads = [];

    public function __construct(private readonly ?PDO $db = null, private ?OrderFieldUploads $uploads = null)
    {
    }

    private function uploads(): OrderFieldUploads
    {
        return $this->uploads ??= new OrderFieldUploads($this->db);
    }

    /**
     * The questions of a product in one language, or [] when it asks none.
     *
     * `max_bytes` is what an image question really accepts (0 for any other).
     *
     * @return list<array{id: int, type: string, required: bool, max_length: int, max_bytes: int, label: string, help: string, options: list<array{id: int, label: string}>}>
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
                'max_bytes' => OrderFieldType::isImage($field['field_type']) ? OrderFieldUploadPolicy::effectiveMaxBytes($field['max_file_size_mb']) : 0,
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
     * choice as its option id, a tick box "1" or "0", a picture as its upload
     * token (so two pictures are two lines). An unanswered optional
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

            if (OrderFieldType::isImage($question['type'])) {
                if ($raw === null || $raw === '') {
                    if ($question['required']) {
                        throw new OrderFieldValidationException($question['id'], self::message('required_image', $label, $languageCode));
                    }
                    continue;
                }
                try {
                    $this->resolvedUploads[(string) $raw] = $this->uploads()->resolve($raw, $productId, $question['id'], $label, $languageCode);
                } catch (OrderFieldUploadException $e) {
                    throw new OrderFieldValidationException($question['id'], $e->getMessage());
                }
                $answers[$question['id']] = (string) $raw;
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
     * A picture's value is its original filename, with `upload_id` naming the
     * upload record() binds to the answer.
     *
     * @param array<int, string> $answers from validate()
     * @return list<array{field_id: int, field_type: string, label: string, value: string, option_id: ?int, upload_id: ?int}>
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
            $uploadId = null;
            $value = $answer;

            if (OrderFieldType::isImage($question['type'])) {
                $upload = $this->resolvedUploads[$answer]
                    ?? $this->uploads()->resolve($answer, $productId, $question['id'], $question['label'], $language);
                $uploadId = (int) $upload['id'];
                $value = (string) $upload['original_filename'];
            } elseif ($question['type'] === OrderFieldType::CHECKBOX) {
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
                'upload_id' => $uploadId,
            ];
        }

        return $snapshot;
    }

    /**
     * Writes an order line's answers and binds each picture to its answer,
     * inside the checkout's transaction. A picture another order claimed
     * first, or one that expired a moment ago, throws: the caller rolls the
     * whole order back, and the upload stays a temporary one that expires.
     *
     * @param array<int, string> $answers from validate()
     *
     * @throws OrderFieldUploadException kind "claimed"
     */
    public function record(int $orderItemId, int $productId, array $answers, string $languageCode): void
    {
        $db = $this->db ?? \App\Database::connection();
        $snapshot = $this->snapshot($productId, $answers);
        $ids = (new OrderItemFieldRepository($db))->create($orderItemId, $snapshot);
        $uploads = new OrderFieldUploadRepository($db);

        foreach (array_values($snapshot) as $position => $answer) {
            if ($answer['upload_id'] !== null && !$uploads->claim($answer['upload_id'], $ids[$position])) {
                throw new OrderFieldUploadException('claimed', self::message('upload_taken', $answer['label'], $languageCode), 409);
            }
        }
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
            'required_image' => ['nl' => 'Kies een afbeelding bij "{label}".', 'en' => 'Please choose a picture for "{label}".'],
            'upload_taken' => ['nl' => 'De afbeelding bij "{label}" kan niet meer worden gebruikt. Voeg het product opnieuw toe met je afbeelding.', 'en' => 'The picture for "{label}" can no longer be used. Please add the product again with your picture.'],
            'invalid_choice' => ['nl' => 'Het antwoord bij "{label}" kan niet worden gekozen. Laad de pagina opnieuw.', 'en' => 'The answer for "{label}" cannot be chosen. Please reload the page.'],
            default => ['nl' => '"{label}" mag hooguit {max} tekens zijn.', 'en' => '"{label}" may be at most {max} characters.'],
        };

        return strtr(SiteText::pick($sentence, $languageCode), ['{label}' => $label, '{max}' => (string) $max]);
    }
}
