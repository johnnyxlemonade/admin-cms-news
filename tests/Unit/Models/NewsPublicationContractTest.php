<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Unit\Models;

use Lemonade\Cms\CmsPublicationState;
use Lemonade\Cms\News\Models\NewsModel;
use Lemonade\Framework\Database\DatabaseDriverInterface;
use PHPUnit\Framework\TestCase;

/**
 * Overuje per-locale persistence shared publication lifecycle Aktualit
 */
final class NewsPublicationContractTest extends TestCase
{
    /**
     * Nova minimalni mutace zacina stavem New
     */
    public function testCreateTranslationPersistsNewState(): void
    {
        $bindings = $this->captureBindings(function (NewsModel $news): void {
            $news->saveTranslation(7, 'cs', null, null, null, null, null, null, '2026-10-13 10:00:00');
        });

        self::assertContains(CmsPublicationState::New->value, $bindings);
    }

    /**
     * Bezne ulozeni povysi jen nepublikovanou mutaci na Draft
     */
    public function testSavePromotesTranslationToDraft(): void
    {
        $bindings = $this->captureBindings(function (NewsModel $news): void {
            $news->promoteTranslationToDraft(7, 'cs', '2026-10-13 10:00:00');
        });

        self::assertContains(CmsPublicationState::Draft->value, $bindings);
        self::assertContains('cs', $bindings);
        self::assertContains(CmsPublicationState::Published->value, $bindings);
    }

    /**
     * Publish a unpublish zapisuji publication stav konkretni locale mutace
     */
    public function testPublicationTransitionsRemainScopedToLocale(): void
    {
        $published = $this->captureBindings(function (NewsModel $news): void {
            $news->savePublication(7, 'cs', CmsPublicationState::Published, '2026-10-13 10:00:00', '2026-10-13 10:00:00');
        });
        $draft = $this->captureBindings(function (NewsModel $news): void {
            $news->savePublication(7, 'en', CmsPublicationState::Draft, null, '2026-10-13 10:00:00');
        });

        self::assertContains(CmsPublicationState::Published->value, $published);
        self::assertContains('cs', $published);
        self::assertContains(CmsPublicationState::Draft->value, $draft);
        self::assertContains('en', $draft);
    }

    /**
     * Root metadata persistuji viditelnost lokalizovanych stitku
     */
    public function testCreateDraftPersistsShowTagsSetting(): void
    {
        $capturedSql = '';
        $capturedBindings = [];
        $driver = $this->createMock(DatabaseDriverInterface::class);
        $driver->method('protect_identifiers')->willReturnCallback(static fn(string $identifier): string => $identifier);
        $driver->method('query')->willReturnCallback(
            static function (string $sql, array|false $bindings) use (&$capturedSql, &$capturedBindings): bool {
                $capturedSql = $sql;
                $capturedBindings = $bindings === false ? [] : array_values($bindings);

                return true;
            },
        );
        $driver->method('insert_id')->willReturn(1);

        (new NewsModel($driver))->createDraft(
            null,
            false,
            [
                'show_author' => true,
                'sharing_enabled' => true,
                'show_published_at' => true,
                'show_thumbnail' => true,
                'show_reading_time' => true,
                'show_tags' => false,
            ],
            '2026-10-13 10:00:00',
        );

        self::assertStringContainsString('show_tags', $capturedSql);
        self::assertSame(0, $capturedBindings[7]);
    }

    /**
     * Zachyti bind hodnoty jedne modelove mutace bez databazove infrastruktury
     *
     * @param callable(NewsModel):void $operation
     * @return list<mixed>
     */
    private function captureBindings(callable $operation): array
    {
        $capturedBindings = [];
        $driver = $this->createMock(DatabaseDriverInterface::class);
        $driver->method('protect_identifiers')->willReturnCallback(static fn(string $identifier): string => $identifier);
        $driver->method('query')->willReturnCallback(
            static function (string $sql, array|false $bindings) use (&$capturedBindings): bool {
                $capturedBindings = $bindings === false ? [] : array_values($bindings);

                return true;
            },
        );
        $driver->method('insert_id')->willReturn(1);

        $operation(new NewsModel($driver));

        return $capturedBindings;
    }
}
