<?php
// The renderer supplies an allowlisted payload only after server authorization.
$diagnostics = is_array($diagnostics ?? null) ? $diagnostics : [];
$escapeDiagnostic = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$diagnosticLabels = [
    'status' => 'HTTP', 'event' => 'Подія', 'reason' => 'Причина',
    'exception_class' => 'Клас винятку', 'file' => 'Файл', 'line' => 'Рядок',
    'method' => 'Метод', 'route' => 'Маршрут', 'permission' => 'Потрібний дозвіл',
    'role' => 'Роль', 'reference' => 'Ідентифікатор'
];
?>
<?php if ($diagnostics): ?>
<section class="error-diagnostic" aria-label="Діагностика для Розробника">
    <h2>Діагностика для Розробника</h2>
    <dl>
        <?php foreach ($diagnosticLabels as $key => $label): ?>
            <?php if (isset($diagnostics[$key]) && $diagnostics[$key] !== ''): ?>
                <dt><?= $escapeDiagnostic($label) ?></dt>
                <dd><?= $escapeDiagnostic($diagnostics[$key]) ?></dd>
            <?php endif; ?>
        <?php endforeach; ?>
    </dl>
    <?php if (!empty($diagnostics['log_url'])): ?>
        <a href="<?= $escapeDiagnostic($diagnostics['log_url']) ?>">Відкрити запис у захищеному журналі</a>
    <?php endif; ?>
</section>
<?php endif; ?>
