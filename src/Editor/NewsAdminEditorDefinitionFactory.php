<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Editor;

use Lemonade\Admin\Action\Presentation\AdminActionFormDefinition;
use Lemonade\Admin\Editor\AdminEditor\Action\AdminEditorActionDefinition;
use Lemonade\Admin\Editor\AdminEditor\Action\AdminEditorSaveBarDefinition;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorBuilder;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorDefinition;
use Lemonade\Admin\Editor\AdminEditor\Field\Field;
use Lemonade\Admin\Editor\AdminEditor\Header\AdminEditorHeaderContextDefinition;
use Lemonade\Admin\Editor\AdminEditor\Header\AdminEditorHeaderDefinition;
use Lemonade\Admin\Editor\AdminEditor\Layout\AdminEditorSidebarDefinition;
use Lemonade\Admin\Editor\AdminEditor\Layout\AdminEditorTab;
use Lemonade\Admin\Editor\AdminEditor\Layout\CustomViewBlock;
use Lemonade\Admin\Editor\AdminEditor\Layout\FieldColumn;
use Lemonade\Admin\Editor\AdminEditor\Layout\FieldGroupBlock;
use Lemonade\Admin\Editor\AdminEditor\Layout\SectionBlock;
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
        $builder = AdminEditorBuilder::create('cms.news')
            ->form($this->editorForm((int) $articleId, $contentLocale))
            ->header($this->header($context))
            ->saveBar(new AdminEditorSaveBarDefinition(
                primaryAction: new AdminEditorActionDefinition(
                    label: '',
                    labelKey: 'admin.common.save_changes',
                    submit: true,
                    primary: true,
                ),
                secondaryAction: new AdminEditorActionDefinition(
                    label: '',
                    labelKey: 'admin.common.discard',
                ),
                titleKey: 'admin.editor.unsaved_changes',
                descriptionKey: 'admin.editor.unsaved_changes_help',
            ));
        $builder
            ->tab($this->contentTab($translation, $contentLocale, $tagOptions))
            ->tab($this->seoTab($translation))
            ->tab($this->settingsTab())
            ->tab($this->mediaTab('gallery', 'admin.file_upload.gallery', 'admin.file_upload.gallery', $galleryUpload))
            ->tab($this->mediaTab('attachments', 'admin.file_upload.attachments', 'admin.file_upload.attachments', $attachmentUpload));

        $sidebarSections = $this->sidebarSections(
            article: $article,
            canPublish: $canPublish,
            canAssignOwner: $canAssignOwner,
            ownerOptions: $ownerOptions,
            featuredImageUpload: $featuredImageUpload,
        );
        if ($sidebarSections !== []) {
            $builder->sidebar(new AdminEditorSidebarDefinition($sidebarSections));
        }

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
     * Sestavi shared hlavicku s breadcrumbem, akcemi a content locale contextem
     */
    private function header(?AdminEditorHeaderContextDefinition $context): AdminEditorHeaderDefinition
    {
        $indexUrl = $this->urls->route(
            name: $this->routes->managementRouteName('news', 'index'),
            params: ['module' => 'news'],
        );
        $titleKey = 'news.editor.title';

        return new AdminEditorHeaderDefinition(
            title: '',
            titleKey: $titleKey,
            breadcrumbs: [
                [
                    'label' => '',
                    'labelKey' => 'news.module.name',
                    'href' => $indexUrl,
                ],
                [
                    'label' => '',
                    'labelKey' => $titleKey,
                    'current' => true,
                ],
            ],
            actions: [
                new AdminEditorActionDefinition(
                    label: '',
                    labelKey: 'admin.common.back',
                    href: $indexUrl,
                ),
                new AdminEditorActionDefinition(
                    label: '',
                    labelKey: 'admin.common.save',
                    submit: true,
                    primary: true,
                ),
            ],
            context: $context,
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
                            new FieldColumn(
                                Field::select('tags', labelKey: 'news.fields.tags')
                                    ->help('', 'news.fields.tags_help')
                                    ->multiple()
                                    ->options($this->tagOptions($tagOptions, $translation['tags'] ?? []))
                                    ->attributes([
                                        'data-lemonade-searchable' => true,
                                        'data-lemonade-allow-create' => true,
                                    ]),
                                md: 12,
                            ),
                            new FieldColumn(
                                Field::textarea('summary', labelKey: 'news.fields.summary'),
                                md: 12,
                            ),
                        ]),
                    ],
                    title: '',
                    titleKey: 'news.editor.sections.localized_content',
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
                ),
            ],
            labelKey: 'news.editor.tabs.content',
            default: true,
        );
    }

    /**
     * Sestavi lokalizovana SEO metadata editoru
     *
     * @param array<string,mixed> $translation
     */
    private function seoTab(array $translation): AdminEditorTab
    {
        return new AdminEditorTab(
            id: 'seo',
            label: '',
            blocks: [
                new SectionBlock(
                    id: 'seo',
                    blocks: [
                        new FieldGroupBlock([
                            new FieldColumn(
                                Field::text('page_title', labelKey: 'news.fields.page_title')
                                    ->attributes(['maxlength' => '180'])
                                    ->help('', 'news.fields.page_title_help'),
                                md: 12,
                            ),
                            new FieldColumn(
                                Field::textarea('meta_description', labelKey: 'news.fields.meta_description')
                                    ->attributes(['maxlength' => '255'])
                                    ->help('', 'news.fields.meta_description_help'),
                                md: 12,
                            ),
                            new FieldColumn(
                                Field::urlSegment('url_segment')
                                    ->attributes(['maxlength' => '180'])
                                    ->prefix('/')
                                    ->source(
                                        field: 'title',
                                        autoGenerate: trim((string) ($translation['canonical_path'] ?? '')) === '',
                                    )
                                    ->action('change-url'),
                                md: 12,
                            ),
                        ]),
                    ],
                    title: '',
                    titleKey: 'news.editor.sections.seo',
                    description: '',
                    descriptionKey: 'news.editor.sections.seo_help',
                ),
            ],
            labelKey: 'news.editor.tabs.seo',
        );
    }

    /**
     * Sestavi root nastaveni publikacniho zobrazeni
     *
     */
    private function settingsTab(): AdminEditorTab
    {
        return new AdminEditorTab(
            id: 'settings',
            label: '',
            blocks: [
                new SectionBlock(
                    id: 'recommendation-settings',
                    blocks: [
                        new FieldGroupBlock([
                            new FieldColumn(
                                Field::toggle('recommended', labelKey: 'news.fields.recommended')
                                    ->help('', 'news.fields.recommended_help'),
                                md: 12,
                            ),
                        ]),
                    ],
                    title: '',
                    titleKey: 'news.editor.sections.recommendation',
                ),
                new SectionBlock(
                    id: 'display-settings',
                    blocks: [
                        new FieldGroupBlock([
                            $this->displaySettingField(
                                name: 'show_author',
                                labelKey: 'news.fields.show_author',
                                helpKey: 'news.fields.show_author_help',
                            ),
                            $this->displaySettingField(
                                name: 'sharing_enabled',
                                labelKey: 'news.fields.sharing_enabled',
                                helpKey: 'news.fields.sharing_enabled_help',
                            ),
                            $this->displaySettingField(
                                name: 'show_published_at',
                                labelKey: 'news.fields.show_published_at',
                                helpKey: 'news.fields.show_published_at_help',
                            ),
                            $this->displaySettingField(
                                name: 'show_thumbnail',
                                labelKey: 'news.fields.show_thumbnail',
                                helpKey: 'news.fields.show_thumbnail_help',
                            ),
                            $this->displaySettingField(
                                name: 'show_reading_time',
                                labelKey: 'news.fields.show_reading_time',
                                helpKey: 'news.fields.show_reading_time_help',
                            ),
                            $this->displaySettingField(
                                name: 'show_tags',
                                labelKey: 'news.fields.show_tags',
                                helpKey: 'news.fields.show_tags_help',
                            ),
                        ]),
                    ],
                    title: '',
                    titleKey: 'news.editor.sections.display',
                ),
            ],
            labelKey: 'news.editor.tabs.settings',
        );
    }

    /**
     * Sestavi zatim neimplementovanou content capability jako informativni tab
     */
    private function placeholderTab(string $id, string $labelKey, string $titleKey): AdminEditorTab
    {
        return new AdminEditorTab(
            id: $id,
            label: '',
            blocks: [
                new SectionBlock(
                    id: $id,
                    blocks: [],
                    title: '',
                    titleKey: $titleKey,
                    description: '',
                    descriptionKey: 'news.editor.not_implemented',
                ),
            ],
            labelKey: $labelKey,
        );
    }

    /**
     * Sestavi article-global media tab nad shared Admin upload collection contractem
     */
    private function mediaTab(string $id, string $labelKey, string $titleKey, ?AdminFileUploadCollection $upload): AdminEditorTab
    {
        if ($upload === null) {
            return $this->placeholderTab($id, $labelKey, $titleKey);
        }

        return new AdminEditorTab(
            id: $id,
            label: '',
            blocks: [
                new SectionBlock(
                    id: $id,
                    blocks: [new CustomViewBlock('admin::components.file-upload-collection', ['uploadCollection' => $upload])],
                    title: '',
                    titleKey: $titleKey,
                ),
            ],
            labelKey: $labelKey,
        );
    }

    /**
     * Sestavi publication panel a pripraveny shared upload hlavniho obrazku
     *
     * @param array<string,mixed> $article
     * @param array<int,string> $ownerOptions
     * @return list<SectionBlock>
     */
    private function sidebarSections(
        array $article,
        bool $canPublish,
        bool $canAssignOwner,
        array $ownerOptions,
        ?AdminFileUploadCollection $featuredImageUpload,
    ): array {
        $sections = [];
        if ($canPublish) {
            $sections[] = new SectionBlock(
                id: 'publication',
                blocks: [
                    new FieldGroupBlock([
                        new FieldColumn(
                            Field::select('publication_state', labelKey: 'news.fields.publication_state')
                                ->options(['0' => '', '1' => '', '2' => ''])
                                ->optionLabelKeys([
                                    '0' => 'admin.status.new',
                                    '1' => 'admin.status.draft',
                                    '2' => 'admin.status.published',
                                ]),
                            md: 12,
                        ),
                        new FieldColumn(
                            Field::datetimeLocal('published_at', labelKey: 'news.fields.published_at'),
                            md: 12,
                        ),
                    ]),
                ],
                title: '',
                titleKey: 'news.editor.publication',
            );
        }
        $ownershipFields = [
            new FieldColumn(Field::readonlyDisplay('created_by_name', labelKey: 'news.fields.created_by'), md: 12),
            new FieldColumn(Field::readonlyDisplay('created_at', labelKey: 'news.fields.created_at'), md: 12),
            new FieldColumn(Field::readonlyDisplay('updated_by_name', labelKey: 'news.fields.updated_by'), md: 12),
            new FieldColumn(Field::readonlyDisplay('updated_at', labelKey: 'news.fields.updated_at'), md: 12),
        ];
        $ownershipFields[] = new FieldColumn(
            $canAssignOwner
                ? Field::actionSelect(
                    name: 'owner_id',
                    actionKey: 'assign-owner',
                    labelKey: 'news.fields.owner',
                    actionLabelKey: 'news.actions.assign_owner',
                )->options($ownerOptions)
                : Field::readonlyDisplay('owner_name', labelKey: 'news.fields.owner'),
            md: 12,
        );
        if (($article['deleted_at'] ?? null) !== null) {
            $ownershipFields[] = new FieldColumn(Field::readonlyDisplay('deleted_by_name', labelKey: 'news.fields.deleted_by'), md: 12);
            $ownershipFields[] = new FieldColumn(Field::readonlyDisplay('deleted_at', labelKey: 'news.fields.deleted_at'), md: 12);
        }
        $sections[] = new SectionBlock(
            id: 'ownership',
            blocks: [new FieldGroupBlock($ownershipFields)],
            title: '',
            titleKey: 'news.editor.ownership',
        );
        if ($featuredImageUpload !== null) {
            $sections[] = new SectionBlock(
                id: 'thumbnail',
                blocks: [
                    new CustomViewBlock(
                        view: 'admin::components.file-upload-collection',
                        context: ['uploadCollection' => $featuredImageUpload],
                    ),
                ],
                title: '',
                titleKey: 'news.editor.thumbnail.title',
            );
        }

        return $sections;
    }

    /**
     * Sestavi jeden toggle root display nastaveni v responsivnim sloupci
     *
     */
    private function displaySettingField(string $name, string $labelKey, string $helpKey): FieldColumn
    {
        return new FieldColumn(
            Field::toggle($name, labelKey: $labelKey)
                ->help('', $helpKey),
            md: 6,
        );
    }

    /**
     * Prevede dostupne nazvy stitku na native select options
     *
     * @param list<string> $tagNames
     * @return array<string,string>
     */
    private function tagOptions(array $tagNames, mixed $selectedTags): array
    {
        $options = [];
        if (is_array($selectedTags)) {
            foreach ($selectedTags as $tagName) {
                if (is_string($tagName)) {
                    $options[$tagName] = $tagName;
                }
            }
        }
        foreach ($tagNames as $tagName) {
            $options[$tagName] = $tagName;
        }

        return $options;
    }
}
