<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Unit;

use Lemonade\Admin\Editor\AdminEditor\AdminEditorBlock;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorDefinition;
use Lemonade\Admin\Editor\AdminEditor\Field\ActionSelectField;
use Lemonade\Admin\Editor\AdminEditor\Field\AdminEditorFieldDefinition;
use Lemonade\Admin\Editor\AdminEditor\Field\SelectField;
use Lemonade\Admin\Editor\AdminEditor\Field\TextareaField;
use Lemonade\Admin\Editor\AdminEditor\Field\UrlSegmentField;
use Lemonade\Admin\Editor\AdminEditor\Header\AdminEditorHeaderContextDefinition;
use Lemonade\Admin\Editor\AdminEditor\Header\AdminEditorHeaderContextItem;
use Lemonade\Admin\Editor\AdminEditor\Layout\CustomViewBlock;
use Lemonade\Admin\Editor\AdminEditor\Layout\FieldGroupBlock;
use Lemonade\Admin\Editor\AdminEditor\Layout\SectionBlock;
use Lemonade\Admin\Module\AdminModuleRegistry;
use Lemonade\Admin\Module\AdminModuleRouteResolver;
use Lemonade\Admin\Navigation\AdminNavigationGroupRegistry;
use Lemonade\Admin\Presentation\AdminFilePresentation;
use Lemonade\Admin\Presentation\AdminFileUploadCollection;
use Lemonade\Admin\Presentation\AdminFileUploadProfilePresentation;
use Lemonade\Cms\News\Editor\NewsAdminEditorDefinitionFactory;
use Lemonade\Cms\News\ModuleManifest;
use Lemonade\Framework\Routing\ControllerAction;
use Lemonade\Framework\Routing\Router;
use Lemonade\Framework\Routing\UrlGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the structure and capability-dependent projection of the News editor
 */
final class NewsAdminEditorDefinitionFactoryTest extends TestCase
{
    public function testContentFieldUsesSharedRichTextAndUploadCapabilities(): void
    {
        $editor = $this->factory()->create(
            article: [
                'id' => 16,
                'translations' => [[
                    'locale' => 'en',
                    'title' => 'News',
                    'content' => '<p>Content</p>',
                    'canonical_path' => 'stable-news',
                    'tags' => [],
                ]],
            ],
            contentLocale: 'en',
            canPublish: false,
            tagOptions: [],
        );

        $contentSections = array_values(array_filter(
            $editor->tabs()[0]->blocks(),
            static fn(mixed $block): bool => $block instanceof SectionBlock && $block->id() === 'content-body',
        ));
        self::assertCount(1, $contentSections);
        self::assertInstanceOf(FieldGroupBlock::class, $contentSections[0]->blocks()[0]);
        $contentField = $contentSections[0]->blocks()[0]->columns()[0]->field();

        self::assertInstanceOf(TextareaField::class, $contentField);
        self::assertTrue($contentField->isRichText());
        self::assertTrue($contentField->hasImageUpload());
        self::assertTrue($contentField->hasFileUpload());
    }

    public function testUrlSegmentAutoGenerationDependsOnCanonicalRouteExistence(): void
    {
        $factory = $this->factory();
        $existingRoute = $factory->create(
            article: [
                'id' => 16,
                'translations' => [[
                    'locale' => 'en',
                    'title' => 'Existing article',
                    'canonical_path' => 'stable-news',
                    'tags' => [],
                ]],
            ],
            contentLocale: 'en',
            canPublish: false,
            tagOptions: [],
        );
        $newRoute = $factory->create(
            article: [
                'id' => 17,
                'translations' => [[
                    'locale' => 'en',
                    'title' => 'New article',
                    'canonical_path' => null,
                    'tags' => [],
                ]],
            ],
            contentLocale: 'en',
            canPublish: false,
            tagOptions: [],
        );

        $existingUrl = $this->urlField($existingRoute);
        $newUrl = $this->urlField($newRoute);

        self::assertSame('change-url', $existingUrl->actionKey());
        self::assertFalse($existingUrl->autoGenerates());
        self::assertTrue($newUrl->autoGenerates());
    }

    public function testHeaderFormAndSaveBarUseSharedAdminContracts(): void
    {
        $context = new AdminEditorHeaderContextDefinition('Content language', [
            new AdminEditorHeaderContextItem('English', '/en', active: true),
        ]);
        $editor = $this->factory()->create(
            article: [
                'id' => 23,
                'translations' => [[
                    'locale' => 'en',
                    'title' => 'Title',
                    'canonical_path' => null,
                    'tags' => [],
                ]],
            ],
            contentLocale: 'en',
            canPublish: false,
            context: $context,
            tagOptions: [],
        );

        $header = $editor->header();
        self::assertNotNull($header);
        self::assertSame($context, $header->context());
        self::assertTrue($header->context()->items()[0]->active());
        self::assertSame('news.editor.title', $header->titleKey());
        self::assertSame('news.module.name', $header->breadcrumbs()[0]['labelKey'] ?? null);
        self::assertSame('/admin/cms/news', $header->actions()[0]->href());
        self::assertTrue($header->actions()[1]->submit());

        $saveBar = $editor->saveBar();
        self::assertNotNull($saveBar);
        self::assertSame('admin.editor.unsaved_changes', $saveBar->titleKey());
        self::assertSame('admin.editor.unsaved_changes_help', $saveBar->descriptionKey());
        self::assertSame('admin.common.save_changes', $saveBar->primaryAction()->labelKey());
        self::assertSame('admin.common.discard', $saveBar->secondaryAction()?->labelKey());
        self::assertSame('save', $editor->form()->actionKey());
    }

    public function testContentSeoAndSettingsFieldsRemainExplicit(): void
    {
        $editor = $this->factory()->create(
            article: [
                'id' => 23,
                'translations' => [[
                    'locale' => 'en',
                    'title' => 'Title',
                    'canonical_path' => null,
                    'tags' => ['Existing'],
                ]],
            ],
            contentLocale: 'en',
            canPublish: true,
            tagOptions: ['New', 'Existing'],
        );

        self::assertSame(
            ['content', 'seo', 'settings', 'gallery', 'attachments'],
            array_map(static fn($tab): string => $tab->id(), $editor->tabs()),
        );
        self::assertTrue($editor->tabs()[0]->default());
        self::assertSame(
            ['content-basic', 'content-body'],
            array_map(
                static fn($section): string => $section instanceof SectionBlock ? $section->id() : '',
                $editor->tabs()[0]->blocks(),
            ),
        );
        self::assertSame(
            ['recommendation-settings', 'display-settings'],
            array_map(
                static fn($section): string => $section instanceof SectionBlock ? $section->id() : '',
                $editor->tabs()[2]->blocks(),
            ),
        );
        self::assertSame(
            ['locale', 'title', 'author_name', 'tags', 'summary', 'content'],
            $this->fieldNames($editor->tabs()[0]->blocks()),
        );
        self::assertSame(
            ['recommended', 'show_author', 'sharing_enabled', 'show_published_at', 'show_thumbnail', 'show_reading_time', 'show_tags'],
            $this->fieldNames($editor->tabs()[2]->blocks()),
        );
        self::assertSame(
            ['page_title', 'meta_description', 'url_segment'],
            $this->fieldNames($editor->tabs()[1]->blocks()),
        );

        $contentSection = $editor->tabs()[0]->blocks()[0];
        self::assertInstanceOf(SectionBlock::class, $contentSection);
        $localeField = $contentSection->blocks()[0];
        self::assertInstanceOf(AdminEditorFieldDefinition::class, $localeField);
        self::assertSame('en', $localeField->structuralDefaultValue());
        $tagGroup = $contentSection->blocks()[1];
        self::assertInstanceOf(FieldGroupBlock::class, $tagGroup);
        $tagField = $tagGroup->columns()[2]->field();
        self::assertInstanceOf(SelectField::class, $tagField);
        self::assertTrue($tagField->isMultiple());
        self::assertSame(['Existing' => 'Existing', 'New' => 'New'], $tagField->selectOptions());
        self::assertSame('news.fields.tags_help', $tagField->helpTranslationKey());

        $seoSection = $editor->tabs()[1]->blocks()[0];
        self::assertInstanceOf(SectionBlock::class, $seoSection);
        self::assertSame('admin.seo.tab', $editor->tabs()[1]->labelKey());
        self::assertSame('admin.seo.section', $seoSection->titleKey());
    }

    public function testMediaTabsRenderProvidedUploadCollections(): void
    {
        $upload = $this->uploadCollection();
        $editor = $this->factory()->create(
            article: [
                'id' => 23,
                'translations' => [[
                    'locale' => 'en',
                    'title' => 'Media article',
                    'canonical_path' => null,
                    'tags' => [],
                ]],
            ],
            contentLocale: 'en',
            canPublish: false,
            tagOptions: [],
            galleryUpload: $upload,
            attachmentUpload: $upload,
        );

        foreach ([3 => 'gallery', 4 => 'attachments'] as $index => $id) {
            $section = $editor->tabs()[$index]->blocks()[0];
            self::assertInstanceOf(SectionBlock::class, $section);
            self::assertSame($id, $section->id());
            self::assertSame('news.editor.sections.' . $id . '_help', $section->descriptionKey());
            $uploadView = $section->blocks()[0];
            self::assertInstanceOf(CustomViewBlock::class, $uploadView);
            self::assertSame('admin::components.file-upload-collection', $uploadView->view());
            self::assertSame($upload, $uploadView->context()['uploadCollection']);
        }
    }

    public function testSidebarProjectsPublicationOwnershipAuditAndThumbnail(): void
    {
        $upload = $this->uploadCollection();
        $editor = $this->factory()->create(
            article: [
                'id' => 23,
                'created_by_name' => 'Creator',
                'created_at' => '2026-01-01',
                'updated_by_name' => 'Editor',
                'updated_at' => '2026-01-02',
                'owner_name' => 'Owner',
                'deleted_by_name' => 'Remover',
                'deleted_at' => '2026-01-03',
                'translations' => [[
                    'locale' => 'en',
                    'title' => 'Sidebar article',
                    'canonical_path' => null,
                    'tags' => [],
                ]],
            ],
            contentLocale: 'en',
            canPublish: true,
            tagOptions: [],
            canAssignOwner: true,
            ownerOptions: [7 => 'New owner'],
            featuredImageUpload: $upload,
        );

        $panels = $editor->sidebar()?->panels();
        self::assertNotNull($panels);
        self::assertSame(['publication', 'ownership', 'thumbnail'], array_map(
            static fn($panel): string => $panel->id(),
            $panels,
        ));

        $publicationFields = $panels[0]->blocks()[0];
        self::assertInstanceOf(FieldGroupBlock::class, $publicationFields);
        self::assertSame(['publication_state', 'published_at'], array_map(
            static function ($column): string {
                $field = $column->field();
                self::assertInstanceOf(AdminEditorFieldDefinition::class, $field);

                return $field->name();
            },
            $publicationFields->columns(),
        ));
        self::assertInstanceOf(SelectField::class, $publicationFields->columns()[0]->field());
        self::assertSame('news.editor.sections.publication_help', $panels[0]->descriptionKey());

        $auditView = $panels[1]->blocks()[0];
        self::assertInstanceOf(CustomViewBlock::class, $auditView);
        self::assertSame('admin::components.editor-audit-metadata', $auditView->view());
        $metadata = $auditView->context()['metadata'];
        self::assertSame(['Creator', 'Editor', 'Owner', 'Remover'], array_column($metadata, 'value'));
        self::assertSame('2026-01-03', $metadata[3]['detail']);

        $ownerGroup = $panels[1]->blocks()[1];
        self::assertInstanceOf(FieldGroupBlock::class, $ownerGroup);
        $ownerField = $ownerGroup->columns()[0]->field();
        self::assertInstanceOf(ActionSelectField::class, $ownerField);
        self::assertSame('assign-owner', $ownerField->actionKey());
        self::assertSame([7 => 'New owner'], $ownerField->selectOptions());
        self::assertSame('news.actions.assign_owner_submit', $ownerField->actionTranslationKey());

        $thumbnailView = $panels[2]->blocks()[0];
        self::assertInstanceOf(CustomViewBlock::class, $thumbnailView);
        self::assertSame($upload, $thumbnailView->context()['uploadCollection']);
    }

    public function testRestrictedEditorOmitsUnavailablePublicationOwnershipAndMediaControls(): void
    {
        $editor = $this->factory()->create(
            article: [
                'id' => 23,
                'translations' => [[
                    'locale' => 'en',
                    'title' => 'Restricted article',
                    'canonical_path' => null,
                    'tags' => [],
                ]],
            ],
            contentLocale: 'en',
            canPublish: false,
            tagOptions: [],
            canAssignOwner: false,
        );

        self::assertSame(
            ['ownership'],
            array_map(static fn($panel): string => $panel->id(), $editor->sidebar()?->panels() ?? []),
        );
        self::assertCount(1, $editor->sidebar()?->panels()[0]->blocks() ?? []);

        $emptyGallery = $editor->tabs()[3]->blocks()[0];
        $emptyAttachments = $editor->tabs()[4]->blocks()[0];
        self::assertInstanceOf(SectionBlock::class, $emptyGallery);
        self::assertInstanceOf(SectionBlock::class, $emptyAttachments);
        self::assertSame([], $emptyGallery->blocks());
        self::assertSame([], $emptyAttachments->blocks());
        self::assertSame('admin.file_upload.gallery', $emptyGallery->titleKey());
        self::assertSame('admin.file_upload.attachments', $emptyAttachments->titleKey());
    }

    private function factory(): NewsAdminEditorDefinitionFactory
    {
        $router = new Router();
        $target = ControllerAction::for(self::class, 'testHeaderFormAndSaveBarUseSharedAdminContracts');
        $router->getNamed('admin.cms.module.index', '/admin/cms/{module}', $target);
        $router->postNamed('admin.cms.module.update', '/admin/cms/{module}/edit/{id}', $target);
        $router->postNamed('admin.cms.module.ajax.entity', '/admin/cms/{module}/ajax/{id}', $target);

        return new NewsAdminEditorDefinitionFactory(new UrlGenerator($router), $this->routes());
    }

    private function uploadCollection(): AdminFileUploadCollection
    {
        return new AdminFileUploadCollection(
            moduleCode: 'news',
            entityId: 23,
            usage: 'gallery',
            files: [],
            startUrl: '/start',
            appendUrlTemplate: '/append',
            completeUrlTemplate: '/complete',
            abortUrlTemplate: '/abort',
            removeUrlTemplate: '/remove',
            reorderUrl: '/reorder',
            renameModalUrlTemplate: '/rename',
            labelKey: 'gallery',
            helpKey: 'gallery_help',
            kind: 'image',
            multiple: true,
            sortable: true,
            editable: true,
            profile: new AdminFileUploadProfilePresentation('image/*', ['jpg'], 1000, '1 KB'),
            presentation: AdminFilePresentation::Standard,
            fallback: null,
            alt: '',
        );
    }

    private function urlField(AdminEditorDefinition $editor): UrlSegmentField
    {
        $seoSection = $editor->tabs()[1]->blocks()[0];
        self::assertInstanceOf(SectionBlock::class, $seoSection);
        $seoFields = $seoSection->blocks()[0];
        self::assertInstanceOf(FieldGroupBlock::class, $seoFields);
        $urlField = $seoFields->columns()[2]->field();
        self::assertInstanceOf(UrlSegmentField::class, $urlField);

        return $urlField;
    }

    /**
     * @param list<AdminEditorBlock> $sections
     * @return list<string>
     */
    private function fieldNames(array $sections): array
    {
        $names = [];
        foreach ($sections as $section) {
            self::assertInstanceOf(SectionBlock::class, $section);
            foreach ($section->blocks() as $block) {
                if ($block instanceof AdminEditorFieldDefinition) {
                    $names[] = $block->name();

                    continue;
                }
                if (!$block instanceof FieldGroupBlock) {
                    continue;
                }
                foreach ($block->columns() as $column) {
                    $field = $column->field();
                    self::assertInstanceOf(AdminEditorFieldDefinition::class, $field);
                    $names[] = $field->name();
                }
            }
        }

        return $names;
    }

    private function routes(): AdminModuleRouteResolver
    {
        $adminModules = new AdminModuleRegistry(new AdminNavigationGroupRegistry());
        $adminModules->register(new ModuleManifest());

        return new AdminModuleRouteResolver($adminModules);
    }
}
