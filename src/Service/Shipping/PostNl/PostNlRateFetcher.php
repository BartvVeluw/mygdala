<?php

namespace App\Service\Shipping\PostNl;

use Smalot\PdfParser\Parser as PdfParser;

/**
 * All network/PDF I/O for the PostNL sync lives here, and only here — see
 * App\Service\Shipping\PostNl\PostNlRateParser for the (pure, I/O-free) text
 * parsing this feeds into. Kept behind this one method so
 * PostNlRateSyncService can be tested against a fake implementation instead
 * of ever hitting the real network (see tests/Service/Shipping/PostNl/).
 *
 * PostNL publishes no free structured (JSON/API) tariff source — see MAIN.MD
 * "Beperkingen van de PostNL-bron" for what was actually investigated. The
 * only free, official source is their yearly tariff PDF, whose URL changes
 * (an unpredictable CMS asset hash) every time prices change, so it can't be
 * hardcoded — it has to be discovered from PostNL's own public rates page
 * each time this runs:
 *
 *   1. GET the public rates landing page (plain HTML, no login/JS needed).
 *   2. Find the link to the current full tariff PDF among its `.pdf` links
 *      (there is more than one — e.g. a separate international-rates PDF —
 *      so this specifically looks for the one whose filename identifies it
 *      as the main tariff booklet, never just "the first PDF found").
 *   3. Download that PDF.
 *   4. Extract its text via smalot/pdfparser (free, pure-PHP, MIT-licensed —
 *      no paid service, no shell-out to a binary, works on shared hosting).
 *
 * Uses file_get_contents()+stream_context (allow_url_fopen) rather than
 * ext-curl, since that's universally available on PHP shared hosting
 * (including Vimexx) without needing a specific compiled extension.
 */
class PostNlRateFetcher
{
    private const LANDING_PAGE_URL = 'https://www.postnl.nl/tarieven/';

    /** Distinguishes the main tariff booklet from other PDFs linked on the same page (e.g. international rates). */
    private const PDF_FILENAME_HINT = 'tarievenboekje';

    private const HTTP_TIMEOUT_SECONDS = 20;

    /** See App\Service\HttpUserAgent — the application, plus this installation's own base URL. */
    private const USER_AGENT_PURPOSE = 'shipping rate sync';

    /** @throws PostNlFetchException */
    public function fetchTariffText(): string
    {
        $html = $this->httpGet(self::LANDING_PAGE_URL);
        $pdfUrl = $this->discoverPdfUrl($html);
        $pdfBytes = $this->httpGet($pdfUrl);

        if (!str_starts_with($pdfBytes, '%PDF-')) {
            throw new PostNlFetchException("Downloaded file from {$pdfUrl} doesn't look like a PDF.");
        }

        return $this->extractText($pdfBytes);
    }

    /** @throws PostNlFetchException */
    private function discoverPdfUrl(string $html): string
    {
        if (!preg_match_all('/href="([^"]+\.pdf)"/i', $html, $matches)) {
            throw new PostNlFetchException('No PDF link found on ' . self::LANDING_PAGE_URL);
        }

        foreach ($matches[1] as $href) {
            if (str_contains(strtolower($href), self::PDF_FILENAME_HINT) && str_starts_with($href, 'http')) {
                return $href;
            }
        }

        throw new PostNlFetchException(
            'No PDF link matching "' . self::PDF_FILENAME_HINT . '" found on ' . self::LANDING_PAGE_URL
            . ' (found: ' . implode(', ', array_unique($matches[1])) . ')'
        );
    }

    /** @throws PostNlFetchException */
    private function httpGet(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: " . \App\Service\HttpUserAgent::forPurpose(self::USER_AGENT_PURPOSE) . "\r\n",
                'timeout' => self::HTTP_TIMEOUT_SECONDS,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        $statusLine = $http_response_header[0] ?? '';
        $statusOk = (bool) preg_match('#^HTTP/\S+\s+2\d\d#', $statusLine);

        if ($body === false || !$statusOk) {
            throw new PostNlFetchException("Could not fetch {$url} (" . ($statusLine ?: 'no response') . ')');
        }

        return $body;
    }

    /** @throws PostNlFetchException */
    private function extractText(string $pdfBytes): string
    {
        try {
            $document = (new PdfParser())->parseContent($pdfBytes);

            return $document->getText();
        } catch (\Throwable $e) {
            throw new PostNlFetchException('Could not extract text from the PostNL tariff PDF: ' . $e->getMessage(), 0, $e);
        }
    }
}
