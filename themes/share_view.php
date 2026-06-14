<?php
$articles = $articles ?? [];
$comments = $comments ?? [];
$show_comments = $show_comments ?? false;
?>

<?php foreach ($articles as $article): ?>
<article class="share-article" style="margin-bottom:48px;">
    <header style="margin-bottom:20px;">
        <h2 style="font-size:1.5rem;"><?= h($article['title'] ?: '无标题') ?></h2>
        <div style="font-family:var(--font-ui);font-size:0.8rem;color:var(--text-muted);margin-top:4px;">
            <?= h(format_date($article['created_at'])) ?>
        </div>
        <?php if (!empty($article['tags'])): ?>
        <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;">
            <?php foreach ($article['tags'] as $tag): ?>
                <span style="font-family:var(--font-ui);font-size:0.75rem;padding:2px 8px;background:var(--bg);border-radius:20px;color:var(--text-muted);"><?= h($tag) ?></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </header>
    <div class="rendered-content" style="font-size:1.05rem;line-height:1.9;"><?= h($article['content'] ?? '') ?></div>
</article>
<?php endforeach; ?>

<?php if ($show_comments && !empty($comments)): ?>
<div class="share-comments" style="margin-top:40px;padding-top:24px;border-top:1px solid var(--border);">
    <h3 style="font-size:1.1rem;margin-bottom:16px;">留言</h3>
    <?php foreach ($comments as $c): ?>
    <div style="padding:12px 0;border-bottom:1px solid var(--border-light);">
        <div style="font-family:var(--font-ui);font-size:0.8rem;color:var(--text-muted);margin-bottom:4px;">
            <strong style="color:var(--text);"><?= h($c['user_name'] ?? '?') ?></strong>
            &middot; <?= h(format_date($c['created_at'])) ?>
        </div>
        <div style="font-size:0.9rem;line-height:1.7;"><?= nl2br(h($c['content'] ?? '')) ?></div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="share-footer" style="text-align:center;margin-top:48px;padding-top:24px;border-top:1px solid var(--border);">
    <p style="color:var(--text-muted);font-family:var(--font-ui);font-size:0.8rem;">
        由 <a href="/" style="color:var(--accent);"><?= h(SITE_NAME) ?></a> 分享
    </p>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    if (typeof marked !== 'undefined') {
        marked.setOptions({ breaks: true, gfm: true });
        document.querySelectorAll('.rendered-content').forEach(el => {
            const raw = el.textContent;
            if (raw.trim()) {
                let processed = raw.replace(/\r?\n/g, '\n').replace(/\n{3,}/g, m => '\n\n' + '<br>'.repeat(m.length - 2) + '\n\n');
                el.innerHTML = marked.parse(processed);
            }
        });
    }
});
</script>
</div>
</main>
</body>
</html>
