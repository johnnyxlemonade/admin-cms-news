<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Public\Internal;

use InvalidArgumentException;

/**
 * Defines validated filters, ordering and pagination for published News selection
 *
 * @internal
 */
final readonly class PublishedNewsCriteria
{
    /**
     * @param list<PublishedNewsRangeBound> $publishedAt
     */
    private function __construct(
        private int $page,
        private int $perPage,
        private ?string $tag,
        private ?bool $recommended,
        private ?string $searchTerm,
        private array $publishedAt,
        private string $sortBy,
        private string $sortDirection,
    ) {}

    /**
     * @param array{from?:string,to?:string} $publishedAt
     */
    public static function fromInput(
        int $page,
        int $perPage,
        ?string $tag,
        ?bool $recommended,
        ?string $search,
        array $publishedAt,
        string $sortBy,
        string $sortDirection,
    ): self {
        self::validatePagination($page, $perPage);

        if (!in_array($sortBy, ['publishedAt', 'title'], true)) {
            throw new InvalidArgumentException('Unsupported News sort key.');
        }
        $direction = strtolower($sortDirection);
        if (!in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('Sort direction must be asc or desc.');
        }
        if (array_diff(array_keys($publishedAt), ['from', 'to']) !== []) {
            throw new InvalidArgumentException('Publication range accepts only from and to bounds.');
        }

        $rangeBounds = [];
        foreach ($publishedAt as $bound => $value) {
            $rangeBounds[] = PublishedNewsRangeBound::fromInput($bound, $value);
        }

        $term = trim($search ?? '');

        return new self(
            page: $page,
            perPage: $perPage,
            tag: $tag,
            recommended: $recommended,
            searchTerm: $term === '' ? null : $term,
            publishedAt: $rangeBounds,
            sortBy: $sortBy,
            sortDirection: $direction,
        );
    }

    /**
     * Preserves pagination validation before public module availability checks
     */
    public static function validatePagination(int $page, int $perPage): void
    {
        if ($page < 1 || $perPage < 1) {
            throw new InvalidArgumentException('Page and page size must be positive.');
        }
    }

    public function getPage(): int
    {
        return $this->page;
    }

    public function getPerPage(): int
    {
        return $this->perPage;
    }

    public function getTag(): ?string
    {
        return $this->tag;
    }

    public function getRecommended(): ?bool
    {
        return $this->recommended;
    }

    public function getSearchTerm(): ?string
    {
        return $this->searchTerm;
    }

    /**
     * @return list<PublishedNewsRangeBound>
     */
    public function getPublishedAt(): array
    {
        return $this->publishedAt;
    }

    public function getSortBy(): string
    {
        return $this->sortBy;
    }

    public function getSortDirection(): string
    {
        return $this->sortDirection;
    }
}
