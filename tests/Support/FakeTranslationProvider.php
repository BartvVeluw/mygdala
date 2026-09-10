<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Service\Translation\TranslationException;
use App\Service\Translation\TranslationProvider;

/**
 * A translation provider that never touches the network.
 *
 * THE TEST SUITE MUST NOT DEPEND ON A LIVE TRANSLATION API. A paid third
 * party that is slow, rate-limited, or simply down would make this project's
 * tests fail for a reason that has nothing to do with this project's code,
 * and it would spend somebody's quota on every run. Everything about the
 * translation feature that is worth testing — the manual-edit rule, the stale
 * detection, the HTML path, the failure path — is this CMS's own behaviour,
 * and this class is enough to exercise all of it.
 *
 * It marks what it "translated" so a test can tell provider output apart from
 * the source text at a glance.
 */
final class FakeTranslationProvider implements TranslationProvider
{
    /** @var list<array{texts: array<string, string>, source: string, target: string, html: bool}> */
    public array $calls = [];

    public function __construct(
        private readonly bool $configured = true,
        /** Throw instead of answering — the controlled-failure path. */
        private readonly ?string $failWith = null,
        /** Answer with this exact text for every field, instead of the marker. */
        private readonly ?string $fixedAnswer = null,
    ) {
    }

    public function key(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function supports(string $source, string $target): bool
    {
        return $this->configured && $source !== $target;
    }

    public function translate(string $text, string $source, string $target, bool $html = false): string
    {
        return $this->translateAll(['value' => $text], $source, $target, $html)['value'] ?? '';
    }

    public function translateAll(array $texts, string $source, string $target, bool $html = false): array
    {
        if ($this->failWith !== null) {
            throw new TranslationException($this->failWith);
        }

        $this->calls[] = [
            'texts' => $texts,
            'source' => $source,
            'target' => $target,
            'html' => $html,
        ];

        $out = [];
        foreach ($texts as $field => $text) {
            $out[$field] = $this->fixedAnswer ?? ('[' . strtoupper($target) . '] ' . $text);
        }

        return $out;
    }

    /** The texts handed over on the most recent call, for an assertion. */
    public function lastTexts(): array
    {
        $last = end($this->calls);

        return $last === false ? [] : $last['texts'];
    }
}
