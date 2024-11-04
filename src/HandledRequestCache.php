<?php

declare(strict_types=1);

namespace Bakame\Http\CacheStatus;

use Bakame\Http\StructuredFields\Item;
use Bakame\Http\StructuredFields\ItemValidator;
use Bakame\Http\StructuredFields\Parameters;
use Bakame\Http\StructuredFields\StructuredField;
use Bakame\Http\StructuredFields\StructuredFieldError;
use Bakame\Http\StructuredFields\StructuredFieldProvider;
use Bakame\Http\StructuredFields\Token;
use Bakame\Http\StructuredFields\Type;
use Bakame\Http\StructuredFields\Validation\ProcessedItem;
use Stringable;

/**
 * A single handled request cache as per RFC9211.
 *
 * @see https://www.rfc-editor.org/rfc/rfc9211.html
 */
final class HandledRequestCache implements StructuredFieldProvider, Stringable
{
    private function __construct(
        public readonly Token|string $servedBy,
        public readonly bool $hit = true,
        public readonly ?Forward  $forward = null,
        public readonly ?int $ttl = null,
        public readonly ?string $key = null,
        public readonly Token|string|null $detail = null,
    ) {
        match (true) {
            !Type::Token->supports($this->servedBy) && !Type::String->supports($this->servedBy) => throw new Exception('The handled request cache identifier must be a Token or a string.'),
            null !== $this->forward && $this->hit  => throw new Exception('The handled request cache can not be both a hit and forwarded.'),
            null === $this->forward && !$this->hit => throw new Exception('The handled request cache must be a hit or forwarded.'),
            null !== $this->key && !Type::String->supports($this->key) => throw new Exception('The `key` parameter must be a string or null.'),
            null !== $this->ttl && !Type::Integer->supports($this->ttl) => throw new Exception('The `ttl` parameter must be a integer or null.'),
            null !== $this->detail && !Type::String->supports($this->detail) && !Type::Token->supports($this->detail)  => throw new Exception('The `detail` parameter must be a string or a Token when present.'),
            default => null,
        };
    }

    /**
     * Returns an instance from a Header Line and the optional response status code.
     */
    public static function fromHttpValue(string $value, ?int $statusCode = null): self
    {
        return self::fromStructuredField(Item::fromHttpValue($value), $statusCode);
    }

    private static function validator(): ItemValidator
    {
        static $validator;

        $validator ??= ItemValidator::new()
            ->value(fn (mixed $value): bool|string => match (true) {
                Type::fromVariable($value)->isOneOf(Type::String, Type::Token) => true,
                default => 'The cache name must be a Token or a string.',
            })
            ->parameters(function (Parameters $parameters): bool|string {
                if (!$parameters->allowedKeys(Parameter::list())) {
                    return 'The cache contains invalid parameters.';
                }

                $hit = !in_array($parameters->valueByKey(Parameter::Hit->value, default: false), [null, false], true);
                $fwd = $parameters->valueByKey(Parameter::Forward->value);

                return match (true) {
                    !$hit && null !== $fwd,
                    $hit && null === $fwd => true,
                    default => "The '".Parameter::Hit->value."' and '".Parameter::Forward->value."' parameters are mutually exclusive.",
                };
            })
            ->parametersByKeys(Parameter::rules());

        return $validator;
    }

    /**
     * Returns an instance from a Structured Field Item and the optional response status code.
     */
    public static function fromStructuredField(Item $item, ?int $statusCode = null): self
    {
        if (null !== $statusCode && ($statusCode < 100 || $statusCode > 599)) {
            throw new Exception('the default forward status code must be a valid HTTP status code when present.');
        }

        $validation = self::validator()->validate($item);
        if ($validation->isFailed()) {
            throw new Exception('The submitted item is an invalid handled request cache status', previous: $validation->errors->toException());
        }

        /** @var ProcessedItem $parsedItem */
        $parsedItem = $validation->data;
        /** @var Token|string $servedBy */
        $servedBy = $parsedItem->value;
        /**
         * @var array{
         *    hit: bool,
         *    ttl: ?int,
         *    key: ?string,
         *    detail: Token|string|null,
         *    fwd: ?Token,
         *    fwd-status: ?int,
         *    collapsed: bool,
         *    stored: bool
         * } $parameters
         */
        $parameters = $parsedItem->parameters;
        $forward = null !== $parameters[Parameter::Forward->value] ? new Forward(
            ForwardedReason::fromToken($parameters[Parameter::Forward->value]),
            $parameters[Parameter::ForwardStatusCode->value] ?? $statusCode,
            $parameters[Parameter::Collapsed->value],
            $parameters[Parameter::Stored->value]
        ) : null;

        return new self(
            $servedBy,
            $parameters[Parameter::Hit->value],
            $forward,
            $parameters[Parameter::TimeToLive->value],
            $parameters[Parameter::Key->value],
            $parameters[Parameter::Detail->value],
        );
    }

    /**
     * Returns a new instance with a server identifier as a string which is already hit.
     */
    public static function serverIdentifierAsString(string $serverIdentifier): self
    {
        return new self($serverIdentifier);
    }

    /**
     * Returns a new instance with a server identifier as a Token which is already hit.
     */
    public static function serverIdentifierAsToken(Token|string $identifier): self
    {
        return new self(match (true) {
            $identifier instanceof Token => $identifier,
            default => Token::fromString($identifier)
        });
    }

    /**
     * The server identifier as a string.
     */
    public function servedBy(): string
    {
        return match (true) {
            $this->servedBy instanceof Token => $this->servedBy->toString(),
            default => $this->servedBy,
        };
    }

    /**
     * @throws StructuredFieldError
     */
    public function __toString(): string
    {
        return $this->toStructuredField()->toHttpValue();
    }

    public function toStructuredField(): StructuredField
    {
        return Item::fromPair([
            $this->servedBy,
            array_filter([
                [Parameter::Hit->value, $this->hit],
                ...($this->forward?->toPairs() ?? [[Parameter::Forward->value, null]]),
                [Parameter::TimeToLive->value, $this->ttl],
                [Parameter::Key->value, $this->key],
                [Parameter::Detail->value, $this->detail],
            ], fn (array $pair): bool => !in_array($pair[1], [null, false], true)),
        ]);
    }

    /**
     * Indicates that the request was satisfied by the cache.
     *
     * The request was not forwarded, and the response was obtained from the cache.
     *
     * This method MUST retain the state of the current instance,
     * and return an instance that contains the specified changes.
     */
    public function wasHit(): self
    {
        return match (true) {
            $this->hit => $this,
            default => new self($this->servedBy, true, null, $this->ttl, $this->key, $this->detail),
        };
    }

    /**
     * Indicates that the request went forward towards the origin.
     *
     * The request was not forwarded, and the response was obtained from the cache.
     *
     * This method MUST retain the state of the current instance,
     * and return an instance that contains the specified changes.
     */
    public function wasForwarded(Forward $forward): self
    {
        return new self(
            $this->servedBy,
            false,
            $forward,
            $this->ttl,
            $this->key,
            $this->detail
        );
    }

    /**
     * Change the response's remaining freshness lifetime as calculated by the cache.
     *
     * This method MUST retain the state of the current instance,
     * and return an instance that contains the specified changes.
     */
    public function withTtl(?int $ttl): self
    {
        return match ($ttl) {
            $this->ttl => $this,
            default => new self($this->servedBy, $this->hit, $this->forward, $ttl, $this->key, $this->detail),
        };
    }

    /**
     * Change the additional information not captured in other parameters as Structured field string.
     *
     * It can be implementation-specific states or other caching-related metrics
     *
     * This method MUST retain the state of the current instance,
     * and return an instance that contains the specified changes.
     */
    public function withDetailAsString(?string $detail): self
    {
        return match ($detail) {
            $this->detail => $this,
            default => new self($this->servedBy, $this->hit, $this->forward, $this->ttl, $this->key, $detail),
        };
    }

    /**
     * Change the additional information not captured in other parameters as Structured field Token.
     *
     * It can be implementation-specific states or other caching-related metrics
     *
     * This method MUST retain the state of the current instance,
     * and return an instance that contains the specified changes.
     */
    public function withDetailAsToken(Token|string|null $detail): self
    {
        $detail = match (true) {
            $detail instanceof Token,
            null === $detail => $detail,
            default => Token::fromString($detail),
        };

        return match (true) {
            $this->detail === $detail,
            $detail?->equals($this->detail) => $this,
            default => new self($this->servedBy, $this->hit, $this->forward, $this->ttl, $this->key, $detail),
        };
    }

    /**
     * Change the cache key representation as a Structured field string.
     *
     * The cache key representation can be implementation-specific
     *
     * This method MUST retain the state of the current instance,
     * and return an instance that contains the specified changes.
     */
    public function withKey(?string $key): self
    {
        return match ($key) {
            $this->key => $this,
            default => new self($this->servedBy, $this->hit, $this->forward, $this->ttl, $key, $this->detail),
        };
    }
}