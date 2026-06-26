<?php

// SPDX-FileCopyrightText: 2026 CRM Notes Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Minimal stubs for Nextcloud framework classes.
 * These allow unit tests to run without the full Nextcloud environment.
 */

declare(strict_types=1);

// --- OCP\DB\Types ---
namespace OCP\DB {
    interface IResult {
        public function closeCursor(): bool;
        public function fetch(int $fetchMode = 0);
        public function fetchAll(int $fetchMode = 0): array;
        public function fetchColumn();
        public function fetchOne();
        public function rowCount(): int;
        public function columnCount(): int;
    }

    class Types {
        public const INTEGER = 'integer';
        public const STRING = 'string';
        public const TEXT = 'text';
        public const BOOLEAN = 'boolean';
        public const DATETIME = 'datetime';
        public const FLOAT = 'float';
    }

    class Exception extends \Exception {
        public const REASON_UNIQUE_CONSTRAINT_VIOLATION = 1;
        public const REASON_CONNECTION_LOST = 2;

        private int $reason = 0;

        public function setReason(int $reason): void {
            $this->reason = $reason;
        }

        public function getReason(): int {
            return $this->reason;
        }
    }
}

// --- OCP\AppFramework\Db ---
namespace OCP\AppFramework\Db {

    use OCP\DB\Types;

    abstract class Entity {
        public $id;
        private array $_updatedFields = [];
        protected array $_fieldTypes = ['id' => 'integer'];

        public static function fromParams(array $params): static {
            $instance = new static();
            foreach ($params as $key => $value) {
                $method = 'set' . ucfirst($key);
                $instance->$method($value);
            }
            return $instance;
        }

        public static function fromRow(array $row): static {
            $instance = new static();
            foreach ($row as $key => $value) {
                $prop = $instance->columnToProperty($key);
                $instance->setter($prop, [$value]);
            }
            $instance->resetUpdatedFields();
            return $instance;
        }

        public function getFieldTypes(): array {
            return $this->_fieldTypes;
        }

        public function resetUpdatedFields(): void {
            $this->_updatedFields = [];
        }

        public function getUpdatedFields(): array {
            return $this->_updatedFields;
        }

        protected function addType(string $fieldName, string $type): void {
            $this->_fieldTypes[$fieldName] = $type;
        }

        protected function setter(string $name, array $args): void {
            if (property_exists($this, $name)) {
                $this->markFieldUpdated($name);
                $this->$name = $args[0];
            }
        }

        protected function getter(string $name): mixed {
            if (property_exists($this, $name)) {
                return $this->$name;
            }
            return null;
        }

        protected function markFieldUpdated(string $attribute): void {
            $this->_updatedFields[$attribute] = true;
        }

        public function __call(string $methodName, array $args): mixed {
            if (str_starts_with($methodName, 'set')) {
                $attr = lcfirst(substr($methodName, 3));
                $this->setter($attr, $args);
                return null;
            } elseif (str_starts_with($methodName, 'get')) {
                $attr = lcfirst(substr($methodName, 3));
                return $this->getter($attr);
            }
            throw new \BadMethodCallException($methodName . ' is not defined');
        }

        public function getId(): ?int {
            return $this->id;
        }

        public function setId(int $id): void {
            $this->id = $id;
            $this->_updatedFields['id'] = true;
        }

        public function columnToProperty(string $columnName): string {
            $parts = explode('_', $columnName);
            $property = '';
            foreach ($parts as $i => $part) {
                $property .= ($i === 0) ? $part : ucfirst($part);
            }
            return $property;
        }

        public function propertyToColumn(string $property): string {
            $result = '';
            for ($i = 0; $i < strlen($property); $i++) {
                $c = $property[$i];
                if (ctype_upper($c)) {
                    $result .= '_' . strtolower($c);
                } else {
                    $result .= $c;
                }
            }
            return $result;
        }
    }

    class DoesNotExistException extends \Exception {
    }

    class MultipleObjectsReturnedException extends \Exception {
    }

    /**
     * @template T of Entity
     */
    abstract class QBMapper {
        protected $db;

        public function __construct($db, protected string $tableName, protected ?string $entityClass = null) {
            $this->db = $db;
        }

        public function getTableName(): string {
            return $this->tableName;
        }

        public function insert(Entity $entity): Entity {
            return $entity;
        }

        public function update(Entity $entity): Entity {
            return $entity;
        }

        public function delete(Entity $entity): Entity {
            return $entity;
        }

        protected function findEntity($query): Entity {
            return new ($this->entityClass)();
        }

        protected function findEntities($query): array {
            return [];
        }
    }
}

// --- OCP top-level interfaces ---
namespace OCP {
    interface IDBConnection {
        public function getQueryBuilder();
    }

    interface IRequest {
        public function getParam(string $key, $default = null);
    }

    interface IUserSession {
        public function getUser(): ?IUser;
    }

    interface IUser {
        public function getUID(): string;
        public function getDisplayName(): string;
    }

    interface IGroup {
        public function getGID(): string;
        public function getDisplayName(): string;
        public function getUsers(): array;
    }

    interface IGroupManager {
        public function get(string $gid): ?IGroup;
        public function search(string $search, ?int $limit = null, ?int $offset = null): array;
        public function getUserGroups(IUser $user): array;
        public function groupExists(string $gid): bool;
        public function isAdmin(string $userId): bool;
    }

    interface IUserManager {
        public function get(string $uid): ?IUser;
        public function searchDisplayName(string $pattern, ?int $limit = null, ?int $offset = null): array;
    }

    interface IConfig {
        public function getUserValue(string $userId, string $appId, string $key, string $default = ''): string;
        public function setUserValue(string $userId, string $appId, string $key, string $value): void;
        public function getAppValue(string $appName, string $key, string $default = ''): string;
    }

    interface IAppConfig {
        public function getValueBool(string $app, string $key, bool $default = false): bool;
        public function setValueBool(string $app, string $key, bool $value): bool;
    }

    class Util {
        public static function addStyle(string $app, string $file): void {}
        public static function addScript(string $app, string $file): void {}
    }

    interface IURLGenerator {
        public function linkToRoute(string $routeName, array $arguments = []): string;
        public function imagePath(string $app, string $image): string;
        public function getAbsoluteURL(string $url): string;
    }

    interface IL10N {
        public function t(string $text, $parameters = []): string;
    }
}

// --- OCP\Files ---
namespace OCP\Files {
    interface IRootFolder {
        public function getUserFolder(string $userId);
    }

    interface Folder {
        public function getById(int $id): array;
        public function get(string $path);
        public function getRelativePath(string $path): ?string;
    }

    interface Node {
        public function getId(): int;
        public function getPath(): string;
    }

    class NotFoundException extends \Exception {
    }
}

// --- OCP\DB\QueryBuilder ---
namespace OCP\DB\QueryBuilder {
    interface IQueryBuilder {
        public const PARAM_STR = 2;
        public const PARAM_INT = 1;
        public const PARAM_BOOL = 5;
        public const PARAM_INT_ARRAY = 101;
        public const PARAM_STR_ARRAY = 102;

        public function select(...$columns);
        public function selectDistinct($select);
        public function from($table, $alias = null);
        public function join($fromAlias, $join, $alias, $condition = null);
        public function where(...$predicates);
        public function andWhere(...$args);
        public function orderBy($sort, $order = null);
        public function addOrderBy($sort, $order = null);
        public function setMaxResults(?int $limit);
        public function setFirstResult(?int $offset);
        public function expr();
        public function func();
        public function createNamedParameter($value, $type = null, ?string $name = null);
        public function delete(string $table = null, ?string $alias = null);
        public function executeStatement(): int;
        public function executeQuery();
    }

    interface IExpressionBuilder {
        public function eq($x, $y, $type = null);
        public function in($x, $y, $type = null);
        public function like($x, $y, $type = null);
        public function gt($x, $y, $type = null);
        public function andX(...$args);
        public function orX(...$args);
    }

    interface IFunctionBuilder {
        public function count($count, $alias = '');
        public function charLength($field, $alias = '');
    }
}

// --- OCP\AppFramework ---
namespace OCP\AppFramework {
    abstract class App {
        private string $appName;
        public function __construct(string $appName, array $params = []) {
            $this->appName = $appName;
        }
        public function getContainer() { return null; }
    }

    abstract class Controller {
        protected \OCP\IRequest $request;
        public function __construct(string $appName, \OCP\IRequest $request) {
            $this->request = $request;
        }
    }

    // In Nextcloud OCP\AppFramework\Http is a class carrying HTTP status
    // constants (separate from the OCP\AppFramework\Http\* namespace).
    class Http {
        public const STATUS_OK = 200;
        public const STATUS_NO_CONTENT = 204;
        public const STATUS_BAD_REQUEST = 400;
        public const STATUS_UNAUTHORIZED = 401;
        public const STATUS_FORBIDDEN = 403;
        public const STATUS_NOT_FOUND = 404;
        public const STATUS_CONFLICT = 409;
        public const STATUS_INTERNAL_SERVER_ERROR = 500;
    }
}

namespace OCP\AppFramework\Http {
    class Http {
        public const STATUS_OK = 200;
        public const STATUS_NO_CONTENT = 204;
        public const STATUS_BAD_REQUEST = 400;
        public const STATUS_UNAUTHORIZED = 401;
        public const STATUS_FORBIDDEN = 403;
        public const STATUS_NOT_FOUND = 404;
        public const STATUS_CONFLICT = 409;
        public const STATUS_INTERNAL_SERVER_ERROR = 500;
    }

    class Response {
        private int $status = 200;
        public function setStatus(int $status): static {
            $this->status = $status;
            return $this;
        }
        public function getStatus(): int {
            return $this->status;
        }
        public function cacheFor(int $seconds): static {
            return $this;
        }
    }

    class JSONResponse extends Response {
        private mixed $data;
        public function __construct(mixed $data = [], int $status = 200) {
            $this->data = $data;
            $this->setStatus($status);
        }
        public function getData(): mixed { return $this->data; }
    }

    class DataDisplayResponse extends Response {
        private string $data;
        private array $headers;
        public function __construct(string $data = '', int $status = 200, array $headers = []) {
            $this->data = $data;
            $this->headers = $headers;
            $this->setStatus($status);
        }
        public function getData(): string { return $this->data; }
        public function getHeaders(): array { return $this->headers; }
        // Mirror the real DataDisplayResponse, whose render() emits the raw body.
        public function render(): string { return $this->data; }
    }

    class TemplateResponse extends Response {
        public function __construct(string $appName, string $templateName, array $params = [], string $renderAs = 'user') {}
    }
}

// --- OCP\AppFramework\Http\Attribute ---
namespace OCP\AppFramework\Http\Attribute {
    #[\Attribute(\Attribute::TARGET_METHOD)]
    class NoAdminRequired {
    }

    #[\Attribute(\Attribute::TARGET_METHOD)]
    class NoCSRFRequired {
    }

    #[\Attribute(\Attribute::TARGET_METHOD)]
    class PublicPage {
    }
}

// --- OCP\AppFramework\Bootstrap ---
namespace OCP\AppFramework\Bootstrap {
    interface IBootstrap {
        public function register(IRegistrationContext $context): void;
        public function boot(IBootContext $context): void;
    }

    interface IRegistrationContext {
        public function registerEventListener(string $event, string $listener, int $priority = 0): void;
        public function registerService(string $name, callable $factory, bool $shared = true): void;
    }
    interface IBootContext {
        public function getAppContainer();
    }
}

// --- OCP\EventDispatcher ---
namespace OCP\EventDispatcher {
    interface Event {
    }

    /**
     * @template T
     */
    interface IEventListener {
        public function handle(Event $event): void;
    }

    interface IEventDispatcher {
        public function dispatchTyped(Event $event): void;
    }
}

// --- OCA\Contacts\Event (the Contacts app, an optional dependency) ---
namespace OCA\Contacts\Event {
    class LoadContactsOcaApiEvent implements \OCP\EventDispatcher\Event {
    }
}

// --- OCP\App\IAppManager (used by PageController to detect the Contacts app) ---
namespace OCP\App {
    interface IAppManager {
        public function isEnabledForUser(string $appId, ?\OCP\IUser $user = null): bool;
    }
}

// --- OCP\AppFramework\Services\IInitialState ---
namespace OCP\AppFramework\Services {
    interface IInitialState {
        public function provideInitialState(string $key, mixed $data): void;
    }
}

// --- OCP\Migration ---
namespace OCP\Migration {
    interface IOutput {
        public function info(string $message): void;
        public function warning(string $message): void;
        public function startProgress(int $max = 0): void;
        public function advance(int $step = 1, string $description = ''): void;
        public function finishProgress(): void;
    }
    class SimpleMigrationStep {}
}

namespace OCP\DB {
    interface ISchemaWrapper {
        public function hasTable(string $tableName): bool;
        public function createTable(string $tableName);
        public function getTable(string $tableName);
    }
}

// --- OCP\Contacts\IManager ---
namespace OCP\Contacts {
    interface IManager {
        public function search(string $pattern, array $searchProperties, array $options): array;
        public function delete(int $id, string $addressBookKey): bool;
        public function createOrUpdate(array $properties, string $addressBookKey);
        public function isEnabled(): bool;
        public function getUserAddressBooks(): array;
    }
}

// --- OCP\IAddressBook ---
namespace OCP {
    interface IAddressBook {
        public function getKey();
        public function getUri(): string;
        public function getDisplayName();
        public function search($pattern, $searchProperties, $options);
        public function createOrUpdate($properties);
        public function getPermissions();
        public function delete($id);
    }
}

// --- OCP\Contacts\ContactsMenu ---
namespace OCP\Contacts\ContactsMenu {
    interface IProvider {
        public function process(IEntry $entry): void;
    }

    interface IEntry {
        public function getProperty(string $key): mixed;
        public function addAction(IAction $action): void;
    }

    interface IAction {
        public function setPriority(int $priority): void;
    }

    interface IActionFactory {
        public function newLinkAction(string $icon, string $name, string $href): IAction;
    }
}

// --- Psr\Log ---
namespace Psr\Log {
    if (!\interface_exists(\Psr\Log\LoggerInterface::class, false)) {
        interface LoggerInterface {
            public function emergency($message, array $context = []): void;
            public function alert($message, array $context = []): void;
            public function critical($message, array $context = []): void;
            public function error($message, array $context = []): void;
            public function warning($message, array $context = []): void;
            public function notice($message, array $context = []): void;
            public function info($message, array $context = []): void;
            public function debug($message, array $context = []): void;
            public function log($level, $message, array $context = []): void;
        }
    }
}
