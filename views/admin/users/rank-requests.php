<?php
$rankRequests = is_array($rankRequests ?? null) ? $rankRequests : [];
$adminCsrfToken = (string) ($adminCsrfToken ?? '');
?>
<section class="admin-rank-requests">
    <div class="admin-rank-requests-head">
        <div>
            <h2>Запити на підвищення рангу</h2>
            <p>Користувачі з заповненими телефоном та адресою, які очікують рішення адміністратора.</p>
        </div>
        <span class="admin-rank-request-count">
            Нових: <?= count($rankRequests) ?>
        </span>
    </div>

    <?php if (empty($rankRequests)): ?>
        <div class="admin-rank-request-empty">Нових запитів немає.</div>
    <?php else: ?>
        <div class="admin-rank-request-list">
            <?php foreach ($rankRequests as $request): ?>
                <?php
                $requestId = (int) ($request['id'] ?? 0);
                $higherRanks = is_array($request['higher_ranks'] ?? null)
                    ? $request['higher_ranks']
                    : [];
                $addressParts = array_filter([
                    trim((string) ($request['country'] ?? '')),
                    trim((string) ($request['city'] ?? '')),
                    trim((string) ($request['address'] ?? '')),
                    trim((string) ($request['postcode'] ?? ''))
                ], function ($value) {
                    return $value !== '';
                });
                ?>
                <article class="admin-rank-request-card">
                    <div class="admin-rank-request-main">
                        <div class="admin-rank-request-identity">
                            <strong><?= htmlspecialchars((string) ($request['user_name'] ?? '')) ?></strong>
                            <span><?= htmlspecialchars((string) ($request['user_email'] ?? '')) ?></span>
                            <small>ID користувача: <?= (int) ($request['user_id'] ?? 0) ?></small>
                        </div>
                        <small><?= htmlspecialchars((string) ($request['created_at'] ?? '')) ?></small>
                    </div>

                    <div class="admin-rank-request-data">
                        <span>Ранг: <strong><?= htmlspecialchars((string) ($request['rank_name'] ?? '')) ?></strong></span>
                        <span>Телефон: <strong><?= htmlspecialchars((string) ($request['phone'] ?? '')) ?></strong></span>
                        <span>Адреса: <strong><?= htmlspecialchars(implode(', ', $addressParts)) ?></strong></span>
                    </div>

                    <?php if (!empty($higherRanks)): ?>
                        <form
                            class="admin-rank-request-form"
                            method="post"
                            action="/Anabelka/admin/users/rank-request/approve"
                            onsubmit="return confirm('Підвищити ранг цього користувача?');"
                        >
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($adminCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="request_id" value="<?= $requestId ?>">

                            <label>
                                <span>Новий ранг</span>
                                <select name="rank_id" required>
                                    <?php foreach ($higherRanks as $rank): ?>
                                        <option value="<?= (int) ($rank['id'] ?? 0) ?>">
                                            <?= htmlspecialchars((string) ($rank['name'] ?? '')) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <label>
                                <span>Примітка</span>
                                <input type="text" name="note" maxlength="255" placeholder="Необов’язково">
                            </label>

                            <button type="submit">Підвищити ранг</button>
                        </form>
                    <?php else: ?>
                        <div class="admin-rank-request-empty">Вищого активного рангу немає.</div>
                    <?php endif; ?>

                    <form
                        class="admin-rank-request-reject"
                        method="post"
                        action="/Anabelka/admin/users/rank-request/reject"
                        onsubmit="return confirm('Відхилити цей запит?');"
                    >
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($adminCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="request_id" value="<?= $requestId ?>">
                        <input type="text" name="note" maxlength="255" placeholder="Причина відмови — необов’язково">
                        <button type="submit">Відхилити</button>
                    </form>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
