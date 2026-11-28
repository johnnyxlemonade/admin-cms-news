<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Integration\Support;

use Lemonade\Admin\File\FileReadModel;
use Lemonade\Admin\Platform\Migrations\CreateCoreFiles;
use Lemonade\Cms\News\Migrations\CreateNewsArticles;
use Lemonade\Cms\News\Public\PublishedNewsReadModel;
use Lemonade\Cms\Routing\Cms\PublicCmsUrlBuilder;
use Lemonade\Cms\Routing\Module\PublicModuleStateResolverInterface;
use Lemonade\Framework\Database\Connection\DatabaseConfig;
use Lemonade\Framework\Database\Database;
use Lemonade\Framework\Database\Driver\Mysql\MysqlConnection;
use Lemonade\Framework\Database\Driver\Mysql\MysqlDatabaseDriver;
use Lemonade\Framework\Database\Driver\Mysql\MysqlIdentifierEscaper;
use Lemonade\Framework\Database\Driver\Mysql\MysqlSchemaGrammar;
use Lemonade\Framework\Database\Driver\Mysql\MysqlSqlEscaper;
use Lemonade\Framework\Database\Instrumentation\Internal\DatabaseInstrumentation;
use Lemonade\Framework\Database\Schema\Schema;
use Lemonade\Framework\Database\Schema\SchemaCompiler;
use Lemonade\Framework\Database\Sql\IdentifierProtector;
use RuntimeException;

/**
 * Owns the guarded MySQL schema and technical dependencies for public News tests
 */
final readonly class PublishedNewsIntegrationEnvironment
{
    private MysqlConnection $connection;

    private MysqlDatabaseDriver $driver;

    private Database $database;

    private DatabaseInstrumentation $instrumentation;

    /**
     * Resets only the explicitly selected integration database and applies package migrations
     */
    public function __construct()
    {
        $databaseName = getenv('NEWS_PUBLIC_INTEGRATION_DATABASE');
        if (!is_string($databaseName) || preg_match('/^cms_news_test_[a-zA-Z0-9_]+$/D', $databaseName) !== 1) {
            throw new RuntimeException('Set NEWS_PUBLIC_INTEGRATION_DATABASE to a dedicated cms_news_test_ database.');
        }

        $config = DatabaseConfig::fromArray([
            'driver' => 'mysql',
            'host' => 'db',
            'database' => $databaseName,
            'username' => 'db',
            'password' => 'db',
        ]);
        $instrumentation = new DatabaseInstrumentation();
        $connection = new MysqlConnection($config, $instrumentation);
        $escaper = new MysqlIdentifierEscaper('');
        $driver = new MysqlDatabaseDriver(
            $connection,
            $config,
            $escaper,
            new IdentifierProtector($escaper),
        );

        $this->connection = $connection;
        $this->driver = $driver;
        $this->database = new Database($connection, $driver);
        $this->instrumentation = $instrumentation;

        $this->resetSchema();
        $this->migrate($config, $escaper);
    }

    public function getDatabase(): Database
    {
        return $this->database;
    }

    public function getInstrumentation(): DatabaseInstrumentation
    {
        return $this->instrumentation;
    }

    public function createReader(bool $moduleEnabled = true): PublishedNewsReadModel
    {
        $modules = new readonly class ($moduleEnabled) implements PublicModuleStateResolverInterface {
            public function __construct(private bool $enabled) {}

            public function isDiscoveredInstalledAndEnabled(string $moduleCode): bool
            {
                return $this->enabled;
            }
        };

        return new PublishedNewsReadModel(
            $this->database,
            new FileReadModel($this->driver),
            new PublicCmsUrlBuilder(),
            $modules,
        );
    }

    /**
     * Removes all test tables and closes the connection even after a failed scenario
     */
    public function close(): void
    {
        if ($this->connection->inTransaction()) {
            $this->connection->rollBack();
        }

        $this->resetSchema();
        $this->connection->close();
    }

    private function migrate(DatabaseConfig $config, MysqlIdentifierEscaper $escaper): void
    {
        $this->database->statement('CREATE TABLE system_user (id BIGINT UNSIGNED PRIMARY KEY)');
        $this->database->statement('CREATE TABLE system_language (code VARCHAR(10) PRIMARY KEY, enabled TINYINT, sort_order INT, is_default TINYINT)');
        $this->database->statement('CREATE TABLE cms_route (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, module_code VARCHAR(100), entity_id BIGINT UNSIGNED, locale VARCHAR(10), path VARCHAR(700), created_at DATETIME, updated_at DATETIME, deleted_at DATETIME NULL, UNIQUE KEY uq_target (module_code,entity_id,locale))');

        $schema = new Schema(
            new SchemaCompiler(new MysqlSchemaGrammar(new MysqlSqlEscaper($escaper), $config)),
            $this->driver,
        );
        (new CreateNewsArticles())->up($schema);
        (new CreateCoreFiles())->up($schema);
    }

    private function resetSchema(): void
    {
        $this->database->statement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($this->database->select('SHOW TABLES') as $row) {
                $table = (string) array_values($row)[0];
                $this->database->statement('DROP TABLE `' . str_replace('`', '``', $table) . '`');
            }
        } finally {
            $this->database->statement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
}
