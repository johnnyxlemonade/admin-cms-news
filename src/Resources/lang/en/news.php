<?php

declare(strict_types=1);

return [
    'module' => ['name' => 'News'],
    'actions' => ['create' => 'Add news', 'confirm_create' => 'Create', 'edit' => 'Edit', 'delete' => 'Delete', 'restore' => 'Restore', 'deleted' => 'News item deleted', 'restored' => 'News item restored as draft'],
    'fields' => ['title' => 'Article name', 'author_name' => 'Author', 'tags' => 'Tags', 'tags_help' => 'Enter each tag on a separate line.', 'page_title' => 'Page title', 'page_title_help' => 'Recommended length is 50–60 characters. This title appears in search results.', 'summary' => 'Summary', 'content' => 'Content', 'meta_description' => 'Meta description', 'meta_description_help' => 'Recommended length is 120–160 characters. This description appears in search results.', 'thumbnail' => 'Thumbnail', 'recommended' => 'Recommended post', 'recommended_help' => 'The news item will appear in the recommended posts section.', 'show_author' => 'Show author', 'show_author_help' => 'The article displays the author name.', 'sharing_enabled' => 'Enable sharing', 'sharing_enabled_help' => 'Shows article sharing buttons.', 'show_published_at' => 'Show publication date', 'show_published_at_help' => 'The date appears in the article header.', 'show_thumbnail' => 'Show thumbnail', 'show_thumbnail_help' => 'The thumbnail appears in the article detail.', 'show_reading_time' => 'Show reading time', 'show_reading_time_help' => 'Automatically calculated approximate reading time.', 'state' => 'State', 'published_at' => 'Published from', 'updated_at' => 'Updated'],
    'list' => ['title' => 'News', 'description' => 'Manage published and draft news', 'search' => 'Search news…', 'loading' => 'Loading news…', 'empty' => 'There is no news yet', 'active' => 'Active', 'deleted' => 'Deleted'],
    'filters' => ['state' => 'State', 'all_states' => 'All states', 'locale' => 'Language', 'all_locales' => 'All languages'],
    'editor' => [
        'title' => 'Edit news',
        'create_title' => 'New news',
        'content' => 'Content',
        'publication' => 'Publication',
        'locale' => ['label' => 'Content language', 'new_translation' => 'New translation', 'disabled' => 'Disabled language'],
        'tabs' => ['content' => 'Content', 'seo' => 'SEO', 'settings' => 'Settings'],
        'sections' => ['localized_content' => 'Content', 'content' => 'Article content', 'seo' => 'SEO settings', 'seo_help' => 'Control how this news item appears in search results.', 'recommendation' => 'Recommendation', 'display' => 'Display settings'],
        'not_implemented' => 'Not implemented yet',
        'thumbnail' => ['title' => 'Preview image', 'help' => 'The image is used as the primary preview for this news item.'],
        'created' => 'News item created',
        'updated' => 'News item saved',
    ],
    'state' => ['draft' => 'Draft', 'published' => 'Published'],
    'validation' => ['translation_required' => 'Enter a title in at least one language', 'locale_required' => 'Select a language', 'title_required' => 'Title is required', 'title_max_length' => 'Title may not exceed 255 characters', 'author_max_length' => 'Author may not exceed 255 characters', 'page_title_max_length' => 'Page title may not exceed 255 characters', 'meta_description_max_length' => 'Meta description may not exceed 255 characters', 'tag_max_length' => 'A tag may not exceed 255 characters', 'locale_invalid' => 'The selected language is not enabled', 'url_invalid' => 'The URL address is not valid', 'url_taken' => 'This URL address is already used in this language', 'locale_prefix_missing' => 'The selected language has no public news prefix', 'publication_invalid' => 'The publication state is invalid', 'not_found' => 'The news item does not exist', 'save_failed' => 'The news item could not be saved'],
    'confirm' => ['delete' => 'Do you really want to delete this news item?', 'restore' => 'Do you really want to restore this news item as a draft?'],
    'audit' => ['events' => ['created' => 'News item created', 'updated' => 'News item updated', 'translation_created' => 'News language version added', 'translation_updated' => 'News language version updated', 'published' => 'News item published', 'unpublished' => 'News item unpublished', 'deleted' => 'News item deleted', 'restored' => 'News item restored as draft']],
    'permissions' => [
        'view' => 'View news',
        'create' => 'Create news',
        'edit' => 'Edit news',
        'publish' => 'Publish news',
        'delete' => 'Delete news',
        'restore' => 'Restore news',
    ],
];
