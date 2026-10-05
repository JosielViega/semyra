<?php

declare(strict_types=1);

/** @var string $csrfField */
/** @var array<string, list<string>> $errors */
/** @var array{email?: string, remember?: bool} $old */
?>
<section class="auth-shell" aria-labelledby="login-title">
    <div class="auth-card">
        <p class="eyebrow">Sua conta</p>
        <h1 id="login-title">Entrar</h1>
        <p class="auth-intro">Acesse sua conta sem interromper a experiência de convidado.</p>

        <?php if (isset($errors['credentials'])): ?>
            <p class="auth-form-error" role="alert"><?= e($errors['credentials'][0]) ?></p>
        <?php endif; ?>

        <form class="auth-form" method="post" action="/login" novalidate>
            <?= $csrfField ?>
            <div class="auth-field">
                <label for="login-email">E-mail</label>
                <input id="login-email" name="email" type="email" maxlength="191" autocomplete="email" required value="<?= e($old['email'] ?? '') ?>" aria-describedby="<?= isset($errors['email']) ? 'login-email-error' : '' ?>">
                <?php if (isset($errors['email'])): ?><p id="login-email-error" class="field-error"><?= e($errors['email'][0]) ?></p><?php endif; ?>
            </div>
            <div class="auth-field">
                <label for="login-password">Senha</label>
                <input id="login-password" name="password" type="password" minlength="8" maxlength="72" autocomplete="current-password" required>
            </div>
            <label class="auth-remember" for="login-remember">
                <input id="login-remember" name="remember" type="checkbox" value="1"<?= !empty($old['remember']) ? ' checked' : '' ?>>
                <span>Manter conectado neste dispositivo</span>
            </label>
            <button class="auth-submit" type="submit">Entrar</button>
        </form>

        <p class="auth-alternate">Ainda não tem conta? <a href="/register">Criar conta</a></p>
    </div>
</section>
