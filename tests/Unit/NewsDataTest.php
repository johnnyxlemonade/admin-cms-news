<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Unit;

use DomainException;
use Lemonade\Cms\Content\CmsDomainMapping;
use Lemonade\Cms\News\NewsData;
use PHPUnit\Framework\TestCase;

/**
 * Overuje mapovani a validaci domenovych hodnot aktuality
 */
final class NewsDataTest extends TestCase
{
    public function testMapsAuthorNameToRootColumn(): void
    {
        $data = NewsData::fromEditor(['author_name' => ' Jana ']);
        $mapping = new CmsDomainMapping(NewsData::class);
        self::assertSame([
            'root' => ['author_name' => 'Jana'],
            'localized' => [],
        ], $mapping->values($data));
    }

    public function testRejectsOverlongAuthorWithExistingValidationKey(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('news.validation.author_max_length');
        NewsData::fromEditor(['author_name' => str_repeat('x', 256)]);
    }
}
