<?php

declare(strict_types=1);

use Lemonade\Admin\Editor\AdminEditor\AdminEditorDefinition;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorRenderContext;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorRenderer;
use Lemonade\Framework\View\View;
use Lemonade\Framework\View\ViewHelpers;

/** @var ViewHelpers $helpers */
/** @var View $this */
/** @var string $title */
/** @var AdminEditorDefinition $adminEditor */
/** @var AdminEditorRenderContext $adminEditorContext */

$form = $adminEditor->form();
?>
<header class="lm-modal-header">
    <div>
        <h2 class="lm-modal-title" id="news-modal-create-title" data-lemonade-modal-title data-lemonade-i18n="news.editor.create_title"><?= e($title) ?></h2>
    </div>
    <button class="lm-modal-close" type="button" data-lemonade-modal-close="close" aria-label="<?= e($helpers->lang('admin.common.close')) ?>" data-lemonade-i18n-aria-label="admin.common.close"></button>
</header>
<section class="lm-modal-body" data-lemonade-modal-body>
    <?= (new AdminEditorRenderer($this, $helpers))->render($adminEditor, $adminEditorContext) ?>
</section>
<footer class="lm-modal-footer">
    <button class="btn btn-light" type="button" data-lemonade-modal-close="cancel" data-lemonade-i18n="admin.common.cancel"><?= e($helpers->lang('admin.common.cancel')) ?></button>
    <button class="btn btn-primary lm-button-primary" type="submit" form="<?= e($form->id()) ?>" data-lemonade-action data-lemonade-form="<?= e($form->id()) ?>" data-lemonade-url="<?= e((string) $form->actionUrl()) ?>" data-lemonade-method="<?= e($form->method()) ?>" data-lemonade-action-key="<?= e((string) $form->actionKey()) ?>" data-lemonade-i18n="news.actions.confirm_create"><?= e($helpers->lang('news.actions.confirm_create')) ?></button>
</footer>
