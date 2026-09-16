<?php
$pageTitle = 'Відгуки';
$reviews = is_array($reviews ?? null) ? $reviews : [];
$status = trim((string) ($status ?? ''));
$csrfToken = (string) ($csrfToken ?? '');
$flash = is_array($flash ?? null) ? $flash : null;
$loadError = trim((string) ($loadError ?? ''));
$escape = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$stars = static function ($rating) {
    $rating = max(1, min(5, (int) $rating));
    return str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);
};
$statusLabels = [
    'pending' => 'Очікує модерації',
    'approved' => 'Схвалено',
    'rejected' => 'Відхилено'
];
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Адмін-панель · Відгуки — Анабелька</title>
    <link rel="stylesheet" href="/Anabelka/css/style.css?v=9">
    <link rel="stylesheet" href="/Anabelka/css/admin-reviews.css?v=1">
</head>
<body>
<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="admin-reviews-page">
    <?php if ($flash): ?>
        <div class="admin-review-flash <?= ($flash['type'] ?? '') === 'success' ? 'is-success' : 'is-error' ?>">
            <?= $escape($flash['message'] ?? '') ?>
        </div>
    <?php endif; ?>

    <?php if ($loadError !== ''): ?>
        <div class="admin-review-warning"><?= $escape($loadError) ?></div>
    <?php endif; ?>

    <nav class="admin-reviews-filters" aria-label="Фільтр відгуків">
        <a class="<?= $status === '' ? 'is-active' : '' ?>" href="/Anabelka/admin/reviews">Усі</a>
        <a class="<?= $status === 'pending' ? 'is-active' : '' ?>" href="/Anabelka/admin/reviews?status=pending">Очікують</a>
        <a class="<?= $status === 'approved' ? 'is-active' : '' ?>" href="/Anabelka/admin/reviews?status=approved">Схвалені</a>
        <a class="<?= $status === 'rejected' ? 'is-active' : '' ?>" href="/Anabelka/admin/reviews?status=rejected">Відхилені</a>
    </nav>

    <?php if (empty($reviews)): ?>
        <div class="admin-review-card">Відгуків у цьому списку немає.</div>
    <?php else: ?>
        <div class="admin-review-list">
            <?php foreach ($reviews as $review): ?>
                <?php
                $reviewId = (int) ($review['id'] ?? 0);
                $reviewStatus = (string) ($review['status'] ?? 'pending');
                ?>
                <article class="admin-review-card">
                    <div class="admin-review-head">
                        <div>
                            <strong><?= $escape($review['customer_name'] ?? '') ?></strong>
                            <div class="admin-review-meta"><?= $escape($review['customer_email'] ?? '') ?></div>
                            <a href="/Anabelka/product/<?= rawurlencode((string) ($review['product_slug'] ?? '')) ?>#product-reviews">
                                <?= $escape($review['product_name'] ?? '') ?>
                            </a>
                        </div>
                        <div>
                            <div class="admin-review-stars"><?= $escape($stars($review['rating'] ?? 0)) ?></div>
                            <div class="admin-review-meta"><?= $escape($statusLabels[$reviewStatus] ?? $reviewStatus) ?></div>
                        </div>
                    </div>

                    <p class="admin-review-body"><?= $escape($review['body'] ?? '') ?></p>
                    <div class="admin-review-meta">
                        <?= $escape($review['created_at'] ?? '') ?>
                        <?php if (!empty($review['moderated_at'])): ?>
                            · модерація <?= $escape($review['moderated_at']) ?>
                        <?php endif; ?>
                    </div>

                    <div class="admin-review-actions" style="margin-top:12px">
                        <?php if ($reviewStatus !== 'approved'): ?>
                            <form method="post" action="/Anabelka/admin/reviews/approve">
                                <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                                <input type="hidden" name="review_id" value="<?= $reviewId ?>">
                                <input type="hidden" name="return_status" value="<?= $escape($status) ?>">
                                <button class="is-primary" type="submit">Схвалити</button>
                            </form>
                        <?php endif; ?>

                        <?php if ($reviewStatus !== 'rejected'): ?>
                            <form method="post" action="/Anabelka/admin/reviews/reject">
                                <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                                <input type="hidden" name="review_id" value="<?= $reviewId ?>">
                                <input type="hidden" name="return_status" value="<?= $escape($status) ?>">
                                <button type="submit">Відхилити</button>
                            </form>
                        <?php endif; ?>

                        <form method="post" action="/Anabelka/admin/reviews/delete" onsubmit="return confirm('Видалити цей відгук?');">
                            <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                            <input type="hidden" name="review_id" value="<?= $reviewId ?>">
                            <input type="hidden" name="return_status" value="<?= $escape($status) ?>">
                            <button class="is-danger" type="submit">Видалити</button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>
</body>
</html>
