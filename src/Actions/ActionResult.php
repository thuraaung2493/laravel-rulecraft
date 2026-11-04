<?php

declare(strict_types=1);

namespace Thuraaung\Rulecraft\Actions;

class ActionResult
{
    /** @var bool */
    private $successful;

    /** @var array<array-key, mixed> */
    private $data;

    /** @var string|null */
    private $error;

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function __construct(bool $successful, array $data, ?string $error)
    {
        $this->successful = $successful;
        $this->data = $data;
        $this->error = $error;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function success(array $data = []): self
    {
        return new self(true, $data, null);
    }

    public static function failure(string $error): self
    {
        return new self(false, [], $error);
    }

    public function successful(): bool
    {
        return $this->successful;
    }

    public function failed(): bool
    {
        return ! $this->successful;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    public function error(): ?string
    {
        return $this->error;
    }
}
