<?php

declare(strict_types=1);

namespace App\Update;

/**
 * Ed25519 signatures over the release manifest.
 *
 * WHY A SIGNATURE ON TOP OF HTTPS AND SHA-256. The SHA-256 in the manifest
 * proves the package is the one the manifest names; HTTPS proves the
 * manifest came from the host in the URL. Neither proves the release came
 * from the people who make Mygdala: whoever controls the feed host — or a
 * compromised account on it — could publish a manifest with a matching hash
 * for any ZIP they like. The signature closes that gap. The private key never
 * touches a server; every installation carries only the public half
 * (ReleaseKeys), and the updater refuses a manifest it cannot verify.
 *
 * What is signed: the manifest file's EXACT bytes. No canonical JSON, no
 * re-serialisation: the verifier checks the bytes it downloaded and only
 * then parses them, so there is no second interpretation of "the same
 * manifest" for an attacker to aim at. The package itself is covered
 * transitively: its SHA-256 is inside the signed bytes.
 *
 * The signature travels next to the manifest as manifest.json.sig, a small
 * JSON document naming the algorithm and the key, so a key rotation can be
 * announced by shipping the new public key in a release before the feed
 * starts signing with it.
 *
 * ext-sodium is part of PHP since 7.2 and present on the hosts this project
 * targets; where it is missing the updater refuses rather than falling back
 * to "hash only" (Preflight names the extension).
 */
final class ReleaseSignature
{
    public const ALGORITHM = 'ed25519';

    public static function isSupported(): bool
    {
        return function_exists('sodium_crypto_sign_verify_detached');
    }

    /**
     * @param array<string, string> $trustedKeys key id => raw 32-byte public key
     *
     * @return string the id of the key that verified the signature
     *
     * @throws UpdateException when the signature is missing, malformed, made
     *                         with an unknown key or simply wrong
     */
    public static function verify(string $message, string $signatureDocument, array $trustedKeys): string
    {
        if (!self::isSupported()) {
            throw new UpdateException('update.error.sodium_missing', [], 'ext-sodium is not available');
        }

        if ($trustedKeys === []) {
            throw new UpdateException('update.error.no_trusted_key', [], 'No release public key is configured');
        }

        $document = json_decode($signatureDocument, true);

        if (!is_array($document)
            || ($document['algorithm'] ?? null) !== self::ALGORITHM
            || !is_string($document['key_id'] ?? null)
            || !is_string($document['signature'] ?? null)
        ) {
            throw new UpdateException('update.error.signature_invalid', [], 'Malformed signature document');
        }

        $keyId = $document['key_id'];

        if (!isset($trustedKeys[$keyId])) {
            throw new UpdateException(
                'update.error.signature_unknown_key',
                ['key' => substr(preg_replace('/[^0-9a-f]/', '', $keyId) ?? '', 0, 16)],
                'Signed with a key this installation does not trust: ' . $keyId
            );
        }

        $signature = base64_decode($document['signature'], true);

        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new UpdateException('update.error.signature_invalid', [], 'Signature is not 64 bytes of base64');
        }

        if (!sodium_crypto_sign_verify_detached($signature, $message, $trustedKeys[$keyId])) {
            throw new UpdateException('update.error.signature_invalid', [], 'Signature does not match the manifest');
        }

        return $keyId;
    }

    /**
     * Only the release builder signs (App\Update\Build\ReleaseBuilder).
     *
     * @param string $secretKey raw 64-byte Ed25519 secret key
     */
    public static function sign(string $message, string $secretKey): string
    {
        if (strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \InvalidArgumentException('An Ed25519 secret key is 64 bytes');
        }

        $publicKey = sodium_crypto_sign_publickey_from_secretkey($secretKey);

        return json_encode([
            'algorithm' => self::ALGORITHM,
            'key_id' => self::keyId($publicKey),
            'signature' => base64_encode(sodium_crypto_sign_detached($message, $secretKey)),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /** The first 16 hex digits of the public key's SHA-256: short, stable, not secret. */
    public static function keyId(string $publicKey): string
    {
        return substr(hash('sha256', $publicKey), 0, 16);
    }

    /** @return array{public: string, secret: string} both raw */
    public static function generateKeyPair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            'public' => sodium_crypto_sign_publickey($pair),
            'secret' => sodium_crypto_sign_secretkey($pair),
        ];
    }
}
