<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\UserRepository;
use App\Services\AuthSession;
use App\Services\RememberMeService;
use App\Validation\Validator;

final class AuthController
{
    public function __construct(
        private readonly Request $request,
        private readonly View $view,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly Validator $validator,
        private readonly UserRepository $users,
        private readonly AuthSession $auth,
        private readonly RememberMeService $rememberMe,
    ) {
    }

    public function showRegister(): Response
    {
        if ($this->currentUser() !== null) {
            return Response::redirect('/', 303);
        }

        return $this->form('register', [], []);
    }

    public function register(): Response
    {
        if (!$this->csrf->verify($this->request->input('_token'))) {
            return $this->invalidCsrfResponse();
        }

        $displayName = $this->trimmedInput('display_name');
        $email = $this->normalizedEmail($this->request->input('email'));
        $password = $this->request->input('password');
        $confirmation = $this->request->input('password_confirmation');
        $errors = $this->registrationErrors($displayName, $email, $password, $confirmation);
        $old = [
            'display_name' => is_string($displayName) ? $displayName : '',
            'email' => is_string($email) ? $email : '',
        ];

        if ($errors !== []) {
            return $this->form('register', $errors, $old, 422);
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($passwordHash)) {
            throw new \RuntimeException('Unable to hash password.');
        }

        $userId = $this->users->create($displayName, $email, $passwordHash);
        if ($userId === null) {
            return $this->form('register', [
                'email' => ['Este e-mail já está cadastrado.'],
            ], $old, 422);
        }

        $this->auth->login($userId);
        $this->session->flash('success', 'Conta criada com sucesso.');

        return Response::redirect('/', 303);
    }

    public function showLogin(): Response
    {
        if ($this->currentUser() !== null) {
            return Response::redirect('/', 303);
        }

        return $this->form('login', [], []);
    }

    public function login(): Response
    {
        if (!$this->csrf->verify($this->request->input('_token'))) {
            return $this->invalidCsrfResponse();
        }

        $email = $this->normalizedEmail($this->request->input('email'));
        $password = $this->request->input('password');
        $remember = $this->request->input('remember') === '1';
        $old = [
            'email' => is_string($email) ? $email : '',
            'remember' => $remember,
        ];
        $validShape = $this->validator->validate(
            ['email' => $email, 'password' => $password],
            ['email' => 'required|string|email|max:191', 'password' => 'required|string'],
        );
        $user = $validShape && is_string($email) ? $this->users->findByEmail($email) : null;

        if ($user === null || !is_string($password) || !password_verify($password, $user['password_hash'])) {
            return $this->form('login', [
                'credentials' => ['E-mail ou senha inválidos.'],
            ], $old, 422);
        }

        $this->auth->login($user['id']);
        if ($remember) {
            $this->rememberMe->issue($user['id']);
        }
        $this->session->flash('success', 'Login realizado com sucesso.');

        return Response::redirect('/', 303);
    }

    public function logout(): Response
    {
        if (!$this->csrf->verify($this->request->input('_token'))) {
            return $this->invalidCsrfResponse();
        }

        $this->rememberMe->revokeCurrent();
        $this->auth->logout();
        $this->session->flash('success', 'Você saiu da sua conta.');

        return Response::redirect('/', 303);
    }

    private function currentUser(): ?array
    {
        $userId = $this->auth->userId();
        if ($userId === null) {
            return null;
        }

        $user = $this->users->findById($userId);
        if ($user === null) {
            $this->auth->logout();
        }

        return $user;
    }

    private function form(string $name, array $errors, array $old, int $status = 200): Response
    {
        return Response::html($this->view->render('pages/' . $name, [
            'title' => ($name === 'login' ? 'Entrar' : 'Criar conta') . ' — Semyra',
            'csrfField' => $this->csrf->field(),
            'errors' => $errors,
            'old' => $old,
            'currentUser' => null,
        ]), $status);
    }

    private function invalidCsrfResponse(): Response
    {
        return Response::html($this->view->render('pages/error', [
            'title' => 'Solicitação inválida',
            'heading' => 'Formulário inválido ou expirado',
            'message' => 'Recarregue a página e tente novamente.',
        ]), 419);
    }

    private function registrationErrors(
        mixed $displayName,
        mixed $email,
        mixed $password,
        mixed $confirmation,
    ): array {
        $this->validator->validate([
            'display_name' => $displayName,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'display_name' => 'required|string|min:2|max:30',
            'email' => 'required|string|email|max:191',
            'password' => 'required|string|min:8|max:72',
            'password_confirmation' => 'required|string',
        ]);

        $validationErrors = $this->validator->errors();
        $errors = [];
        if (isset($validationErrors['display_name'])) {
            $errors['display_name'] = ['Informe um nome entre 2 e 30 caracteres.'];
        }
        if (isset($validationErrors['email'])) {
            $errors['email'] = ['Informe um e-mail válido com até 191 caracteres.'];
        }
        if (isset($validationErrors['password'])) {
            $errors['password'] = ['A senha deve ter entre 8 e 72 caracteres.'];
        }
        if (isset($validationErrors['password_confirmation'])
            || !is_string($password)
            || !is_string($confirmation)
            || $password !== $confirmation) {
            $errors['password_confirmation'] = ['A confirmação deve ser igual à senha.'];
        }

        return $errors;
    }

    private function trimmedInput(string $key): mixed
    {
        $value = $this->request->input($key);

        return is_string($value) ? trim($value) : $value;
    }

    private function normalizedEmail(mixed $email): mixed
    {
        return is_string($email) ? strtolower(trim($email)) : $email;
    }
}
