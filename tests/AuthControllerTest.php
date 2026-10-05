<?php

declare(strict_types=1);

namespace Tests;

use App\Controllers\AuthController;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use App\Repositories\UserRepository;
use App\Repositories\UserRememberTokenRepository;
use App\Services\AuthSession;
use App\Services\RememberMeService;
use App\Services\RoomParticipantSession;
use App\Validation\Validator;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class AuthControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testGetFormsUseOfficialLayoutAccessibleLabelsCsrfAndAutocomplete(): void
    {
        [$register] = $this->controller();
        [$login] = $this->controller();

        $registerResponse = $register->showRegister();
        $loginResponse = $login->showLogin();

        self::assertSame(200, $registerResponse->status());
        self::assertStringContainsString('SEMYRA', $registerResponse->body());
        self::assertStringContainsString('<label for="register-name">Nome</label>', $registerResponse->body());
        self::assertStringContainsString('autocomplete="name"', $registerResponse->body());
        self::assertSame(2, substr_count($registerResponse->body(), 'autocomplete="new-password"'));
        self::assertStringContainsString('name="_token"', $registerResponse->body());
        self::assertStringNotContainsString('value="secret-password"', $registerResponse->body());

        self::assertSame(200, $loginResponse->status());
        self::assertStringContainsString('<label for="login-email">E-mail</label>', $loginResponse->body());
        self::assertStringContainsString('autocomplete="email"', $loginResponse->body());
        self::assertStringContainsString('autocomplete="current-password"', $loginResponse->body());
        self::assertStringContainsString('name="remember"', $loginResponse->body());
        self::assertStringContainsString('Manter conectado neste dispositivo', $loginResponse->body());
        self::assertDoesNotMatchRegularExpression('/id="login-remember"[^>]* checked/', $loginResponse->body());
    }

    public function testValidRegistrationNormalizesEmailHashesPasswordAndLogsIn(): void
    {
        [$controller, $pdo, $auth] = $this->controller([
            'display_name' => '  Josiel  ',
            'email' => ' Teste@Email.COM ',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ]);

        $response = $controller->register();

        self::assertSame(303, $response->status());
        self::assertSame('/', $response->headers()['Location']);
        self::assertSame(1, $auth->userId());
        self::assertSame('Josiel', $pdo->rows[1]['display_name']);
        self::assertSame('teste@email.com', $pdo->rows[1]['email']);
        self::assertTrue(password_verify('secret-password', $pdo->rows[1]['password_hash']));
        self::assertNotSame('secret-password', $pdo->rows[1]['password_hash']);
    }

    public function testRegistrationRejectsEveryRequiredValidationBoundary(): void
    {
        $invalidBodies = [
            ['display_name' => '', 'email' => 'ok@example.com', 'password' => '12345678', 'password_confirmation' => '12345678'],
            ['display_name' => 'A', 'email' => 'ok@example.com', 'password' => '12345678', 'password_confirmation' => '12345678'],
            ['display_name' => str_repeat('A', 31), 'email' => 'ok@example.com', 'password' => '12345678', 'password_confirmation' => '12345678'],
            ['display_name' => 'Nome', 'email' => 'invalid', 'password' => '12345678', 'password_confirmation' => '12345678'],
            ['display_name' => 'Nome', 'email' => str_repeat('a', 184) . '@test.com', 'password' => '12345678', 'password_confirmation' => '12345678'],
            ['display_name' => 'Nome', 'email' => 'ok@example.com', 'password' => '1234567', 'password_confirmation' => '1234567'],
            ['display_name' => 'Nome', 'email' => 'ok@example.com', 'password' => str_repeat('x', 73), 'password_confirmation' => str_repeat('x', 73)],
            ['display_name' => 'Nome', 'email' => 'ok@example.com', 'password' => '12345678', 'password_confirmation' => 'different'],
        ];

        foreach ($invalidBodies as $body) {
            [$controller, $pdo, $auth] = $this->controller($body);
            $response = $controller->register();

            self::assertSame(422, $response->status());
            self::assertSame([], $pdo->rows);
            self::assertNull($auth->userId());
            self::assertStringNotContainsString('value="' . $body['password'] . '"', $response->body());
        }
    }

    public function testDuplicateEmailWithDifferentCaseIsFriendlyAndNeverRepopulatesPassword(): void
    {
        $pdo = new AuthUserPdo();
        $pdo->seed('Primeiro', 'teste@email.com', 'first-password');
        [$controller] = $this->controller([
            'display_name' => 'Segundo',
            'email' => 'TESTE@EMAIL.COM',
            'password' => 'second-password',
            'password_confirmation' => 'second-password',
        ], pdo: $pdo);

        $response = $controller->register();

        self::assertSame(422, $response->status());
        self::assertStringContainsString('Este e-mail já está cadastrado.', $response->body());
        self::assertStringContainsString('value="Segundo"', $response->body());
        self::assertStringContainsString('value="teste@email.com"', $response->body());
        self::assertStringNotContainsString('second-password', $response->body());
        self::assertCount(1, $pdo->rows);
    }

    public function testRegisterRejectsInvalidCsrf(): void
    {
        [$controller, $pdo] = $this->controller([
            '_token' => 'invalid',
            'display_name' => 'Josiel',
            'email' => 'user@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ], useProvidedToken: true);

        $response = $controller->register();

        self::assertSame(419, $response->status());
        self::assertSame([], $pdo->rows);
    }

    public function testValidLoginNormalizesEmailAndUsesPasswordVerify(): void
    {
        $pdo = new AuthUserPdo();
        $pdo->seed('Josiel', 'user@example.com', 'correct-password');
        [$controller, , $auth] = $this->controller([
            'email' => ' USER@EXAMPLE.COM ',
            'password' => 'correct-password',
        ], pdo: $pdo);

        $response = $controller->login();

        self::assertSame(303, $response->status());
        self::assertSame('/', $response->headers()['Location']);
        self::assertSame(1, $auth->userId());
        self::assertSame([], $pdo->rememberTokens);
    }

    public function testValidLoginWithRememberIssuesPersistentToken(): void
    {
        $pdo = new AuthUserPdo();
        $pdo->seed('Josiel', 'user@example.com', 'correct-password');
        [$controller, , $auth, , $remember] = $this->controller([
            'email' => 'user@example.com',
            'password' => 'correct-password',
            'remember' => '1',
        ], pdo: $pdo);

        $response = $remember->applyTo($controller->login());

        self::assertSame(303, $response->status());
        self::assertSame(1, $auth->userId());
        self::assertCount(1, $pdo->rememberTokens);
        self::assertStringContainsString('semyra_remember=', $response->headers()['Set-Cookie']);
        self::assertStringContainsString('HttpOnly', $response->headers()['Set-Cookie']);
        self::assertStringNotContainsString(
            array_values($pdo->rememberTokens)[0]['validator_hash'],
            $response->headers()['Set-Cookie'],
        );
    }

    public function testMissingEmailAndWrongPasswordUseSameGenericMessage(): void
    {
        $pdo = new AuthUserPdo();
        $pdo->seed('Josiel', 'user@example.com', 'correct-password');
        [$missing] = $this->controller(['email' => 'missing@example.com', 'password' => 'wrong-password'], pdo: $pdo);
        [$wrong] = $this->controller(['email' => 'user@example.com', 'password' => 'wrong-password'], pdo: $pdo);

        $missingResponse = $missing->login();
        $wrongResponse = $wrong->login();

        self::assertSame(422, $missingResponse->status());
        self::assertSame(422, $wrongResponse->status());
        self::assertSame(
            substr_count($missingResponse->body(), 'E-mail ou senha inválidos.'),
            substr_count($wrongResponse->body(), 'E-mail ou senha inválidos.'),
        );
        self::assertStringContainsString('E-mail ou senha inválidos.', $missingResponse->body());
    }

    public function testLoginRejectsInvalidCsrf(): void
    {
        [$controller] = $this->controller([
            '_token' => 'invalid',
            'email' => 'user@example.com',
            'password' => 'secret-password',
        ], useProvidedToken: true);

        self::assertSame(419, $controller->login()->status());
    }

    public function testInvalidCredentialsNeverIssueRememberToken(): void
    {
        $pdo = new AuthUserPdo();
        $pdo->seed('Josiel', 'user@example.com', 'correct-password');
        [$controller] = $this->controller([
            'email' => 'user@example.com',
            'password' => 'wrong-password',
            'remember' => '1',
        ], pdo: $pdo);

        self::assertSame(422, $controller->login()->status());
        self::assertSame([], $pdo->rememberTokens);
    }

    public function testAuthenticatedUserIsRedirectedAwayFromLoginAndRegister(): void
    {
        $pdo = new AuthUserPdo();
        $pdo->seed('Josiel', 'user@example.com', 'correct-password');
        [$controller, , $auth] = $this->controller(pdo: $pdo);
        $auth->login(1);

        self::assertSame(303, $controller->showLogin()->status());
        self::assertSame(303, $controller->showRegister()->status());
    }

    public function testLogoutRemovesOnlyAuthAndPreservesRoomParticipantIdentity(): void
    {
        [$controller, , $auth, $session] = $this->controller();
        $participants = new RoomParticipantSession($session);
        $identity = $participants->remember('ROOM1234', 'Convidado');
        $auth->login(9);

        $response = $controller->logout();

        self::assertSame(303, $response->status());
        self::assertNull($auth->userId());
        self::assertSame($identity, $participants->identityFor('ROOM1234'));
        self::assertNotSame([], $session->consumeFlash());
    }

    public function testLogoutRejectsInvalidCsrfAndKeepsAuthentication(): void
    {
        [$controller, , $auth] = $this->controller(['_token' => 'invalid'], useProvidedToken: true);
        $auth->login(9);

        self::assertSame(419, $controller->logout()->status());
        self::assertSame(9, $auth->userId());
    }

    public function testLogoutRevokesCurrentRememberTokenAndClearsCookie(): void
    {
        $pdo = new AuthUserPdo();
        $pdo->seed('Josiel', 'user@example.com', 'correct-password');
        [$login, , , , $loginRemember] = $this->controller([
            'email' => 'user@example.com',
            'password' => 'correct-password',
            'remember' => '1',
        ], pdo: $pdo);
        $loginResponse = $loginRemember->applyTo($login->login());
        $cookie = explode(';', substr($loginResponse->headers()['Set-Cookie'], strlen('semyra_remember=')), 2)[0];

        $_SESSION = [];
        [$logout, , $auth, , $logoutRemember] = $this->controller(
            pdo: $pdo,
            cookies: [RememberMeService::COOKIE_NAME => $cookie],
        );
        $auth->login(1);
        $response = $logoutRemember->applyTo($logout->logout());

        self::assertNull($auth->userId());
        self::assertSame([], $pdo->rememberTokens);
        self::assertStringContainsString('Max-Age=0', $response->headers()['Set-Cookie']);
    }

    public function testInvalidLogoutCsrfDoesNotRevokeRememberToken(): void
    {
        $pdo = new AuthUserPdo();
        $pdo->seed('Josiel', 'user@example.com', 'correct-password');
        [$login, , , , $loginRemember] = $this->controller([
            'email' => 'user@example.com',
            'password' => 'correct-password',
            'remember' => '1',
        ], pdo: $pdo);
        $loginResponse = $loginRemember->applyTo($login->login());
        $cookie = explode(';', substr($loginResponse->headers()['Set-Cookie'], strlen('semyra_remember=')), 2)[0];

        $_SESSION = [];
        [$logout, , $auth, , $logoutRemember] = $this->controller(
            ['_token' => 'invalid'],
            true,
            $pdo,
            [RememberMeService::COOKIE_NAME => $cookie],
        );
        $auth->login(1);
        $response = $logoutRemember->applyTo($logout->logout());

        self::assertSame(419, $response->status());
        self::assertSame(1, $auth->userId());
        self::assertCount(1, $pdo->rememberTokens);
        self::assertArrayNotHasKey('Set-Cookie', $response->headers());
    }

    /** @return array{AuthController, AuthUserPdo, AuthSession, Session, RememberMeService} */
    private function controller(
        array $body = [],
        bool $useProvidedToken = false,
        ?AuthUserPdo $pdo = null,
        array $cookies = [],
    ): array {
        $pdo ??= new AuthUserPdo();
        $database = new Database([]);
        (new \ReflectionProperty($database, 'connection'))->setValue($database, $pdo);
        $session = new Session(false);
        $csrf = new Csrf($session);
        $validToken = $csrf->token();
        if (!$useProvidedToken) {
            $body['_token'] = $validToken;
        }
        $auth = new AuthSession($session);
        $request = new Request([], $body, cookies: $cookies);
        $userRepository = new UserRepository($database);
        $remember = new RememberMeService(
            $request,
            new UserRememberTokenRepository($database),
            $userRepository,
            $auth,
            30,
            false,
        );

        return [new AuthController(
            $request,
            new View(dirname(__DIR__) . '/resources/views'),
            $session,
            $csrf,
            new Validator(),
            $userRepository,
            $auth,
            $remember,
        ), $pdo, $auth, $session, $remember];
    }
}

final class AuthUserPdo extends PDO
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];
    /** @var array<string, array<string, mixed>> */
    public array $rememberTokens = [];
    private int $nextId = 1;
    private int $nextRememberId = 1;
    private int $lastId = 0;

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new AuthUserStatement($this, $query);
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return (string) $this->lastId;
    }

    public function seed(string $name, string $email, string $password): void
    {
        $this->insert([
            'display_name' => $name,
            'email' => strtolower($email),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
    }

    public function insert(array $params): void
    {
        foreach ($this->rows as $row) {
            if (strcasecmp($row['email'], (string) $params['email']) === 0) {
                $exception = new PDOException('Duplicate entry');
                $exception->errorInfo = ['23000', 1062, 'Duplicate entry'];
                throw $exception;
            }
        }
        $this->lastId = $this->nextId++;
        $this->rows[$this->lastId] = [
            'id' => $this->lastId,
            'display_name' => $params['display_name'],
            'email' => $params['email'],
            'password_hash' => $params['password_hash'],
            'created_at' => '2026-09-29 12:00:00.000',
            'updated_at' => '2026-09-29 12:00:00.000',
        ];
    }

    public function insertRememberToken(array $params): void
    {
        $selector = (string) $params['selector'];
        $this->rememberTokens[$selector] = [
            'id' => $this->nextRememberId++,
            'user_id' => (int) $params['user_id'],
            'selector' => $selector,
            'validator_hash' => (string) $params['validator_hash'],
            'expires_at' => '2099-10-05 12:00:00.000',
            'expired' => false,
        ];
    }
}

final class AuthUserStatement extends PDOStatement
{
    private array $params = [];
    private int $affected = 0;

    public function __construct(private readonly AuthUserPdo $pdo, private readonly string $query)
    {
    }

    public function execute(?array $params = null): bool
    {
        $this->params = $params ?? [];
        $this->affected = 0;
        if (str_starts_with($this->query, 'INSERT INTO users')) {
            $this->pdo->insert($this->params);
            $this->affected = 1;
        } elseif (str_starts_with($this->query, 'INSERT INTO user_remember_tokens')) {
            $this->pdo->insertRememberToken($this->params);
            $this->affected = 1;
        } elseif (str_starts_with($this->query, 'DELETE FROM user_remember_tokens WHERE selector')) {
            $selector = (string) $this->params['selector'];
            if (isset($this->pdo->rememberTokens[$selector])) {
                unset($this->pdo->rememberTokens[$selector]);
                $this->affected = 1;
            }
        } elseif (str_contains($this->query, 'WHERE expires_at <=')) {
            foreach ($this->pdo->rememberTokens as $selector => $token) {
                if ($token['expired']) {
                    unset($this->pdo->rememberTokens[$selector]);
                    ++$this->affected;
                }
            }
        }
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if (str_contains($this->query, 'FROM user_remember_tokens')) {
            return $this->pdo->rememberTokens[(string) ($this->params['selector'] ?? '')] ?? false;
        }
        if (str_contains($this->query, 'WHERE id =')) {
            return $this->pdo->rows[(int) ($this->params['id'] ?? 0)] ?? false;
        }
        foreach ($this->pdo->rows as $row) {
            if (strcasecmp($row['email'], (string) ($this->params['email'] ?? '')) === 0) {
                return $row;
            }
        }
        return false;
    }

    public function rowCount(): int
    {
        return $this->affected;
    }
}
