<?php

declare(strict_types=1);

namespace Bakame\Http\CacheStatus;

use Bakame\Http\StructuredFields\Parameters;
use Bakame\Http\StructuredFields\StructuredFieldProvider;
use Bakame\Http\StructuredFields\Token;

use function is_string;

/**
 * @phpstan-import-type SfType from StructuredFieldProvider
 */
final readonly class Forward implements StructuredFieldProvider
{
    public function __construct(
        public ForwardedReason $reason,
        public ?int $statusCode = null,
        public bool $collapsed = false,
        public bool $stored = false,
    ) {
        if (null !== $this->statusCode && ($this->statusCode < 100 || $this->statusCode >= 600)) {
            throw new Exception('The forward statusCode must be a valid HTTP status code when present.');
        }
    }

    public static function fromReason(ForwardedReason|Token|string $reason): self
    {
        return new self(self::filterReason($reason));
    }

    private static function filterReason(ForwardedReason|Token|string $reason): ForwardedReason
    {
        if (is_string($reason)) {
            $reason = Token::tryFromString($reason);
        }

        if ($reason instanceof Token) {
            $reason = ForwardedReason::tryFromToken($reason);
        }

        if (!$reason instanceof ForwardedReason) {
            throw new Exception('Invalid forward reason.');
        }

        return $reason;
    }

    public function reason(ForwardedReason|Token|string $reason): self
    {
        $reason = self::filterReason($reason);

        return match ($reason) {
            $this->reason => $this,
            default => new self($reason, $this->statusCode, $this->collapsed, $this->stored),
        };
    }

    public function statusCode(?int $statusCode): self
    {
        return match (true) {
            $this->statusCode === $statusCode => $this,
            default => new self($this->reason, $statusCode, $this->collapsed, $this->stored),
        };
    }

    public function collapsed(bool $collapsed): self
    {
        return match ($collapsed) {
            $this->collapsed => $this,
            default => new self($this->reason, $this->statusCode, $collapsed, $this->stored),
        };
    }

    public function stored(bool $stored): self
    {
        return match ($stored) {
            $this->stored => $this,
            default => new self($this->reason, $this->statusCode, $this->collapsed, $stored),
        };
    }

    public function toStructuredField(): Parameters
    {
        return Parameters::new()
            ->append(Properties::Forward->value, $this->reason->toToken())
            ->when(null !== $this->statusCode, fn (Parameters $parameters) => $parameters->append(Properties::ForwardStatusCode->value, $this->statusCode)) /* @phpstan-ignore-line */
            ->when($this->stored, fn (Parameters $parameters) => $parameters->append(Properties::Stored->value, $this->stored))
            ->when($this->collapsed, fn (Parameters $parameters) => $parameters->append(Properties::Collapsed->value, $this->collapsed));
    }

    public function equals(mixed $other): bool
    {
        return match (true) {
            !$other instanceof self,
            !$other->reason->equals($this->reason),
            $other->statusCode !== $this->statusCode,
            $other->stored !== $this->stored,
            $other->collapsed !== $this->collapsed => false,
            default => true,
        };
    }
}
