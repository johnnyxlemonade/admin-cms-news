<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Unit\Services;

use Lemonade\Admin\Audit\AuditActor;
use Lemonade\Admin\Audit\AuditLogService;
use Lemonade\Admin\Event\DomainEventDispatcher;
use Lemonade\Admin\Event\TransactionalEventProcessor;
use Lemonade\Admin\Identity\LocalUserDirectory;
use Lemonade\Admin\Localization\LanguageRegistry;
use Lemonade\Cms\News\Models\NewsModel;
use Lemonade\Cms\News\Services\NewsService;
use Lemonade\Cms\Routing\Cms\CmsReservedPathGuard;
use Lemonade\Cms\Routing\Cms\CmsRouteCandidateResolver;
use Lemonade\Cms\Routing\Cms\CmsRouteRepository;
use Lemonade\Cms\Routing\Cms\CmsRouteReservationService;
use Lemonade\Cms\Routing\Locale\PublicLocaleRegistryInterface;
use Lemonade\Cms\Routing\Locale\PublicLocaleSnapshot;
use Lemonade\Framework\Database\Connection\ConnectionInterface;
use Lemonade\Framework\Database\Database;
use Lemonade\Framework\Database\DatabaseDriverInterface;
use Lemonade\Framework\Database\DatabaseResultInterface;
use Lemonade\Framework\Routing\ControllerAction;
use Lemonade\Framework\Routing\Router;
use Lemonade\Framework\Support\Slug\Slugger;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Overuje atomickou hranici editoroveho save bez skutecne databaze
 */
final class NewsServiceSaveTest extends TestCase
{
    public function testChangedAggregateWritesMetadataTranslationPublicationAndAuditInOneTransaction(): void
    {
        $state = new NewsSaveState();
        $connection = $this->connection($state);
        $driver = $this->driver($state, [
            [$this->metadata('Original', false)],
            [$this->translation('Old title')],
            [['name' => 'Culture']],
        ]);
        $service = $this->service($connection, $driver);

        $service->save(AuditActor::user(7), 42, $this->payload('Changed', true, 'New title', 2));

        self::assertSame(1, $state->transactions);
        self::assertCount(5, $state->domainWrites);
        self::assertCount(3, $state->auditWrites);
    }

    public function testUnchangedAggregateWithoutPublicationInputWritesNeitherDataNorAudit(): void
    {
        $state = new NewsSaveState();
        $connection = $this->connection($state);
        $driver = $this->driver($state, [
            [$this->metadata('Original', false)],
            [$this->translation('Old title')],
            [['name' => 'Culture']],
        ]);
        $service = $this->service($connection, $driver);
        $payload = $this->payload('Original', false, 'Old title', 1);
        unset($payload['publication_state'], $payload['published_at']);

        $service->save(AuditActor::user(7), 42, $payload);

        self::assertSame(1, $state->transactions);
        self::assertSame([], $state->domainWrites);
        self::assertSame([], $state->auditWrites);
    }

    /**
     * Prvni bezne ulozeni draftu vytvori canonical route z titulku
     */
    public function testFirstDraftSaveCreatesAutomaticCanonicalRouteFromTitle(): void
    {
        $state = new NewsSaveState(null);
        $connection = $this->connection($state);
        $driver = $this->driver($state, [
            [$this->metadata('Original', false)],
            [$this->translation(null)],
            [['name' => 'Culture']],
        ]);

        $this->service($connection, $driver)->save(
            AuditActor::user(7),
            42,
            $this->payload('Original', false, 'Nová aktualita', 1),
        );

        self::assertSame('nova-aktualita', $state->routePath);
        self::assertCount(1, $state->routeWrites);
    }

    /**
     * Explicitni URL akce normalizuje segment a audituje zmenu ve stejne transakci
     */
    public function testExplicitUrlChangeNormalizesAndUpdatesCanonicalRouteAtomically(): void
    {
        $state = new NewsSaveState();
        $connection = $this->connection($state);
        $driver = $this->driver($state, [
            [$this->metadata('Original', false)],
            [$this->translation('Old title')],
        ]);

        $segment = $this->service($connection, $driver)->changeUrlSegment(
            AuditActor::user(7),
            42,
            'cs',
            'Nová URL!',
        );

        self::assertSame('nova-url', $segment);
        self::assertSame('nova-url', $state->routePath);
        self::assertCount(1, $state->routeWrites);
        self::assertCount(1, $state->auditWrites);
        self::assertSame(1, $state->transactions);
    }

    /**
     * Stejny normalizovany segment je skutecny no-op bez route a auditniho zapisu
     */
    public function testExplicitUrlChangeNoOpWritesNeitherRouteNorAudit(): void
    {
        $state = new NewsSaveState();
        $connection = $this->connection($state);
        $driver = $this->driver($state, [
            [$this->metadata('Original', false)],
            [$this->translation('Old title')],
        ]);

        $segment = $this->service($connection, $driver)->changeUrlSegment(
            AuditActor::user(7),
            42,
            'cs',
            'Old title',
        );

        self::assertSame('old-title', $segment);
        self::assertSame([], $state->routeWrites);
        self::assertSame([], $state->auditWrites);
    }

    public function testOwnerTransferWritesAggregateAndAuditOnlyForChangedOwner(): void
    {
        $state = new NewsSaveState(userExists: true);
        $service = $this->service($this->connection($state), $this->driver($state, [[['owner_id' => 3]]]));

        $service->transferOwner(AuditActor::user(7), 42, 9);

        self::assertSame(1, $state->transactions);
        self::assertCount(1, $state->domainWrites);
        self::assertStringNotContainsString('created_by', $state->domainWrites[0]);
        self::assertCount(1, $state->auditWrites);
        self::assertSame(
            ['previous_owner_id' => 3, 'new_owner_id' => 9],
            json_decode((string) $state->auditBindings[0][7], true, flags: JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Preda osirelou Aktualitu a zachova null v auditni historii puvodniho vlastnika
     */
    public function testOrphanOwnerTransferWritesNullPreviousOwnerToAudit(): void
    {
        $state = new NewsSaveState(userExists: true);
        $service = $this->service($this->connection($state), $this->driver($state, [[['owner_id' => null]]]));

        $service->transferOwner(AuditActor::user(7), 42, 9);

        self::assertCount(1, $state->domainWrites);
        self::assertStringNotContainsString('created_by', $state->domainWrites[0]);
        self::assertCount(1, $state->auditWrites);
        self::assertSame(
            ['previous_owner_id' => null, 'new_owner_id' => 9],
            json_decode((string) $state->auditBindings[0][7], true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testOwnerTransferToCurrentOwnerIsAuditedNoOp(): void
    {
        $state = new NewsSaveState(userExists: true);
        $service = $this->service($this->connection($state), $this->driver($state, [[['owner_id' => 3]]]));

        $service->transferOwner(AuditActor::user(7), 42, 3);

        self::assertSame(1, $state->transactions);
        self::assertSame([], $state->domainWrites);
        self::assertSame([], $state->auditWrites);
    }

    public function testOwnerTransferRejectsMissingOrDeletedLocalUser(): void
    {
        $state = new NewsSaveState(userExists: false);
        $service = $this->service($this->connection($state), $this->driver($state, [[['owner_id' => 3]]]));

        try {
            $service->transferOwner(AuditActor::user(7), 42, 9);
            self::fail('Missing owner must be rejected.');
        } catch (\DomainException $exception) {
            self::assertSame('news.validation.owner_invalid', $exception->getMessage());
        }

        self::assertSame([], $state->domainWrites);
        self::assertSame([], $state->auditWrites);
    }

    private function service(ConnectionInterface $connection, DatabaseDriverInterface $driver): NewsService
    {
        $database = new Database($connection, $driver);
        $routes = new CmsRouteReservationService(
            new CmsRouteRepository($database),
            new CmsRouteCandidateResolver(),
            $this->reservedPaths(),
        );
        $events = new TransactionalEventProcessor(
            $database,
            new AuditLogService($database),
            new DomainEventDispatcher($this->createMock(LoggerInterface::class)),
        );

        return new NewsService(
            new NewsModel($driver),
            $this->instanceWithoutConstructor(LanguageRegistry::class),
            $routes,
            new Slugger(),
            $events,
            new LocalUserDirectory($database),
        );
    }

    /**
     * Vytvori guard s jedinou CMS fallback routou pro DB-free test
     */
    private function reservedPaths(): CmsReservedPathGuard
    {
        $router = new Router();
        $router->getNamed(
            'frontend.cms-route',
            '/{path:any}',
            ControllerAction::for(self::class, 'fallback'),
        );

        return new CmsReservedPathGuard(
            $router,
            new ServerRequest('GET', '/admin'),
            new NewsSaveLocaleRegistry(),
        );
    }

    public function fallback(): void {}

    private function connection(NewsSaveState $state): ConnectionInterface
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('inTransaction')->willReturnCallback(
            static fn(): bool => $state->insideTransaction,
        );
        $connection->method('transaction')->willReturnCallback(
            static function (callable $callback) use ($connection, $state): mixed {
                ++$state->transactions;
                $state->insideTransaction = true;
                try {
                    return $callback($connection);
                } finally {
                    $state->insideTransaction = false;
                }
            },
        );
        $connection->method('select')->willReturnCallback(
            static function (string $sql, array $bindings = []) use ($state): array {
                self::assertTrue($state->insideTransaction, 'Canonical route must be read inside the save transaction.');

                if (str_contains($sql, 'FROM system_user')) {
                    return $state->userExists ? [['1' => 1]] : [];
                }

                if (!str_contains($sql, 'module_code = ?')) {
                    if ($state->routePath === null) {
                        return [];
                    }

                    return [[
                        'id' => 9,
                        'module_code' => 'cms.news',
                        'entity_id' => 42,
                        'locale' => (string) ($bindings[0] ?? 'cs'),
                        'path' => $state->routePath,
                    ]];
                }

                if ($state->routePath === null) {
                    return [];
                }

                return [[
                    'id' => 9,
                    'module_code' => (string) ($bindings[0] ?? 'cms.news'),
                    'entity_id' => (int) ($bindings[1] ?? 42),
                    'locale' => (string) ($bindings[2] ?? 'cs'),
                    'path' => $state->routePath,
                ]];
            },
        );
        $connection->method('statement')->willReturnCallback(
            static function (string $sql, array $bindings = []) use ($state): int {
                self::assertTrue($state->insideTransaction, 'Audit must be written inside the save transaction.');
                if (str_contains($sql, 'cms_route')) {
                    $state->routePath = str_starts_with($sql, 'INSERT')
                        ? (string) ($bindings[3] ?? '')
                        : (string) ($bindings[0] ?? $state->routePath);
                    $state->routeWrites[] = $sql;

                    return 1;
                }
                $state->auditWrites[] = $sql;
                $state->auditBindings[] = array_values($bindings);

                return 1;
            },
        );

        return $connection;
    }

    /**
     * @param list<list<array<string,mixed>>> $selectRows
     */
    private function driver(NewsSaveState $state, array $selectRows): DatabaseDriverInterface
    {
        $results = array_map(function (array $rows): DatabaseResultInterface {
            $result = $this->createMock(DatabaseResultInterface::class);
            $result->method('result_array')->willReturn($rows);
            $result->method('row_array')->willReturn($rows[0] ?? null);

            return $result;
        }, $selectRows);
        $driver = $this->createMock(DatabaseDriverInterface::class);
        $driver->method('protect_identifiers')->willReturnCallback(
            static fn(string $identifier): string => $identifier,
        );
        $driver->method('affected_rows')->willReturn(1);
        $driver->method('query')->willReturnCallback(
            static function (string $sql) use (&$results, $state): DatabaseResultInterface|bool {
                self::assertTrue($state->insideTransaction, 'News persistence must stay inside the save transaction.');
                if (str_starts_with(strtolower(ltrim($sql)), 'select')) {
                    $result = array_shift($results);
                    if (!$result instanceof DatabaseResultInterface) {
                        throw new \LogicException('Unexpected News save read.');
                    }

                    return $result;
                }
                $state->domainWrites[] = $sql;

                return true;
            },
        );

        return $driver;
    }

    /** @return array<string,int|string|null> */
    private function metadata(string $author, bool $recommended): array
    {
        return [
            'author_name' => $author,
            'recommended' => $recommended ? 1 : 0,
            'show_author' => 1,
            'sharing_enabled' => 1,
            'show_published_at' => 1,
            'show_thumbnail' => 1,
            'show_reading_time' => 1,
            'show_tags' => 1,
        ];
    }

    /** @return array<string,int|string|null> */
    private function translation(?string $title): array
    {
        return [
            'id' => 5,
            'article_id' => 42,
            'locale' => 'cs',
            'title' => $title,
            'page_title' => null,
            'summary' => null,
            'content' => null,
            'meta_description' => null,
            'publication_state' => 1,
            'published_at' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function payload(string $author, bool $recommended, string $title, int $publicationState): array
    {
        return [
            'author_name' => $author,
            'recommended' => $recommended ? '1' : '0',
            'locale' => 'cs',
            'title' => $title,
            'page_title' => '',
            'summary' => '',
            'content' => '',
            'meta_description' => '',
            'tags' => ['Culture'],
            'publication_state' => (string) $publicationState,
            'published_at' => $publicationState === 2 ? '2026-10-19T12:30' : '',
        ];
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function instanceWithoutConstructor(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}

/**
 * Poskytuje jediny aktivni locale snapshot pro route guard
 */
final readonly class NewsSaveLocaleRegistry implements PublicLocaleRegistryInterface
{
    public function snapshot(): PublicLocaleSnapshot
    {
        return PublicLocaleSnapshot::fromRows([
            ['code' => 'cs', 'enabled' => 1, 'is_default' => 1],
        ]);
    }
}

final class NewsSaveState
{
    public bool $insideTransaction = false;

    public int $transactions = 0;

    public function __construct(
        public ?string $routePath = 'old-title',
        public bool $userExists = false,
    ) {}

    /** @var list<string> */
    public array $domainWrites = [];

    /** @var list<string> */
    public array $auditWrites = [];

    /** @var list<list<mixed>> */
    public array $auditBindings = [];

    /** @var list<string> */
    public array $routeWrites = [];
}
