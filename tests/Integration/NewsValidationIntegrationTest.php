<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Integration;

use Lemonade\Admin\Action\Exception\ModuleActionException;
use Lemonade\Admin\Http\AdminErrorCode;
use Lemonade\Cms\News\Tests\Integration\Support\NewsIntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Verifies validation failures without coupling them to a longer News lifecycle
 */
final class NewsValidationIntegrationTest extends NewsIntegrationTestCase
{
    public function testPublishingAnUntitledDraftLeavesItsPersistedStateUnchanged(): void
    {
        $articleId = $this->createDraft();
        $before = $this->environment()->contentSnapshot();

        $result = $this->environment()->getTransport()->dispatch('news', $articleId, [
            'action' => 'save',
            'payload' => ['locale' => 'cs', 'title' => '', 'publication_state' => '2'],
        ]);

        self::assertArrayHasKey('title', $result->errors());
        self::assertSame($before, $this->environment()->contentSnapshot());
    }

    /**
     * @param array<string,mixed> $invalidFields
     * @param list<string> $expectedErrors
     */
    #[DataProvider('invalidEditorFields')]
    public function testInvalidEditorFieldsLeaveTheDraftUnchanged(
        array $invalidFields,
        array $expectedErrors,
    ): void {
        $articleId = $this->createDraft();
        $before = $this->environment()->contentSnapshot();

        $result = $this->environment()->getTransport()->dispatch('news', $articleId, [
            'action' => 'save',
            'payload' => ['locale' => 'cs', 'title' => 'Valid title'] + $invalidFields,
        ]);

        self::assertSame($expectedErrors, array_keys($result->errors()));
        self::assertSame($before, $this->environment()->contentSnapshot());
    }

    /**
     * @return iterable<string,array{array<string,mixed>,list<string>}>
     */
    public static function invalidEditorFields(): iterable
    {
        yield 'structured rich content' => [
            ['summary' => ['invalid'], 'content' => ['invalid']],
            ['summary', 'content'],
        ];
        yield 'SEO field lengths' => [
            ['page_title' => str_repeat('x', 181), 'meta_description' => str_repeat('x', 256)],
            ['page_title', 'meta_description'],
        ];
    }

    public function testPublishedTranslationCannotLoseItsRequiredTitle(): void
    {
        $articleId = $this->createDraft();
        $transport = $this->environment()->getTransport();
        $transport->dispatch('news', $articleId, ['action' => 'save', 'payload' => [
            'locale' => 'cs',
            'title' => 'Published title',
            'publication_state' => '2',
            'published_at' => '2028-01-02T10:30',
        ]]);
        $before = $this->environment()->contentSnapshot();

        $result = $transport->dispatch('news', $articleId, [
            'action' => 'save',
            'payload' => ['locale' => 'cs', 'title' => ''],
        ]);

        self::assertArrayHasKey('title', $result->errors());
        self::assertSame($before, $this->environment()->contentSnapshot());
    }

    public function testInvalidUrlInputReturnsItsFieldError(): void
    {
        $articleId = $this->createDraft();

        $result = $this->environment()->getTransport()->dispatch('news', $articleId, [
            'action' => 'change-url',
            'payload' => ['locale' => []],
        ]);

        self::assertSame(['url_segment' => 'admin.url_segment.invalid'], $result->errors());
    }

    public function testInvalidOwnerInputReturnsItsFieldError(): void
    {
        $articleId = $this->createDraft();

        $result = $this->environment()->getTransport()->dispatch('news', $articleId, [
            'action' => 'assign-owner',
            'payload' => ['owner_id' => 0],
        ]);

        self::assertSame(['owner_id' => 'news.validation.owner_invalid'], $result->errors());
    }

    public function testNonArrayTransportPayloadIsRejectedBeforeMutation(): void
    {
        $articleId = $this->createDraft();
        $before = $this->environment()->contentSnapshot();

        try {
            $this->environment()->getTransport()->dispatch('news', $articleId, [
                'action' => 'delete',
                'payload' => 'invalid',
            ]);
            self::fail('Invalid transport payload must be rejected.');
        } catch (ModuleActionException $exception) {
            self::assertSame(422, $exception->status());
            self::assertSame(AdminErrorCode::VALIDATION_FAILED, $exception->error());
        }

        self::assertSame($before, $this->environment()->contentSnapshot());
    }

    /**
     */
    #[DataProvider('missingArticleIds')]
    public function testDeleteSafelyRejectsAMissingArticle(?int $articleId): void
    {
        try {
            $result = $this->environment()->getTransport()->dispatch('news', $articleId, [
                'action' => 'delete',
                'payload' => [],
            ]);
            self::assertFalse($result->successful());
        } catch (ModuleActionException $exception) {
            self::assertSame(403, $exception->status());
        }
    }

    /**
     * @return iterable<string,array{int|null}>
     */
    public static function missingArticleIds(): iterable
    {
        yield 'absent entity id' => [null];
        yield 'unknown entity id' => [999999];
    }

    private function createDraft(): int
    {
        $created = $this->environment()->getTransport()->dispatch(
            'news',
            null,
            ['action' => 'create', 'payload' => []],
        );
        self::assertTrue($created->successful());

        return (int) $created->data()['id'];
    }
}
