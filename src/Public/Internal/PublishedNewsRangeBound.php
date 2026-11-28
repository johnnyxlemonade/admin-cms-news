<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Public\Internal;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Carries one normalized publication time bound into News selection
 *
 * @internal
 */
final readonly class PublishedNewsRangeBound
{
    private function __construct(
        private bool $from,
        private DateTimeImmutable $value,
    ) {}

    public function isFrom(): bool
    {
        return $this->from;
    }

    public function getValue(): DateTimeImmutable
    {
        return $this->value;
    }

    /**
     * Normalizes one supported public range bound without changing its order
     */
    public static function fromInput(string $bound, string $value): self
    {
        if (!in_array($bound, ['from', 'to'], true)) {
            throw new InvalidArgumentException('Publication range accepts only from and to bounds.');
        }
        if (preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}(?:T[0-9]{2}:[0-9]{2})?$/D', $value) !== 1) {
            throw new InvalidArgumentException('Invalid publication time bound.');
        }

        $format = strlen($value) === 10 ? 'Y-m-d' : 'Y-m-d\TH:i';
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
        if ($date === false || $date->format($format) !== $value) {
            throw new InvalidArgumentException('Invalid publication time bound.');
        }

        if (strlen($value) === 10 && $bound === 'to') {
            $date = $date->setTime(23, 59, 59);
        }

        return new self(
            from: $bound === 'from',
            value: $date,
        );
    }
}
