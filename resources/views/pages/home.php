<?php

declare(strict_types=1);

/** @var string $appName */
/** @var string $csrfField */
/** @var array $flashes */
?>
<section class="hero">
    <p class="eyebrow">Reusable PHP starter</p>
    <h1><?= e($appName) ?></h1>
    <p>A small foundation with routing, controllers, views, PDO, validation, sessions, CSRF, logs, migrations, tests, and CI.</p>
</section>

<?php foreach ($flashes as $type => $messages): ?>
    <?php foreach ($messages as $message): ?>
        <p class="flash flash-<?= e($type) ?>" role="status"><?= e($message) ?></p>
    <?php endforeach; ?>
<?php endforeach; ?>

<section class="card">
    <h2>Protected POST example</h2>
    <p>This disposable example demonstrates Request → validation → CSRF → flash → HTTP redirect. It stores no business data.</p>
    <form action="/example" method="post">
        <?= $csrfField ?>
        <label for="message">Short message</label>
        <input id="message" name="message" type="text" minlength="2" maxlength="120" required>
        <button type="submit">Submit example</button>
    </form>
</section>
