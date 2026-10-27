<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Unit\Services;

use InvalidArgumentException;
use Lemonade\Cms\CmsPublicationChange;
use Lemonade\Cms\CmsTagNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Overuje publikacni a tagovy kontrakt prevzaty spolecnym lifecycle
 */
final class NewsServiceSaveTest extends TestCase
{
    /**
     * @dataProvider publicationScenarios
     */
    public function testPublicationChanges(int $currentState, ?string $currentDate, int $requestedState, string $requestedDate, int $expectedState, ?string $expectedDate, bool $changed): void
    {
        [$state, $date, $wasChanged] = CmsPublicationChange::resolve(true, $requestedState, $requestedDate, $currentState, $currentDate, '2026-01-01 00:00:00');
        self::assertSame($expectedState, $state->value);
        self::assertSame($expectedDate, $date);
        self::assertSame($changed, $wasChanged);
    }

    /**
     * @return iterable<string,array{int,string|null,int,string,int,string|null,bool}>
     */
    public static function publicationScenarios(): iterable
    {
        yield 'scheduled' => [1, null, 2, '2027-01-22T12:30', 2, '2027-01-22 12:30:00', true];
        yield 'unpublish' => [2, '2027-01-22 12:30:00', 1, '', 1, null, true];
        yield 'no op' => [2, '2027-01-22 12:30:00', 2, '2027-01-22T12:30', 2, '2027-01-22 12:30:00', false];
        yield 'new keeps published' => [2, '2027-01-22 12:30:00', 0, '', 2, '2027-01-22 12:30:00', false];
    }

    public function testTagsKeepNormalizedOrder(): void
    {
        self::assertSame(['Česko', 'Praha', 'Brno'], CmsTagNormalizer::normalize("  Česko, Praha\nČesko, Brno "));
    }

    /**
     * @dataProvider invalidPublicationScenarios
     */
    public function testInvalidPublicationIsRejected(mixed $state, mixed $date): void
    {
        $this->expectException(InvalidArgumentException::class);
        CmsPublicationChange::resolve(true, $state, $date, 1, null, '2026-01-01 00:00:00');
    }

    /**
     * @return iterable<string,array{mixed,mixed}>
     */
    public static function invalidPublicationScenarios(): iterable
    {
        yield 'invalid state' => ['9', null];
        yield 'invalid date' => ['2', '2026-02-30T10:00'];
    }

    public function testTagLengthIsValidated(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CmsTagNormalizer::normalize([str_repeat('ě', 256)]);
    }
}
