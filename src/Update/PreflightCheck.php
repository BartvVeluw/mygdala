<?php

declare(strict_types=1);

namespace App\Update;

/**
 * One line of a preflight report: what was checked, whether it passed, and
 * what the administrator is told about it.
 *
 * Stored in the update state as plain data (toArray/fromArray), because the
 * request that shows a refusal is often not the one that found it.
 */
final class PreflightCheck
{
    public const OK = 'ok';
    public const WARNING = 'warning';
    public const ERROR = 'error';

    /**
     * @param array<string, string|int> $params placeholders for the catalog text
     */
    public function __construct(
        public readonly string $name,
        public readonly string $status,
        public readonly string $messageKey,
        public readonly array $params = [],
        public readonly string $detail = ''
    ) {
    }

    /** @param array<string, string|int> $params */
    public static function ok(string $name, string $messageKey, array $params = []): self
    {
        return new self($name, self::OK, $messageKey, $params);
    }

    /** @param array<string, string|int> $params */
    public static function warning(string $name, string $messageKey, array $params = [], string $detail = ''): self
    {
        return new self($name, self::WARNING, $messageKey, $params, $detail);
    }

    /** @param array<string, string|int> $params */
    public static function error(string $name, string $messageKey, array $params = [], string $detail = ''): self
    {
        return new self($name, self::ERROR, $messageKey, $params, $detail);
    }

    public function isError(): bool
    {
        return $this->status === self::ERROR;
    }

    /** @return array{name: string, status: string, message: string, params: array<string, string|int>, detail: string} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'status' => $this->status,
            'message' => $this->messageKey,
            'params' => $this->params,
            'detail' => $this->detail,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $status = (string) ($data['status'] ?? self::ERROR);

        return new self(
            (string) ($data['name'] ?? ''),
            in_array($status, [self::OK, self::WARNING, self::ERROR], true) ? $status : self::ERROR,
            (string) ($data['message'] ?? ''),
            is_array($data['params'] ?? null) ? $data['params'] : [],
            (string) ($data['detail'] ?? '')
        );
    }
}
