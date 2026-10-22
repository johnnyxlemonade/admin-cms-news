<?php

declare(strict_types=1);

return [
    'module' => ['name' => 'News', 'description' => 'Manage news and publishing.'],
    'actions' => ['create' => 'Add news', 'confirm_create' => 'Create', 'edit' => 'Edit', 'delete' => 'Delete', 'restore' => 'Restore', 'assign_owner' => 'Transfer ownership', 'assign_owner_submit' => 'Transfer', 'owner_assigned' => 'News ownership transferred', 'deleted' => 'News item deleted', 'restored' => 'News item restored as draft'],
    'fields' => ['title' => 'Article name', 'author_name' => 'Author', 'tags' => 'Tags', 'tags_help' => 'Enter each tag on a separate line.', 'page_title' => 'Page title', 'page_title_help' => 'Recommended length is 50–60 characters. This title appears in search results.', 'summary' => 'Summary', 'content' => 'Content', 'meta_description' => 'Meta description', 'meta_description_help' => 'Recommended length is 120–160 characters. This description appears in search results.', 'thumbnail' => 'Thumbnail', 'recommended' => 'Recommended post', 'recommended_help' => 'The news item will appear in the recommended posts section.', 'show_author' => 'Show author', 'show_author_help' => 'The article displays the author name.', 'sharing_enabled' => 'Enable sharing', 'sharing_enabled_help' => 'Shows article sharing buttons.', 'show_published_at' => 'Show publication date', 'show_published_at_help' => 'The date appears in the article header.', 'show_thumbnail' => 'Show thumbnail', 'show_thumbnail_help' => 'The thumbnail appears in the article detail.', 'show_reading_time' => 'Show reading time', 'show_reading_time_help' => 'Automatically calculated approximate reading time.', 'show_tags' => 'Show tags', 'show_tags_help' => 'Shows tags from the article’s current language version.', 'publication_state' => 'Publication state', 'published_at' => 'Published from', 'created_by' => 'Created by', 'created_at' => 'Created', 'updated_by' => 'Last updated by', 'updated_at' => 'Updated', 'deleted_by' => 'Deleted by', 'deleted_at' => 'Deleted', 'owner' => 'Owner'],
    'list' => ['title' => 'News', 'description' => 'Manage published and draft news', 'search' => 'Search news…', 'loading' => 'Loading news…', 'empty' => 'There is no news yet', 'active' => 'Active', 'deleted' => 'Deleted', 'untitled_locale' => 'Untitled ({locale})'],
    'filters' => ['state' => 'State', 'all_states' => 'All states', 'locale' => 'Language', 'all_locales' => 'All languages'],
    'editor' => [
        'title' => 'Edit news',
        'create_title' => 'New news',
        'content' => 'Content',
        'publication' => 'Publication',
        'ownership' => 'Authorship and ownership',
        'locale' => ['label' => 'Language versions', 'existing_translation' => 'Existing translation', 'new_translation' => 'New translation', 'disabled' => 'Disabled language'],
        'tabs' => ['content' => 'Content', 'seo' => 'SEO', 'settings' => 'Settings'],
        'sections' => ['localized_content' => 'Content', 'localized_content_help' => 'Basic information about the news item', 'content' => 'Article content', 'content_help' => 'Article text and main content', 'seo' => 'SEO settings', 'seo_help' => 'Search engine title, description, and URL settings', 'recommendation' => 'Recommendation', 'recommendation_help' => 'Placement of the news item among recommended content', 'display' => 'Display settings', 'display_help' => 'Additional news item display settings', 'gallery_help' => 'Images assigned to the record', 'attachments_help' => 'Downloadable files and other documents', 'publication_help' => 'Publication status and publishing date', 'ownership_help' => 'Record history and owner management', 'thumbnail_help' => 'Primary news preview in listings and links'],
        'not_implemented' => 'Not implemented yet',
        'thumbnail' => ['title' => 'Preview image', 'help' => 'The image is used as the primary preview for this news item.'],
        'created' => 'News item created',
        'updated' => 'News item saved',
    ],
    'validation' => ['translation_required' => 'Enter a title in at least one language', 'locale_required' => 'Select a language', 'title_required' => 'Title is required', 'title_max_length' => 'Title may not exceed 180 characters', 'author_max_length' => 'Author may not exceed 255 characters', 'page_title_max_length' => 'Page title may not exceed 180 characters', 'meta_description_max_length' => 'Meta description may not exceed 255 characters', 'tag_max_length' => 'A tag may not exceed 255 characters', 'locale_invalid' => 'The selected language is not enabled', 'publication_invalid' => 'The publication state is invalid', 'owner_invalid' => 'The selected owner does not exist or has been removed', 'not_found' => 'The news item does not exist', 'save_failed' => 'The news item could not be saved'],
    'confirm' => ['delete' => 'Do you really want to delete this news item?', 'restore' => 'Do you really want to restore this news item as a draft?'],
    'audit' => ['events' => ['created' => 'News item created', 'updated' => 'News item updated', 'translation_created' => 'News language version added', 'translation_updated' => 'News language version updated', 'published' => 'News item published', 'unpublished' => 'News item unpublished', 'deleted' => 'News item deleted', 'restored' => 'News item restored as draft', 'owner_transferred' => 'News ownership transferred']],
    'permissions' => [
        'view' => 'View news',
        'view_own' => 'View own news',
        'create' => 'Create news',
        'edit' => 'Edit news',
        'edit_own' => 'Edit own news',
        'publish' => 'Publish news',
        'publish_own' => 'Publish own news',
        'delete' => 'Delete news',
        'delete_own' => 'Delete own news',
        'restore' => 'Restore news',
        'restore_own' => 'Restore own news',
        'assign_owner' => 'Transfer news ownership',
    ],
];
