<?php

declare(strict_types=1);

namespace App\Update;

/**
 * V1's update feed: one manifest URL, with its detached signature next to it.
 *
 *     https://releases.example/mygdala/manifest.json
 *     https://releases.example/mygdala/manifest.json.sig
 *     https://releases.example/mygdala/mygdala-0.2.0.zip    (package_url)
 *
 * The URL comes from UpdateConfig (the environment) and nowhere else — no
 * request parameter can change what this class fetches, which is what keeps
 * the Updates screen from being an SSRF endpoint
 * (docs/updates/ARCHITECTURE.md, "Beveiliging"). The package URL then comes
 * from the manifest, and only after the manifest's signature verified.
 */
final class HttpUpdateSource implements UpdateSource
{
    /** A manifest is a few kilobytes; anything near this is not one. */
    private const MAX_MANIFEST_BYTES = 262144;

    private const MAX_SIGNATURE_BYTES = 4096;

    /**
     * @param array<string, string> $trustedKeys key id => raw public key (ReleaseKeys)
     */
    public function __construct(
        private readonly string $manifestUrl,
        private readonly array $trustedKeys,
        private readonly HttpFetcher $http = new HttpFetcher()
    ) {
    }

    /**
     * The source this installation is configured for.
     *
     * @throws UpdateException when no feed is configured
     */
    public static function fromConfig(): self
    {
        $url = UpdateConfig::manifestUrl();

        if ($url === '') {
            throw new UpdateException('update.error.feed_not_configured', [], 'No ' . UpdateConfig::MANIFEST_URL_VARIABLE);
        }

        return new self($url, ReleaseKeys::trusted());
    }

    public function latest(): ReleaseManifest
    {
        $bytes = $this->http->get($this->manifestUrl, self::MAX_MANIFEST_BYTES);
        $signature = $this->http->get($this->manifestUrl . '.sig', self::MAX_SIGNATURE_BYTES);

        // Verify the bytes before reading a single field out of them.
        ReleaseSignature::verify($bytes, $signature, $this->trustedKeys);

        return ReleaseManifest::fromJson($bytes, $this->manifestUrl);
    }

    public function download(ReleaseManifest $manifest, PackageDownload $download, float $deadline): void
    {
        $this->http->resume($manifest->packageUrl, $download, $deadline);
    }

    public function describe(): string
    {
        return UpdateConfig::describeUrl($this->manifestUrl);
    }
}
