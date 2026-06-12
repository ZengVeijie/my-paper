<?php
$articles = $articles ?? [];
$page = $page ?? 1;
$total_pages = $total_pages ?? 1;
$total = $total ?? 0;
?>
<div class="page-header">
    <h1>收藏</h1>
    <p style="color:var(--text-muted);font-family:var(--font-ui);font-size:0.85rem;margin-top:4px;">你收藏的站内文章</p>
</div>

<?php if (empty($articles)): ?>
    <div class="empty-state">
        <p>还没有收藏文章</p>
        <p class="empty-hint">在站内文章中点击 ☆ 收藏，文章便会出现在这里</p>
    </div>
<?php else: ?>
    <div class="article-list" id="article-list">
        <?php foreach ($articles as $a): ?>
        <article class="article-card">
            <div class="article-row">
                <div class="article-main">
                    <div class="article-meta">
                        <time datetime="<?= h($a['created_at']) ?>"><?= h(format_date($a['created_at'])) ?></time>
                        <span class="vis-badge vis-<?= h($a['visibility']) ?>">
                            <?= ($a['visibility'] ?? '') === 'internal' ? '站内可见' : '已分享' ?>
                        </span>
                        <?php $author = $a['author'] ?? null; ?>
                        <?php if ($author): ?>
                            <span style="color:var(--text-muted);font-family:var(--font-ui);font-size:0.8rem;">作者: <?= h($author['display_name'] ?? $author['username'] ?? '?') ?></span>
                        <?php endif; ?>
                    </div>
                    <h2 class="article-title">
                        <a href="/article/<?= h($a['id']) ?>"><?= h($a['title'] ?: '无标题') ?></a>
                    </h2>
                    <?php if (!empty($a['summary'])): ?>
                        <p class="article-summary"><?= h($a['summary']) ?></p>
                    <?php else: ?>
                        <p class="article-summary"><?= h(excerpt($a['content'] ?? '')) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($a['tags'])): ?>
                    <div class="article-tags">
                        <?php foreach ($a['tags'] as $tag): ?>
                            <span class="tag"><?= h($tag) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </article>
        <?php endforeach; ?>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <a href="/favorites?page=<?= $i ?>" class="page-link<?= $i === $page ? ' active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
<?php endif; ?>
