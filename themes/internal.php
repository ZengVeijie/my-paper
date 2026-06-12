<?php
$articles = $articles ?? [];
$search = $search ?? '';
$page = $page ?? 1;
$total_pages = $total_pages ?? 1;
$total = $total ?? 0;
?>
<div class="page-header">
    <h1>站内</h1>
    <p style="color:var(--text-muted);font-family:var(--font-ui);font-size:0.85rem;margin-top:4px;">所有用户设为站内可见的文章</p>
</div>

<div class="search-bar">
    <form method="GET" action="/internal" class="search-form">
        <input type="search" name="search" value="<?= h($search) ?>" placeholder="搜索站内文章..." class="search-input">
        <?php if ($search): ?>
            <a href="/internal" class="search-clear">清除</a>
        <?php endif; ?>
    </form>
</div>

<?php if (empty($articles)): ?>
    <div class="empty-state">
        <p>暂无站内可见文章</p>
        <p class="empty-hint">当用户将文章设为"站内可见"后，文章会出现在这里</p>
    </div>
<?php else: ?>
    <div class="article-list" id="article-list">
        <?php foreach ($articles as $a): ?>
        <article class="article-card">
            <div class="article-row">
                <div class="article-main">
                    <div class="article-meta">
                        <time datetime="<?= h($a['created_at']) ?>"><?= h(format_date($a['created_at'])) ?></time>
                        <span class="vis-badge vis-internal">站内可见</span>
                        <?php if ($a['pinned']): ?>
                            <span class="vis-badge vis-pinned">置顶</span>
                        <?php endif; ?>
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
                            <a href="/internal?tag=<?= urlencode($tag) ?>" class="tag"><?= h($tag) ?></a>
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
            <?php $qs = http_build_query(array_filter(['page' => $i, 'search' => $search])); ?>
            <a href="/internal?<?= $qs ?>" class="page-link<?= $i === $page ? ' active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
<?php endif; ?>
