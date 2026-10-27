<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Editor;

use Lemonade\Admin\Action\Presentation\AdminActionFormDefinition;
use Lemonade\Admin\Cms\Content\CmsDisplaySettings;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorBuilder;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorDefinition;
use Lemonade\Admin\Editor\AdminEditor\Field\Field;
use Lemonade\Admin\Editor\AdminEditor\Header\AdminEditorHeaderContextDefinition;
use Lemonade\Admin\Editor\AdminEditor\Layout\AdminEditorSidebarDefinition;
use Lemonade\Admin\Editor\AdminEditor\Layout\AdminEditorTab;
use Lemonade\Admin\Editor\AdminEditor\Layout\CmsEditorHeader;
use Lemonade\Admin\Editor\AdminEditor\Layout\CmsEditorSaveBar;
use Lemonade\Admin\Editor\AdminEditor\Layout\CmsMediaSection;
use Lemonade\Admin\Editor\AdminEditor\Layout\CmsOwnershipSection;
use Lemonade\Admin\Editor\AdminEditor\Layout\CmsPublicationSection;
use Lemonade\Admin\Editor\AdminEditor\Layout\CmsTagsField;
use Lemonade\Admin\Editor\AdminEditor\Layout\FieldColumn;
use Lemonade\Admin\Editor\AdminEditor\Layout\FieldGroupBlock;
use Lemonade\Admin\Editor\AdminEditor\Layout\SectionBlock;
use Lemonade\Admin\Editor\AdminEditor\Layout\SeoSection;
use Lemonade\Admin\Module\AdminModuleRouteResolver;
use Lemonade\Admin\Presentation\AdminFileUploadCollection;
use Lemonade\Framework\Routing\UrlGenerator;

/**
 * Sklada full-page editor Aktualit se shared taby, fieldy a save barem
 */
final readonly class NewsAdminEditorDefinitionFactory
{
    /**
     * Nastavuje generator canonical Admin URL
     */
    public function __construct(
        private UrlGenerator $urls,
        private AdminModuleRouteResolver $routes,
    ) {}

    /**
     * Vytvori editor pro jednu content mutation a autorizovany publication panel
     *
     * @param array{id:int|null,translations:list<array<string,mixed>>} $article
     * @param list<string> $tagOptions
     * @param array<int,string> $ownerOptions
     */
    public function create(
        array $article,
        string $contentLocale,
        bool $canPublish,
        bool $canAssignOwner = false,
        array $ownerOptions = [],
        ?AdminEditorHeaderContextDefinition $context = null,
        ?AdminFileUploadCollection $featuredImageUpload = null,
        ?AdminFileUploadCollection $galleryUpload = null,
        ?AdminFileUploadCollection $attachmentUpload = null,
        array $tagOptions = [],
    ): AdminEditorDefinition {
        $articleId = $article['id'];
        $translation = $article['translations'][0] ?? [];
        $indexUrl = $this->urls->route($this->routes->managementRouteName('news', 'index'), ['module' => 'news']);
        $builder = AdminEditorBuilder::create('cms.news')
            ->form($this->editorForm((int) $articleId, $contentLocale))
            ->header(CmsEditorHeader::create('news', $indexUrl, $context))
            ->saveBar(CmsEditorSaveBar::create());
        $builder
            ->tab($this->contentTab($translation, $contentLocale, $tagOptions))
            ->tab(SeoSection::tab(pathPrefix: '/', autoGenerateUrl: trim((string) ($translation['canonical_path'] ?? '')) === ''))
            ->tab(CmsDisplaySettings::tab())
            ->tab(CmsMediaSection::gallery('news', $galleryUpload))
            ->tab(CmsMediaSection::attachments('news', $attachmentUpload));

        $sidebarSections = [];
        $publication = CmsPublicationSection::create('news', $canPublish);
        if ($publication !== null) {
            $sidebarSections[] = $publication;
        }
        $sidebarSections[] = CmsOwnershipSection::create('news', $article, $canAssignOwner, $ownerOptions);
        $thumbnail = CmsMediaSection::thumbnail('news', $featuredImageUpload);
        if ($thumbnail !== null) {
            $sidebarSections[] = $thumbnail;
        }
        $builder->sidebar(new AdminEditorSidebarDefinition($sidebarSections));

        return $builder->build();
    }

    /**
     * Sestavi transportni formular pro ulozeni existujiciho News draftu
     */
    private function editorForm(int $articleId, string $contentLocale): AdminActionFormDefinition
    {
        $routeParams = ['module' => 'news', 'id' => $articleId, 'locale' => $contentLocale];

        return new AdminActionFormDefinition(
            id: 'news-editor-form',
            action: $this->urls->route(name: $this->routes->managementRouteName('news', 'update'), params: $routeParams),
            actionUrl: $this->urls->route(name: $this->routes->managementRouteName('news', 'ajax.entity'), params: $routeParams),
            actionKey: 'save',
            novalidate: true,
        );
    }

    /**
     * Sestavi lokalizovany obsah a rich-text body editoru
     *
     * @param array<string,mixed> $translation
     * @param list<string> $tagOptions
     */
    private function contentTab(array $translation, string $contentLocale, array $tagOptions): AdminEditorTab
    {
        return new AdminEditorTab(
            id: 'content',
            label: '',
            blocks: [
                new SectionBlock(
                    id: 'content-basic',
                    blocks: [
                        Field::hidden('locale')->defaultValue($contentLocale),
                        new FieldGroupBlock([
                            new FieldColumn(
                                Field::text('title', labelKey: 'news.fields.title')
                                    ->required()
                                    ->attributes(['maxlength' => '180']),
                                md: 12,
                            ),
                            new FieldColumn(
                                Field::text('author_name', labelKey: 'news.fields.author_name')
                                    ->attributes(['maxlength' => '255']),
                                md: 12,
                            ),
                            CmsTagsField::column('news', $tagOptions, $translation['tags'] ?? []),
                            new FieldColumn(
                                Field::textarea('summary', labelKey: 'news.fields.summary'),
                                md: 12,
                            ),
                        ]),
                    ],
                    title: '',
                    titleKey: 'news.editor.sections.localized_content',
                    descriptionKey: 'news.editor.sections.localized_content_help',
                ),
                new SectionBlock(
                    id: 'content-body',
                    blocks: [
                        new FieldGroupBlock([
                            new FieldColumn(
                                Field::textarea('content', labelKey: 'news.fields.content')
                                    ->richText(imageUpload: true, fileUpload: true),
                                md: 12,
                            ),
                        ]),
                    ],
                    title: '',
                    titleKey: 'news.editor.sections.content',
                    descriptionKey: 'news.editor.sections.content_help',
                ),
            ],
            labelKey: 'news.editor.tabs.content',
            default: true,
        );
    }
}
