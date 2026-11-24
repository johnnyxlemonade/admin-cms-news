<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Unit;

use Lemonade\Cms\News\Public\PublishedNewsCollection;
use Lemonade\Framework\Component\Pagination\PaginationState;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that a published News page supplies framework pagination state
 */
final class PublishedNewsCollectionTest extends TestCase
{
    /**
     * Preserves page totals and the host's URL context for navigation
     */
    public function testCreatesFrameworkPaginationForPublicRoute(): void
    {
        $collection = new PublishedNewsCollection([], total: 18, page: 2, perPage: 5);

        $pagination = $collection->getPagination(
            basePath: '/news',
            pageName: 'p',
            query: ['tag' => 'community', 'p' => 2],
        );

        self::assertInstanceOf(PaginationState::class, $pagination);
        self::assertSame(2, $pagination->currentPage());
        self::assertSame(5, $pagination->perPage());
        self::assertSame(18, $pagination->total());
        self::assertSame(5, $pagination->offset());
        self::assertSame(4, $pagination->lastPage());
        self::assertSame('/news?tag=community&p=3', $pagination->url(3));
    }
}
