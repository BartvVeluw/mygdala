<?php

declare(strict_types=1);

/**
 * The two lines of per-request bookkeeping every PUBLIC page needs before it
 * prints its first byte. Require it directly after the autoloader, next to
 * App\Service\Forms\PublicFormSession::prime().
 *
 * WHY IT IS A FILE AND NOT A FUNCTION CALL: it has to happen before any
 * output, in every entrypoint, and a `require` at the top of a template is
 * how this project already expresses that (see the prime() call in index.php,
 * pagina.php, shop.php, …). Nothing here renders, and it is safe to require
 * more than once.
 *
 * WHAT IT DOES:
 *
 *   1. resolves App\Service\Routing\RequestLanguage, so a template that is
 *      reached DIRECTLY — /shop.php, /cart.php, /product.php?id=… are real
 *      files that Apache serves without dispatcher.php ever running — has a
 *      language pinned before its <html lang> is printed. An unprefixed URL
 *      is always the default language, so this can only ever confirm what a
 *      lazy read would have answered; doing it here makes the moment
 *      explicit and gives step 2 something to record.
 *   2. remembers that language as the visitor's preference
 *      (App\Service\Routing\LanguagePreference), the same way dispatcher.php
 *      does for every routed URL. Together they are what makes the public
 *      language switch work in both directions: it is an ordinary link, and
 *      the language a visitor lands in is the language that gets remembered.
 *      No choice endpoint, no return URL, and therefore nothing anywhere near
 *      an open redirect.
 *
 * Only for a retrieval. A POST is not somebody choosing a language to read
 * the site in, and must not silently change what `/` will answer next time.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$publicRequestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($publicRequestMethod === 'GET' || $publicRequestMethod === 'HEAD') {
    \App\Service\Routing\LanguagePreference::remember(
        \App\Service\Routing\RequestLanguage::current()
    );
}

unset($publicRequestMethod);
