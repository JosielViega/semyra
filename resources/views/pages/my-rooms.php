<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $createdRooms */
/** @var list<array<string, mixed>> $participatedRooms */
/** @var array<string, list<string>> $flashes */
$formatDate = static fn (string $value): string => (new DateTimeImmutable($value))->format('d/m/Y H:i');
?>
<section class="my-rooms-shell" aria-labelledby="my-rooms-title">
    <header class="my-rooms-intro">
        <p class="eyebrow">Sua conta</p>
        <h1 id="my-rooms-title">Minhas salas</h1>
        <p>Suas salas e lugares onde você já assistiu com amigos.</p>
    </header>

    <?php foreach ($flashes as $type => $messages): ?>
        <?php foreach ($messages as $message): ?>
            <p class="flash flash-<?= e($type) ?>" role="status"><?= e($message) ?></p>
        <?php endforeach; ?>
    <?php endforeach; ?>

    <section class="my-rooms-section" aria-labelledby="created-rooms-title">
        <div class="my-rooms-section-heading">
            <h2 id="created-rooms-title">Criadas por mim</h2>
            <span><?= count($createdRooms) ?></span>
        </div>
        <?php if ($createdRooms === []): ?>
            <p class="my-rooms-empty">Você ainda não criou nenhuma sala estando conectado.</p>
        <?php else: ?>
            <div class="my-rooms-list">
                <?php foreach ($createdRooms as $room): ?>
                    <article class="my-room-item">
                        <div>
                            <h3>Sala <?= e($room['code']) ?></h3>
                            <p>Última atividade: <?= e($formatDate((string) $room['last_activity_at'])) ?></p>
                            <p>Criada em: <?= e($formatDate((string) $room['created_at'])) ?></p>
                        </div>
                        <a href="/room/<?= e($room['code']) ?>">Abrir sala</a>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="my-rooms-section" aria-labelledby="participated-rooms-title">
        <div class="my-rooms-section-heading">
            <h2 id="participated-rooms-title">Participei</h2>
            <span><?= count($participatedRooms) ?></span>
        </div>
        <?php if ($participatedRooms === []): ?>
            <p class="my-rooms-empty">Você ainda não participou de outras salas com esta conta.</p>
        <?php else: ?>
            <div class="my-rooms-list">
                <?php foreach ($participatedRooms as $room): ?>
                    <article class="my-room-item">
                        <div>
                            <h3>Sala <?= e($room['code']) ?></h3>
                            <p>Última visita: <?= e($formatDate((string) $room['last_joined_at'])) ?></p>
                            <p>Última atividade: <?= e($formatDate((string) $room['last_activity_at'])) ?></p>
                        </div>
                        <a href="/room/<?= e($room['code']) ?>">Abrir sala</a>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</section>
