<?php

declare(strict_types=1);

namespace App\Update;

/**
 * Where releases come from.
 *
 * The updater never asks HOW a manifest was fetched or how a package
 * travelled, only for a verified manifest and for the package it names. That
 * keeps the domain logic (Updater, Preflight, UpdatePlanner) free of any
 * particular host: V1 has exactly one implementation, HttpUpdateSource on one
 * manifest URL, and a later feed — a GitHub release, a mirror, a local
 * directory for an offline install — is another implementation of these two
 * methods, not a change anywhere else.
 *
 * Contract for every implementation:
 *
 *   - latest() returns a manifest ONLY after its authenticity has been
 *     verified (ReleaseSignature). An unverifiable manifest is an exception,
 *     never a manifest with a flag on it.
 *   - download() continues fetching exactly $manifest->packageUrl into the
 *     partial file of $download until the package is complete or $deadline
 *     (a microtime()) has passed, and never lets it grow past
 *     $manifest->size bytes. It resumes only where it can prove the bytes
 *     continue the ones on disk, and otherwise starts over at byte 0
 *     (PackageDownload). It does not have to check the hash;
 *     ReleasePackage::verify() does that, always, before anything is
 *     extracted.
 */
interface UpdateSource
{
    /**
     * @throws UpdateException
     */
    public function latest(): ReleaseManifest;

    /**
     * @throws UpdateException
     */
    public function download(ReleaseManifest $manifest, PackageDownload $download, float $deadline): void;

    /** Where this source reads from, safe to show and log (no secrets). */
    public function describe(): string;
}
