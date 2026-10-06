<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Actions;

use Lemonade\Admin\Action\Contract\ModuleActionHandlerInterface;
use Lemonade\Admin\Action\Exception\ModuleActionException;
use Lemonade\Admin\Action\ModuleActionResult;
use Lemonade\Admin\Audit\AuditActor;
use Lemonade\Admin\Http\AdminErrorCode;
use Lemonade\Admin\Identity\LocalActorGuard;
use Lemonade\Admin\Identity\LocalActorRequiredException;
use Lemonade\Cms\News\Services\NewsService;
use Lemonade\Framework\Http\HttpStatus as HttpStatusCode;

/**
 * Obnovuje smazanou Aktualitu jako draft pres canonical Admin action
 */
final class NewsRestoreAction implements ModuleActionHandlerInterface
{
    /**
     * Nastavuje News lifecycle a localni identitu auditovane mutace
     */
    public function __construct(
        private readonly NewsService $news,
        private readonly LocalActorGuard $actors,
    ) {}

    /**
     * Obnovi clanek jako draft a prevede odmitnuti do Admin transportu
     *
     * @param array<string,mixed> $payload
     */
    public function execute(?int $id, array $payload): ModuleActionResult
    {
        unset($payload);
        if ($id === null) {
            throw new ModuleActionException(
                HttpStatusCode::NOT_FOUND,
                AdminErrorCode::NOT_FOUND,
                'News item not found.',
            );
        }

        try {
            $this->news->restore(
                AuditActor::user($this->actors->requireLocalUser()->id()),
                $id,
            );
        } catch (LocalActorRequiredException $exception) {
            throw new ModuleActionException(
                HttpStatusCode::FORBIDDEN,
                AdminErrorCode::LOCAL_ACTOR_REQUIRED,
                $exception->getMessage(),
            );
        } catch (\RuntimeException $exception) {
            return ModuleActionResult::invalid(['_form' => $exception->getMessage()]);
        }

        return ModuleActionResult::success('news.actions.restored');
    }
}
