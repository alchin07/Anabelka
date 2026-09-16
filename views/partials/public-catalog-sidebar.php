<?php
$sidebarLanguageCode = $currentLanguage['code']
    ?? Language::SOURCE_CODE;
$publicCatalogTree = isset($navigationTree) && is_array($navigationTree)
    ? $navigationTree
    : HomePage::localizedNavigationTree($sidebarLanguageCode);
$publicCatalogLabel = Translator::t(
    'public.catalog.title',
    'Каталог'
);

$sidebarEscape = static function ($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
};

$renderPublicCatalogSidebarNodes = null;
$renderPublicCatalogSidebarNodes = function (array $nodes, $level = 1) use (
    &$renderPublicCatalogSidebarNodes,
    $sidebarEscape
) {
    foreach ($nodes as $node) {
        $id = (int) ($node['id'] ?? 0);
        $children = is_array($node['children'] ?? null)
            ? $node['children']
            : [];
        $hasChildren = !empty($children);
        $isAdultRoot = !empty($node['is_adult'])
            && (int) ($node['parent_id'] ?? 0) === 0;
        $href = $isAdultRoot
            ? AdultAccess::gateUrl($node)
            : Category::catalogUrl($node);
        $childrenId = 'public-catalog-sidebar-children-' . $id;
        ?>
        <div
            class="public-catalog-sidebar-node<?= $isAdultRoot ? ' is-adult-root' : '' ?>"
            data-public-catalog-sidebar-node
            data-level="<?= (int) $level ?>"
        >
            <div
                class="public-catalog-sidebar-row"
                data-level="<?= (int) $level ?>"
            >
                <?php if ($hasChildren): ?>
                    <button
                        type="button"
                        class="public-catalog-sidebar-toggle"
                        data-public-catalog-sidebar-toggle="<?= $id ?>"
                        aria-expanded="true"
                        aria-controls="<?= $sidebarEscape($childrenId) ?>"
                        aria-label="<?= $sidebarEscape($node['name'] ?? '') ?>"
                    >
                        <span
                            class="public-catalog-sidebar-chevron"
                            aria-hidden="true"
                        >▾</span>
                    </button>
                <?php else: ?>
                    <span
                        class="public-catalog-sidebar-toggle-placeholder"
                        aria-hidden="true"
                    ></span>
                <?php endif; ?>

                <a
                    class="public-catalog-sidebar-link"
                    href="<?= $sidebarEscape($href) ?>"
                >
                    <?php if ($isAdultRoot): ?>
                        <span
                            class="public-catalog-sidebar-adult-badge"
                            aria-hidden="true"
                        >18+</span>
                    <?php endif; ?>

                    <span class="public-catalog-sidebar-name">
                        <?= $sidebarEscape($node['name'] ?? '') ?>
                    </span>
                </a>
            </div>

            <?php if ($hasChildren): ?>
                <div
                    class="public-catalog-sidebar-children"
                    id="<?= $sidebarEscape($childrenId) ?>"
                    data-public-catalog-sidebar-children="<?= $id ?>"
                >
                    <?php $renderPublicCatalogSidebarNodes(
                        $children,
                        $level + 1
                    ); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
};
?>

<aside
    class="public-catalog-sidebar"
    aria-label="<?= $sidebarEscape($publicCatalogLabel) ?>"
>
    <div class="public-catalog-sidebar-panel">
        <a
            class="public-catalog-sidebar-title"
            href="/Anabelka/catalog"
        >
            <?= $sidebarEscape($publicCatalogLabel) ?>
        </a>

        <?php if (!empty($publicCatalogTree)): ?>
            <div class="public-catalog-sidebar-tree">
                <?php $renderPublicCatalogSidebarNodes($publicCatalogTree); ?>
            </div>
        <?php endif; ?>
    </div>
</aside>
