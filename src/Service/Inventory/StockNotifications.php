<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Database;
use App\Mail\StockNotificationBuilder;
use App\Repository\ProductRepository;
use App\Repository\ProductVariantRepository;
use App\Repository\StockNotificationRepository;
use App\Service\Language\LanguageFallback;
use App\Service\Language\SiteLanguages;
use App\Service\Mailer;
use App\Service\ProductSeo;
use App\Service\PurchaseMode;
use App\Service\ShopLocalization;
use PDO;

/**
 * "Mail me when this is available again" (Shop Product & Ordering 2.0,
 * MODULES.md "Terug op voorraad"): ONE transactional mail per request, for
 * exactly the unit the visitor looked at — a product without variants, or
 * the chosen variant. No newsletter, no account, no list anybody can see.
 *
 * ASKING (subscribe()). Only for a unit that tracks stock and is sold out
 * right now; a second request for the same unit and address is the same
 * request (the database keeps one active). What the visitor hears does not
 * say whether the address was already known.
 *
 * WRITING (dispatchForProduct(), dispatchForUnits(), dispatchWaiting()). A
 * request is written to once its unit can be ordered again: stock from 0 to
 * more (a save in the product editor, or units a failed payment gave back),
 * or tracking switched off. Each mail is claimed first, so two senders at
 * once never write it twice; a sent request is never written again, and a
 * unit that already was available (5 → 6) has nobody waiting. A mail that
 * fails is NOT marked sent: the request stays active, counts its attempt,
 * and is tried again by the next sender — the next save of that product, or
 * "Wachtende meldingen versturen" under Shop-instellingen → E-mails. There
 * is no queue and no cron.
 *
 * At most $limit mails per unit per call, so a save or a webhook never
 * hangs on a long list; the rest wait for the next sender.
 */
final class StockNotifications
{
    public const SUBSCRIBED = 'subscribed';
    public const AVAILABLE = 'available';
    public const UNAVAILABLE = 'unavailable';

    public const DEFAULT_LIMIT = 25;

    private PDO $db;
    private StockNotificationRepository $repository;
    private Mailer $mailer;

    public function __construct(?Mailer $mailer = null, ?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
        $this->repository = new StockNotificationRepository($this->db);
        $this->mailer = $mailer ?? new Mailer();
    }

    /** An address as it is stored and compared: trimmed and lower-cased, or null when it is none. */
    public static function normaliseEmail(mixed $email): ?string
    {
        if (!is_string($email)) {
            return null;
        }

        $email = mb_strtolower(trim($email));

        return $email !== '' && mb_strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    /**
     * A visitor asks to hear when this unit is back.
     *
     * @return string SUBSCRIBED (stored, or already stored — the same answer),
     *                AVAILABLE (it can be ordered now; nothing stored) or
     *                UNAVAILABLE (no such unit for sale; nothing stored)
     */
    public function subscribe(int $productId, ?int $variantId, string $email, string $languageCode): string
    {
        $unit = $this->saleableUnit($productId, $variantId);
        if ($unit === null || !$unit->tracked) {
            return $unit === null ? self::UNAVAILABLE : self::AVAILABLE;
        }
        if (!$unit->isSoldOut()) {
            return self::AVAILABLE;
        }

        $this->repository->subscribe($productId, $unit->variantId, $unit->key(), $email, $languageCode);

        return self::SUBSCRIBED;
    }

    /**
     * Writes to everyone waiting for a unit of this product that can be
     * ordered now. What the product editor calls after every save: a restock,
     * tracking switched off or the product switched on again all make a
     * waiting request due, and a failed mail from before is retried.
     *
     * @return array{sent: int, failed: int}
     */
    public function dispatchForProduct(int $productId, int $limit = self::DEFAULT_LIMIT): array
    {
        $totals = ['sent' => 0, 'failed' => 0];
        foreach ($this->repository->waitingUnits() as $waiting) {
            if ($waiting['product_id'] === $productId) {
                $totals = self::add($totals, $this->dispatchUnit($waiting['product_id'], $waiting['variant_id'], $waiting['unit_key'], $limit));
            }
        }

        return $totals;
    }

    /**
     * Writes to everyone waiting for these units (what a release made
     * available again).
     *
     * @param list<StockUnit> $units
     * @return array{sent: int, failed: int}
     */
    public function dispatchForUnits(array $units, int $limit = self::DEFAULT_LIMIT): array
    {
        $totals = ['sent' => 0, 'failed' => 0];
        $done = [];
        foreach ($units as $unit) {
            if (isset($done[$unit->key()])) {
                continue;
            }
            $done[$unit->key()] = true;
            $totals = self::add($totals, $this->dispatchUnit($unit->productId, $unit->variantId, $unit->key(), $limit));
        }

        return $totals;
    }

    /**
     * Writes to everyone waiting for any unit that can be ordered now: the
     * owner's "send the waiting notifications", which also retries every mail
     * that failed before.
     *
     * @return array{sent: int, failed: int}
     */
    public function dispatchWaiting(int $limit = self::DEFAULT_LIMIT): array
    {
        $totals = ['sent' => 0, 'failed' => 0];
        foreach ($this->repository->waitingUnits() as $waiting) {
            $totals = self::add($totals, $this->dispatchUnit($waiting['product_id'], $waiting['variant_id'], $waiting['unit_key'], $limit));
        }

        return $totals;
    }

    /**
     * How many requests are waiting, and how many of those can be written
     * now (their unit is back) — what Shop-instellingen → E-mails shows.
     *
     * @return array{waiting: int, due: int, failed: int}
     */
    public function summary(): array
    {
        $waiting = 0;
        $due = 0;
        foreach ($this->repository->waitingUnits() as $unit) {
            $waiting += $unit['waiting'];
            if ($this->orderableNow($unit['product_id'], $unit['variant_id'])) {
                $due += $unit['waiting'];
            }
        }

        return ['waiting' => $waiting, 'due' => $due, 'failed' => $this->repository->countFailed()];
    }

    /** Whether this unit can be put in a cart right now: for sale, and not sold out. */
    public function orderableNow(int $productId, ?int $variantId): bool
    {
        $unit = $this->saleableUnit($productId, $variantId);

        return $unit !== null && !$unit->isSoldOut();
    }

    /* ------------------------------------------------------------------ */

    /** @return array{sent: int, failed: int} */
    private function dispatchUnit(int $productId, ?int $variantId, string $unitKey, int $limit): array
    {
        $totals = ['sent' => 0, 'failed' => 0];
        if (!$this->orderableNow($productId, $variantId)) {
            return $totals;
        }

        foreach ($this->repository->activeForUnit($unitKey, $limit) as $request) {
            if (!$this->repository->claim($request['id'])) {
                continue;
            }

            try {
                $language = $this->mailLanguage($request['language_code']);
                $mail = StockNotificationBuilder::build([
                    'product_name' => ShopLocalization::product($productId, ShopLocalization::NAME, $language),
                    'variant' => $variantId !== null ? (string) ((new ProductVariantRepository($this->db))->buildLabel($variantId) ?? '') : '',
                    'product_url' => ProductSeo::canonicalUrl($productId, $language),
                ], $language);

                $this->mailer->send($request['email'], '', $mail['subject'], $mail['html'], $mail['text']);
                $this->repository->markSent($request['id']);
                $totals['sent']++;
            } catch (\Throwable $e) {
                // Never marked sent: the request stays active for the next
                // sender. The address stays out of the log.
                $this->repository->markFailed($request['id']);
                $totals['failed']++;
                error_log('[StockNotifications] mail for request #' . $request['id'] . ' failed: ' . Mailer::redactCredentials($e->getMessage()));
            }
        }

        return $totals;
    }

    /**
     * The unit this product (and variant) sells from, while it is for sale in
     * the shop at all: active, in the shop, not "op aanvraag", and — for a
     * variant — an active variant of this product. Null otherwise.
     */
    private function saleableUnit(int $productId, ?int $variantId): ?StockUnit
    {
        $products = new ProductRepository($this->db);
        if (!$products->isShopPurchasable($productId) || PurchaseMode::isInquiry($products->purchaseMode($productId))) {
            return null;
        }
        if ($variantId !== null && (new ProductVariantRepository($this->db))->findActiveForProduct($variantId, $productId) === null) {
            return null;
        }

        return (new Inventory($this->db))->forProduct($productId)->unitFor($variantId);
    }

    /** The language asked in, while the website still has it; the default language otherwise. */
    private function mailLanguage(string $code): string
    {
        try {
            return SiteLanguages::isActive($code) ? $code : LanguageFallback::defaultLanguage();
        } catch (\Throwable) {
            return LanguageFallback::defaultLanguage();
        }
    }

    /**
     * @param array{sent: int, failed: int} $a
     * @param array{sent: int, failed: int} $b
     * @return array{sent: int, failed: int}
     */
    private static function add(array $a, array $b): array
    {
        return ['sent' => $a['sent'] + $b['sent'], 'failed' => $a['failed'] + $b['failed']];
    }
}
