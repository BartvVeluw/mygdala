<?php

declare(strict_types=1);

namespace App\Service\Translation;

use App\Repository\TranslationStateRepository;
use App\Service\Language\LanguageFallback;
use App\Service\Language\LanguageRegistry;
use App\Service\Language\SiteLanguages;
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
     * Can this site machine-translate into $target, from $source?
     *
     * $source defaults to the site's default website language, which is the
     * V1 case and by far the common one: Dutch is written first and English
     * is filled in from it. The parameter exists because the editor now
     * chooses which language version they are working on, and somebody
     * writing the Dutch version of a page that only exists in English should
     * be able to ask for the same help in the other direction.
     *
     * Four separate conditions, and all of them have to hold: a provider
     * exists, both languages are ones this site publishes, they are not the
     * same language, and the provider can actually write that pair.
     */
    public function canTranslateInto(string $target, ?string $source = null): bool
    {
        $from = $source ?? LanguageFallback::defaultLanguage();

        if (!SiteLanguages::exists($target) || !SiteLanguages::exists($from)) {
            return false;
        }

        if ($from === $target) {
            return false;
        }

        return $this->provider()->supports($from, $target);
    }

    /**
     * Translate a set of fields of one content row into one language,
     * from $sourceLanguage (default: the site's default website language).
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
        string $entityKey,
        string $target,
        array $requests,
        ?string $sourceLanguage = null,
    ): TranslationResult {
        $source = $sourceLanguage ?? LanguageFallback::defaultLanguage();

        if (!$this->canTranslateInto($target, $source)) {
            throw new TranslationException('This site cannot machine-translate into that language.');
        }

        $records = [];
        if ($entityKey !== '') {
            try {
                $records = ($this->stateRepository ?? new TranslationStateRepository())
                    ->forEntity($entityType, $entityKey);
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

        $this->rememberMachineTranslations($entityType, $entityKey, $target, $toTranslate, $translated);

        return new TranslationResult($translated, $skipped, $this->provider()->key(), $target);
    }

    /**
     * Write down what the provider just produced, for the fields it produced
     * it for.
     *
     * WHY HERE AND NOT IN THE SAVE ENDPOINT. Recording it on save would have
     * meant teaching all ~77 write endpoints to report which fields an editor
     * accepted from a provider, which is a change to every content table's
     * write path for the sake of a badge. Recording it here costs nothing and
     * is safe, because a record only ever describes a field that had already
     * passed the "may I overwrite this" gate — a translation somebody wrote by
     * hand never reaches this method and so never gets a record claiming the
     * machine owns it.
     *
     * An editor who translates and then abandons the form leaves a record
     * behind. That record says "this source produced this text"; since the
     * column does not hold that text, the next classify() sees a hash mismatch
     * and treats the field as somebody's own. The stale record is inert.
     *
     * @param array<string, string> $sources field => the primary text that was sent
     * @param array<string, string> $translations field => what came back
     */
    private function rememberMachineTranslations(
        string $entityType,
        string $entityKey,
        string $target,
        array $sources,
        array $translations,
    ): void {
        if ($entityKey === '' || $translations === []) {
            return;
        }

        $repository = $this->stateRepository ?? new TranslationStateRepository();
        $provider = $this->provider()->key();

        foreach ($translations as $field => $text) {
            if (!array_key_exists($field, $sources)) {
                continue;
            }

            try {
                $repository->record(
                    $entityType,
                    $entityKey,
                    $field,
                    $target,
                    TranslationState::hash($sources[$field]),
                    TranslationState::hash($text),
                    $provider,
                    false,
                );
            } catch (\Throwable $e) {
                // Losing a badge is cosmetic. Refusing to hand the editor
                // their translation because of it would not be.
                error_log('[TranslationService] could not record translation state: ' . $e->getMessage());
            }
        }
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
        string $entityKey,
        string $target,
        array $sourceTexts,
        array $machineTranslatedFields,
        ?string $provider = null,
    ): void {
        if ($entityKey === '' || !LanguageRegistry::has($target) || $machineTranslatedFields === []) {
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
                    $entityKey,
                    $field,
                    $target,
                    TranslationState::hash($sourceTexts[$field]),
                    null,
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
    public function recordManualEdits(string $entityType, string $entityKey, string $target, array $fields): void
    {
        if ($entityKey === '' || !LanguageRegistry::has($target) || $fields === []) {
            return;
        }

        $repository = $this->stateRepository ?? new TranslationStateRepository();

        foreach ($fields as $field) {
            try {
                $repository->markManual($entityType, $entityKey, $field, $target);
            } catch (\Throwable $e) {
                error_log('[TranslationService] could not mark a manual edit: ' . $e->getMessage());
            }
        }
    }
}
