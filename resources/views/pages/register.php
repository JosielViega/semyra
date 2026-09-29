<?php

declare(strict_types=1);

/** @var string $csrfField */
/** @var array<string, list<string>> $errors */
/** @var array{display_name?: string, email?: string} $old */
?>
<section class="auth-shell" aria-labelledby="register-title">
    <div class="auth-card">
        <p class="eyebrow">Conta opcional</p>
        <h1 id="register-title">Criar conta</h1>
        <p class="auth-intro">Você continua podendo usar salas como convidado.</p>

        <form class="auth-form" method="post" action="/register" novalidate>
            <?= $csrfField ?>
            <div class="auth-field">
                <label for="register-name">Nome</label>
                <input id="register-name" name="display_name" type="text" minlength="2" maxlength="30" autocomplete="name" required value="<?= e($old['display_name'] ?? '') ?>" aria-describedby="<?= isset($errors['display_name']) ? 'register-name-error' : '' ?>">
                <?php if (isset($errors['display_name'])): ?><p id="register-name-error" class="field-error"><?= e($errors['display_name'][0]) ?></p><?php endif; ?>
            </div>
            <div class="auth-field">
                <label for="register-email">E-mail</label>
                <input id="register-email" name="email" type="email" maxlength="191" autocomplete="email" required value="<?= e($old['email'] ?? '') ?>" aria-describedby="<?= isset($errors['email']) ? 'register-email-error' : '' ?>">
                <?php if (isset($errors['email'])): ?><p id="register-email-error" class="field-error"><?= e($errors['email'][0]) ?></p><?php endif; ?>
            </div>
            <div class="auth-field">
                <label for="register-password">Senha</label>
                <input id="register-password" name="password" type="password" minlength="8" maxlength="72" autocomplete="new-password" required aria-describedby="<?= isset($errors['password']) ? 'register-password-error' : '' ?>">
                <?php if (isset($errors['password'])): ?><p id="register-password-error" class="field-error"><?= e($errors['password'][0]) ?></p><?php endif; ?>
            </div>
            <div class="auth-field">
                <label for="register-password-confirmation">Confirmar senha</label>
                <input id="register-password-confirmation" name="password_confirmation" type="password" minlength="8" maxlength="72" autocomplete="new-password" required aria-describedby="<?= isset($errors['password_confirmation']) ? 'register-password-confirmation-error' : '' ?>">
                <?php if (isset($errors['password_confirmation'])): ?><p id="register-password-confirmation-error" class="field-error"><?= e($errors['password_confirmation'][0]) ?></p><?php endif; ?>
            </div>
            <button class="auth-submit" type="submit">Criar conta</button>
        </form>

        <p class="auth-alternate">Já tem conta? <a href="/login">Entrar</a></p>
    </div>
</section>
