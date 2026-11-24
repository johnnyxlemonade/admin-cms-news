<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Public;

use Lemonade\Framework\Component\Pagination\PaginationState;

/**
 * Represents one page of published News and its unpaginated count
 */
final readonly class PublishedNewsCollection
{
    /**
     * Creates a page of published News
     *
     * @param list<PublishedNews> $items
     */
    public function __construct(
        private array $items,
        private int $total,
        private int $page,
        private int $perPage,
    ) {}

    /**
     * Returns the articles on this page
     *
     * @return list<PublishedNews>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * Returns the total number of matching articles
     */
    public function getTotal(): int
    {
        return $this->total;
    }

    /**
     * Returns the requested page number
     */
    public function getPage(): int
    {
        return $this->page;
    }

    /**
     * Returns the requested page size
     */
    public function getPerPage(): int
    {
        return $this->perPage;
    }

    /**
     * Creates framework pagination for the host's public URL context
     *
     * @param array<string, scalar|null> $query
     */
    public function getPagination(string $basePath, string $pageName = 'page', array $query = []): PaginationState
    {
        return new PaginationState(
            currentPage: $this->page,
            perPage: $this->perPage,
            total: $this->total,
            pageName: $pageName,
            basePath: $basePath,
            query: $query,
        );
    }
}
