<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Repository\OrderRepository;
use App\Repository\SiteSettingRepository;
use App\Service\Language\AdminTranslator;
use App\Service\SiteSettings;

/**
 * What one save of Shop → Betalingen may change, checked in full before any
 * of it is written (the shape of App\Service\ProductVariantEditor):
 * api/admin/update-payment-settings.php validates, then saves inside one
 * transaction, so a refused save changes nothing.
 *
 * THE FIELDS
 *
 *   test_api_key, live_api_key   a NEW key for that mode. Empty means "keep
 *                                the stored one": the fields are never
 *                                filled in by the screen, so an empty field
 *                                can never erase a key, and a key is never
 *                                sent back to the browser
 *   payment_mode                 'test' or 'live'
 *   confirm_live_key_replace     '1' when the owner confirms replacing the
 *                                live key of a shop that already took
 *                                payments
 *
 * THE RULES
 *
 *   - With the environment pinning the key (MollieConfiguration, step 1)
 *     nothing about keys or mode may be saved here; a request that tries is
 *     refused, so a hidden .env key can never be replaced or erased.
 *   - A key must have the shape of a Mollie key AND of its field's mode: a
 *     live key typed as the test key is refused with a sentence that says so.
 *   - LIVE ONLY AFTER A WORKING CONNECTION. Choosing live, or giving a live
 *     shop a new live key, runs the connection test on that live key inside
 *     this very save; only when Mollie accepts it is anything written. There
 *     is no remembered "verified": the proof is always the key being saved,
 *     at the moment it is saved.
 *   - Replacing the live key of a shop that is live and has taken payments
 *     needs the confirmation box.
 *
 * The keys are sealed by App\Service\Secrets\SecretStore; the mode is the
 * site setting `shop_payment_mode`. Messages come from the catalog, keyed by
 * the field they are about, and never repeat a key.
 */
final class PaymentSettingsEditor
{
    private string $testKey;
    private string $liveKey;
    private ?string $mode;
    private bool $modeSubmitted;
    private bool $confirmLiveReplace;

    /** @var array<string, string> */
    private array $errors = [];

    private bool $validated = false;

    /**
     * @param array<string, mixed>   $post
     * @param (\Closure(): bool)|null $shopHasPayments whether any order ever got a payment; a test's own
     */
    public function __construct(
        array $post,
        private readonly MollieConfiguration $configuration,
        private readonly MolliePaymentProvider $provider,
        private readonly string $language,
        private readonly ?\Closure $shopHasPayments = null,
    ) {
        $this->testKey = self::text($post['test_api_key'] ?? null);
        $this->liveKey = self::text($post['live_api_key'] ?? null);
        $this->modeSubmitted = array_key_exists('payment_mode', $post);
        $this->mode = $this->modeSubmitted ? self::text($post['payment_mode']) : null;
        $this->confirmLiveReplace = ($post['confirm_live_key_replace'] ?? null) === '1';
    }

    /**
     * Every problem with this save, by the name of the field it is about.
     * Empty when everything may be written.
     *
     * @return array<string, string>
     */
    public function validate(): array
    {
        $this->validated = true;
        $this->errors = [];

        if ($this->configuration->isPinnedByEnvironment()) {
            $this->validatePinned();

            return $this->errors;
        }

        $this->validateKey(MollieConfiguration::MODE_TEST, $this->testKey, 'test_api_key');
        $this->validateKey(MollieConfiguration::MODE_LIVE, $this->liveKey, 'live_api_key');

        if ($this->modeSubmitted && !in_array($this->mode, MollieConfiguration::MODES, true)) {
            $this->errors['payment_mode'] = AdminTranslator::trans('payments.error.mode_invalid');
        }

        if (
            $this->liveKey !== ''
            && !isset($this->errors['live_api_key'])
            && $this->configuration->storedMode() === MollieConfiguration::MODE_LIVE
            && $this->configuration->storedKey(MollieConfiguration::MODE_LIVE) !== null
            && !$this->confirmLiveReplace
            && $this->shopHasPayments()
        ) {
            $this->errors['confirm_live_key_replace'] = AdminTranslator::trans('payments.error.confirm_live_key');
        }

        if ($this->errors === [] && $this->targetMode() === MollieConfiguration::MODE_LIVE) {
            $this->checkLive();
        }

        return $this->errors;
    }

    /**
     * Writes what validate() accepted. Call inside a transaction; a
     * SecretStoreException means the keys could not be sealed and the
     * transaction must be rolled back.
     *
     * @return list<string> what changed, for the log; never a key
     */
    public function save(SiteSettingRepository $settings): array
    {
        if (!$this->validated || $this->errors !== []) {
            throw new \LogicException('Only a validated, accepted payment settings save can be written.');
        }

        $events = [];

        foreach ([MollieConfiguration::MODE_TEST => $this->testKey, MollieConfiguration::MODE_LIVE => $this->liveKey] as $mode => $key) {
            if ($key === '') {
                continue;
            }

            $replaced = $this->configuration->storedKey($mode) !== null;
            $this->configuration->storeKey($mode, $key);
            $events[] = $mode . ' API key ' . ($replaced ? 'replaced' : 'stored');
        }

        $from = $this->configuration->storedMode();
        $to = $this->targetMode();
        if ($this->modeSubmitted && $to !== $from) {
            $settings->upsertMany([MollieConfiguration::MODE_SETTING => $to]);
            SiteSettings::clearCache();
            $events[] = 'payment mode changed from ' . $from . ' to ' . $to;
        }

        return $events;
    }

    private function validatePinned(): void
    {
        $refusal = AdminTranslator::trans('payments.error.pinned_by_environment');

        if ($this->testKey !== '') {
            $this->errors['test_api_key'] = $refusal;
        }
        if ($this->liveKey !== '') {
            $this->errors['live_api_key'] = $refusal;
        }
        if ($this->modeSubmitted && $this->mode !== $this->configuration->activeMode()) {
            $this->errors['payment_mode'] = $refusal;
        }
    }

    private function validateKey(string $mode, string $key, string $field): void
    {
        if ($key === '') {
            return;
        }

        $keyMode = MollieConfiguration::modeOfKey($key);

        if ($keyMode === null) {
            $this->errors[$field] = AdminTranslator::trans('payments.error.key_format');
        } elseif ($keyMode !== $mode) {
            $this->errors[$field] = AdminTranslator::trans('payments.error.' . $keyMode . '_key_in_' . $mode . '_field');
        }
    }

    /**
     * Live is only accepted with a live key Mollie accepts, tested now: when
     * the shop switches to live, and when a live shop gets a new live key.
     * Staying live with the same key needs no call.
     */
    private function checkLive(): void
    {
        $switching = $this->configuration->storedMode() !== MollieConfiguration::MODE_LIVE;
        if (!$switching && $this->liveKey === '') {
            return;
        }

        $field = $this->liveKey !== '' ? 'live_api_key' : 'payment_mode';
        $key = $this->liveKey !== '' ? $this->liveKey : null;

        if ($key === null) {
            try {
                $key = $this->configuration->keyFor(MollieConfiguration::MODE_LIVE);
            } catch (PaymentProviderException) {
                $this->errors[$field] = AdminTranslator::trans('payments.error.live_key_unreadable');

                return;
            }

            if ($key === null) {
                $this->errors[$field] = AdminTranslator::trans('payments.error.live_needs_key');

                return;
            }
        }

        $result = MollieConnectionResult::check($this->provider, $this->configuration, $key, MollieConfiguration::MODE_LIVE, $this->language);
        if (!$result->ok()) {
            $this->errors[$field] = AdminTranslator::trans('payments.error.live_check_failed', ['reason' => $result->message()]);
        }
    }

    private function targetMode(): string
    {
        return $this->modeSubmitted && in_array($this->mode, MollieConfiguration::MODES, true)
            ? (string) $this->mode
            : $this->configuration->storedMode();
    }

    private function shopHasPayments(): bool
    {
        return $this->shopHasPayments !== null
            ? ($this->shopHasPayments)()
            : (new OrderRepository())->hasAnyPayment();
    }

    /** A posted scalar as trimmed text; anything else as ''. */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
