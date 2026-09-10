<?php

declare(strict_types=1);

namespace App\Service\Translation;

use App\Repository\TranslationStateRepository;
use App\Service\Language\ContentLanguages;
use App\Service\Language\LanguageRegistry;
use App\Service\RichTextSanitizer;

/**
 * What application code calls to translate website content.
 *
 * Everything above this line — page editors, block editors, the endpoint —
 * knows about "translating a section into a language". Everything below it
 * knows about a provider. Neither knows about DeepL, which is the point:
 * swapping the provider changes TranslationProviderFactory and nothing else.
 *
 * The four rules this class enforces, in the order they matter:
 *
 * 1. MANUAL EDITS WIN. A field a person wrote is skipped unless that same
 *    person explicitly confirmed the overwrite. The decision is
 *    TranslationState::mayOverwrite()'s, so the screen's confirmation and the
 *    server's refusal are the same rule.
 *
 * 2. ONLY THIS ENTITY'S OWN FIELDS GO OUT. The caller passes the text; the
 *    caller is an endpoint that read it from the row it is editing. No id, no
 *    CSRF token, no setting, no metadata and no other page's content is ever
 *    part of a request to a third party.
 *
 * 3. WHAT COMES BACK IS UNTRUSTED. A provider's HTML goes through the
 *    project's own sanitiser before it is returned, exactly like HTML typed
 *    by an editor. A translation service is a third party, not an authority.
 *
 * 4. NOTHING IS SAVED HERE. This returns translations for the editor to look
 *    at; the editor saves them with the form's normal endpoint, its normal
 *    validation and its normal CSRF token. A translate button that wrote
 *    straight to the database would be a second, weaker write path.
 */
final class TranslationService
{
    public function __construct(
        private readonly ?TranslationProvider $provider = null,
        private readonly ?TranslationStateRepository $stateRepository = null,
    ) {
    }

    private function provider(): TranslationProvider
    {
        return $this->provider ?? TranslationProviderFactory::provider();
    }

    /** Is automatic translation offered at all on this installation? */
    public function isAvailable(): bool
    {
        return $this->provider()->isConfigured();
    }

    /**
     * Can this site machine-translate from its primary language into $target?
     *
     * Three separate conditions, and all of them have to hold: a provider
     * exists, the site actually publishes that language, and the provider can
     * write it.
     */
    public function canTranslateInto(string $target): bool
    {
        if (!ContentLanguages::isEnabled($target) || $target === ContentLanguages::primary()) {
            return false;
        }

        return $this->provider()->supports(ContentLanguages::primary(), $target);
    }

    /**
     * Translate a set of fields of one content row into one language.
     *
     * Returns only the fields that were actually translated. A field that was
     * empty, that a person had already written, or that the provider refused
     * is reported in the result's `skipped` list with a reason — so the
     * editor learns why their text was left alone instead of wondering.
     *
     * @param TranslationRequest[] $requests
     * @throws TranslationException when the provider itself failed
     */
    public function translateEntity(
        string $entityType,
        int $entityId,
        string $target,
        array $requests,
    ): TranslationResult {
        $source = ContentLanguages::primary();

        if (!$this->canTranslateInto($target)) {
            throw new TranslationException('This site cannot machine-translate into that language.');
        }

        $records = [];
        if ($entityId > 0) {
            try {
                $records = ($this->stateRepository ?? new TranslationStateRepository())
                    ->forEntity($entityType, $entityId);
            } catch (\Throwable $e) {
                // No records means every existing translation classifies as
                // MANUAL, so an unreadable state table makes this MORE
                // protective rather than less. Fail towards the editor's text.
                error_log('[TranslationService] could not read translation state: ' . $e->getMessage());
            }
        }

        $toTranslate = [];
        $htmlFields = [];
        $skipped = [];

        foreach ($requests as $request) {
            if ($request->isEmpty()) {
                $skipped[$request->field] = 'empty';
                continue;
            }

            $state = TranslationState::classify(
                $request->sourceText,
                $request->existingTranslation,
                $records[$request->field . ':' . $target] ?? null,
            );

            if (!TranslationState::mayOverwrite($state, $request->force)) {
                $skipped[$request->field] = 'manual';
                continue;
            }

            $toTranslate[$request->field] = $request->sourceText;
            if ($request->isHtml) {
                $htmlFields[$request->field] = true;
            }
        }

        if ($toTranslate === []) {
            return new TranslationResult([], $skipped, $this->provider()->key(), $target);
        }

        // Two calls at most, and only when both kinds are present: markup and
        // plain text need different tag handling, and mixing them would make
        // the provider treat someone's "<" as a tag or their tags as text.
        $translated = [];
        $plain = array_diff_key($toTranslate, $htmlFields);
        $markup = array_intersect_key($toTranslate, $htmlFields);

        if ($plain !== []) {
            $translated += $this->provider()->translateAll($plain, $source, $target, false);
        }

        if ($markup !== []) {
            $translatedMarkup = $this->provider()->translateAll($markup, $source, $target, true);

            foreach ($translatedMarkup as $field => $html) {
                // Rule 3. The same sanitiser an editor's own HTML goes
                // through, so a provider cannot introduce a tag, an attribute
                // or a URL scheme this CMS does not allow.
                $translatedMarkup[$field] = (string) RichTextSanitizer::sanitize($html);
            }

            $translated += $translatedMarkup;
        }

        return new TranslationResult($translated, $skipped, $this->provider()->key(), $target);
    }

    /**
     * Remember that these fields now hold a machine translation of exactly
     * this source text.
     *
     * Called by the SAVE endpoint rather than by the translate endpoint, and
     * that ordering is deliberate: a translation an editor looked at and then
     * abandoned never happened, so it must not leave a record claiming the
     * field is up to date.
     *
     * @param array<string, string> $sourceTexts field => the primary language's text as saved
     * @param string[] $machineTranslatedFields fields the editor accepted from the provider
     */
    public function recordMachineTranslations(
        string $entityType,
        int $entityId,
        string $target,
        array $sourceTexts,
        array $machineTranslatedFields,
        ?string $provider = null,
    ): void {
        if ($entityId < 1 || !LanguageRegistry::has($target) || $machineTranslatedFields === []) {
            return;
        }

        $repository = $this->stateRepository ?? new TranslationStateRepository();

        foreach ($machineTranslatedFields as $field) {
            if (!array_key_exists($field, $sourceTexts)) {
                continue;
            }

            try {
                $repository->record(
                    $entityType,
                    $entityId,
                    $field,
                    $target,
                    TranslationState::hash($sourceTexts[$field]),
                    $provider ?? $this->provider()->key(),
                    false,
                );
            } catch (\Throwable $e) {
                // Losing a badge is a cosmetic failure; refusing to save the
                // editor's content because of it would not be.
                error_log('[TranslationService] could not record translation state: ' . $e->getMessage());
            }
        }
    }

    /**
     * Remember that a person changed a translation by hand, so automatic
     * translation stops offering to replace it.
     *
     * @param string[] $fields
     */
    public function recordManualEdits(string $entityType, int $entityId, string $target, array $fields): void
    {
        if ($entityId < 1 || !LanguageRegistry::has($target) || $fields === []) {
            return;
        }

        $repository = $this->stateRepository ?? new TranslationStateRepository();

        foreach ($fields as $field) {
            try {
                $repository->markManual($entityType, $entityId, $field, $target);
            } catch (\Throwable $e) {
                error_log('[TranslationService] could not mark a manual edit: ' . $e->getMessage());
            }
        }
    }
}
