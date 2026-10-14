<?php

namespace yourecom\tests;

use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use wcf\data\user\User;
use wcf\system\user\storage\UserStorageHandler;
use wcf\system\WCF;

/**
 * YoureCom Standard: base class of the integration tests against the local dev system.
 * Managed by _dev/standard (apply.php), do not edit here.
 *
 * Every test runs in a transaction that is rolled back and acts as the administrator (userID 1) by default.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected const ADMIN_ID = 1;

    #[\Override]
    protected function setUp(): void
    {
        WCF::getDB()->beginTransaction();
        $this->actAs(self::ADMIN_ID);
    }

    #[\Override]
    protected function tearDown(): void
    {
        WCF::getDB()->rollBackTransaction();

        // the user storage writes its changes at the end of the request, pending changes of a test must not leak
        $storage = UserStorageHandler::getInstance();
        foreach (['log', 'cache'] as $property) {
            (new \ReflectionProperty($storage, $property))->setValue($storage, []);
        }
    }

    /**
     * Switches the active user (0 = guest). Permission values are strings in the CLI, a web request delivers integers
     * for numeric options, so they are converted.
     */
    protected function actAs(int $userID): void
    {
        WCF::getSession()->changeUser(new User($userID > 0 ? $userID : null), true);
        WCF::getSession()->getPermission('admin.general.canUseAcp');

        $property = new \ReflectionProperty(WCF::getSession(), 'groupData');
        $groupData = $property->getValue(WCF::getSession());
        if (\is_array($groupData)) {
            foreach ($groupData as $name => $value) {
                if (\is_string($value) && \preg_match('~^-?\d+$~', $value) === 1) {
                    $groupData[$name] = (int)$value;
                }
            }
            $property->setValue(WCF::getSession(), $groupData);
        }
    }

    /**
     * Returns the ID of the user with the given name, skips the test if the user does not exist.
     */
    protected function userID(string $username): int
    {
        $userID = self::scalar("SELECT userID FROM wcf1_user WHERE username = ?", [$username]);
        if (!\is_numeric($userID)) {
            self::markTestSkipped("The test needs the user '{$username}'.");
        }

        return (int)$userID;
    }

    /**
     * Runs a PSR-15 request handler (action, form dialog) like a browser request.
     *
     * @param array<string, string> $query
     * @param array<string, mixed>|null $body
     */
    protected static function handle(
        RequestHandlerInterface $handler,
        string $method = 'GET',
        array $query = [],
        ?array $body = null,
    ): ResponseInterface {
        $request = (new ServerRequest([], [], 'https://localhost/', $method))->withQueryParams($query);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }

        return $handler->handle($request);
    }

    /**
     * Decodes the JSON body of a response.
     *
     * @return array<string, mixed>
     */
    protected static function json(ResponseInterface $response): array
    {
        $data = \json_decode((string)$response->getBody(), true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($data)) {
            return [];
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * Returns the first column of the first row.
     *
     * @param list<int|float|string|null> $parameters
     */
    protected static function scalar(string $sql, array $parameters = []): mixed
    {
        $statement = WCF::getDB()->prepare($sql, 1);
        $statement->execute($parameters);

        return $statement->fetchSingleColumn();
    }

    /**
     * Returns the first column of the first row as an integer (e.g. a COUNT(*) or an ID, 0 if there is no row).
     *
     * @param list<int|float|string|null> $parameters
     */
    protected static function int(string $sql, array $parameters = []): int
    {
        $value = self::scalar($sql, $parameters);

        return \is_numeric($value) ? (int)$value : 0;
    }

    /**
     * Returns a string field of a JSON response ('' if it is missing).
     *
     * @param array<string, mixed> $data
     */
    protected static function field(array $data, string $name): string
    {
        return \is_string($data[$name] ?? null) ? $data[$name] : '';
    }

    /**
     * Executes a statement.
     *
     * @param list<int|float|string|null> $parameters
     */
    protected static function execute(string $sql, array $parameters = []): void
    {
        WCF::getDB()->prepare($sql)->execute($parameters);
    }
}
