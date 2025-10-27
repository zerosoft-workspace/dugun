<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/site.php';
require_once __DIR__.'/../includes/theme.php';
require_once __DIR__.'/../includes/login_header.php';
require_once __DIR__.'/../includes/public_header.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

install_schema();

$content = site_public_content();
$posts = site_blog_posts_all($content);
$slug = trim((string)($_GET['slug'] ?? ''));

if ($slug === '' && isset($_SERVER['PATH_INFO'])) {
  $slug = trim((string)$_SERVER['PATH_INFO'], '/');
}

if ($slug === '' && isset($_SERVER['REQUEST_URI'])) {
  $path = parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH);
  if (is_string($path)) {
    $parts = explode('/', trim($path, '/'));
    if (count($parts) >= 2 && $parts[0] === 'blog') {
      $slug = end($parts);
      if ($slug === 'index.php') {
        $slug = '';
      }
    }
  }
}

$slug = slugify_allow_empty($slug);

$post = null;
$notFoundSlug = false;
if ($slug !== '') {
  $post = site_blog_post_by_slug($slug, $content);
  if (!$post) {
    $notFoundSlug = true;
    http_response_code(404);
  }
}

$listingBadge = trim((string)($content['blog_section_badge'] ?? '')) ?: 'Blog';
$listingTitle = trim((string)($content['blog_section_title'] ?? '')) ?: 'BİKARE Blog';
$listingText = trim((string)($content['blog_section_text'] ?? ''));

if ($post) {
  $pageTitle = $post['title'].' | Blog | '.APP_NAME;
  $pageDescription = trim((string)($post['description'] ?? ''));
  if ($pageDescription === '' && !empty($post['content'])) {
    $pageDescription = mb_substr(trim($post['content']), 0, 160).'...';
  }
  $canonicalUrl = $post['pretty_url'] ?? $post['url'] ?? site_blog_url($post['slug']);
} else {
  $pageTitle = $listingTitle.' | '.APP_NAME;
  $pageDescription = $listingText !== '' ? $listingText : 'BİKARE ekibinden dijital etkinlik trendleri, başarı hikayeleri ve QR kod stratejileri.';
  $canonicalUrl = site_blog_url('');
}

$pageStyles = <<<'CSS'
  :root {
    --ink:#0f172a;
    --muted:#64748b;
    --brand:#0ea5b5;
    --brand-dark:#0b8b98;
    --surface:#ffffff;
    --surface-soft:#f8fafc;
    --bg:#f3f4f6;
    --border:rgba(148,163,184,.2);
  }
  :root[data-theme="dark"] {
    --ink:#e2e8f0;
    --muted:#94a3b8;
    --brand:#38bdf8;
    --brand-dark:#0ea5b5;
    --surface:rgba(15,23,42,.94);
    --surface-soft:rgba(15,23,42,.82);
    --bg:#020617;
    --border:rgba(56,189,248,.25);
  }
  body { margin:0; min-height:100vh; background:linear-gradient(180deg,var(--bg),#fff); font-family:'Inter',sans-serif; color:var(--ink); }
  [data-theme="dark"] body { background:radial-gradient(circle at top,#0f172a 0%,#020617 60%,#010b17 100%); }
  .blog-shell { max-width:1100px; margin:0 auto; padding:60px 16px 100px; }
  .blog-hero { border-radius:32px; background:linear-gradient(135deg,rgba(14,165,181,0.14),rgba(15,23,42,0.06)); padding:60px; box-shadow:0 40px 110px -70px rgba(15,23,42,.45); }
  [data-theme="dark"] .blog-hero { background:linear-gradient(140deg,rgba(56,189,248,0.14),rgba(15,23,42,0.74)); box-shadow:0 48px 120px -60px rgba(8,47,73,.85); }
  .blog-hero h1 { font-size:3rem; font-weight:800; margin-bottom:1rem; }
  .blog-grid { display:grid; gap:32px; grid-template-columns:repeat(auto-fit,minmax(300px,1fr)); margin-top:48px; }
  .blog-card { border-radius:26px; background:var(--surface); box-shadow:0 24px 70px -55px rgba(15,118,110,0.18); overflow:hidden; display:flex; flex-direction:column; transition:transform .2s ease, box-shadow .2s ease; }
  .blog-card:hover { transform:translateY(-8px); box-shadow:0 38px 110px -60px rgba(15,118,110,0.24); }
  [data-theme="dark"] .blog-card { background:rgba(15,23,42,0.9); border:1px solid var(--border); box-shadow:0 32px 90px -58px rgba(8,47,73,.85); }
  [data-theme="dark"] .blog-card:hover { box-shadow:0 44px 120px -70px rgba(8,47,73,.9); }
  .blog-card__media { position:relative; min-height:210px; background-size:cover; background-position:center; overflow:hidden; }
  .blog-card__media::after { content:""; position:absolute; inset:0; background:linear-gradient(180deg,rgba(15,23,42,.05),rgba(15,23,42,.55)); opacity:.65; transition:opacity .2s ease; }
  .blog-card:hover .blog-card__media::after { opacity:.82; }
  .blog-card__media--empty { background:linear-gradient(135deg,rgba(14,165,181,.18),rgba(99,102,241,.18)); }
  [data-theme="dark"] .blog-card__media--empty { background:linear-gradient(135deg,rgba(56,189,248,.24),rgba(59,130,246,.28)); }
  .blog-card__date { position:absolute; left:18px; bottom:18px; border-radius:999px; background:rgba(255,255,255,.88); backdrop-filter:blur(8px); font-weight:600; }
  [data-theme="dark"] .blog-card__date { background:rgba(15,23,42,.78); color:#38bdf8; border:1px solid rgba(56,189,248,.24); }
  .blog-card__body { padding:28px; display:flex; flex-direction:column; gap:1rem; }
  .blog-card__body h3 { font-size:1.4rem; font-weight:700; margin:0; }
  .blog-card__desc { color:var(--muted); margin:0; line-height:1.6; }
  .blog-card__link { display:inline-flex; align-items:center; gap:.5rem; font-weight:600; color:var(--brand); text-decoration:none; }
  .blog-card__link:hover { color:var(--brand-dark); text-decoration:underline; }
  .detail-hero { position:relative; border-radius:32px; overflow:hidden; box-shadow:0 48px 130px -70px rgba(15,23,42,.55); margin-bottom:40px; }
  .detail-hero__media { width:100%; padding-top:48%; background-size:cover; background-position:center; }
  .detail-hero__media--empty { background:linear-gradient(135deg,rgba(14,165,181,.2),rgba(59,130,246,.16)); }
  .detail-hero__overlay { position:absolute; inset:0; background:linear-gradient(180deg,rgba(15,23,42,.05),rgba(15,23,42,.7)); }
  .detail-hero__content { position:absolute; inset:0; display:flex; flex-direction:column; justify-content:flex-end; padding:48px; color:#fff; }
  .detail-hero__badge { align-self:flex-start; border-radius:999px; background:rgba(255,255,255,.22); backdrop-filter:blur(8px); padding:.5rem 1.4rem; font-weight:600; }
  .detail-hero__title { font-size:3rem; font-weight:800; margin:18px 0 8px; }
  .detail-hero__meta { display:flex; gap:18px; flex-wrap:wrap; font-weight:600; font-size:.95rem; }
  .blog-article { background:var(--surface); border-radius:28px; padding:48px; box-shadow:0 30px 90px -60px rgba(15,23,42,.45); color:var(--ink); }
  [data-theme="dark"] .blog-article { background:rgba(15,23,42,.9); border:1px solid var(--border); box-shadow:0 36px 110px -70px rgba(8,47,73,.9); color:var(--ink); }
  .blog-article p { font-size:1.05rem; line-height:1.8; color:var(--ink); }
  .blog-article ul { padding-left:1.2rem; margin:1.2rem 0; color:var(--ink); }
  .blog-article li { margin-bottom:.6rem; }
  .blog-share { display:flex; flex-wrap:wrap; gap:12px; margin-top:32px; }
  .btn-share { border-radius:999px; border:1px solid rgba(14,165,181,.35); padding:.65rem 1.4rem; background:rgba(14,165,181,.08); color:var(--brand); font-weight:600; display:inline-flex; align-items:center; gap:.5rem; }
  .btn-share:hover { background:rgba(14,165,181,.16); color:var(--brand-dark); }
  [data-theme="dark"] .btn-share { border-color:rgba(56,189,248,.35); color:#38bdf8; background:rgba(56,189,248,.12); }
  [data-theme="dark"] .btn-share:hover { background:rgba(56,189,248,.22); color:#04121f; }
  .blog-gallery { margin-top:40px; display:grid; gap:18px; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); }
  .blog-gallery img { width:100%; height:240px; object-fit:cover; border-radius:22px; box-shadow:0 26px 80px -60px rgba(15,23,42,.45); }
  [data-theme="dark"] .blog-gallery img { box-shadow:0 36px 100px -70px rgba(8,47,73,.85); border:1px solid rgba(56,189,248,.24); }
  .other-posts { margin-top:60px; }
  .other-posts h3 { font-weight:700; margin-bottom:1.5rem; }
  .empty-state { border-radius:28px; border:2px dashed rgba(148,163,184,.35); padding:60px; text-align:center; background:rgba(255,255,255,.92); color:var(--muted); }
  [data-theme="dark"] .empty-state { background:rgba(15,23,42,.82); border-color:rgba(56,189,248,.28); color:rgba(148,163,184,.78); }
  @media (max-width: 992px) {
    .blog-hero { padding:36px; }
    .detail-hero__content { padding:32px; }
    .detail-hero__title { font-size:2.4rem; }
    .blog-article { padding:32px; }
  }
  @media (max-width: 576px) {
    .blog-hero { padding:28px; }
    .detail-hero__title { font-size:2rem; }
    .detail-hero__content { padding:28px; }
    .blog-article { padding:28px; }
  }
CSS;

function blog_render_content(string $content): string {
  $content = site_normalize_blog_content($content);
  if ($content === '') {
    return '';
  }
  $blocks = preg_split('~\n{2,}~', $content) ?: [];
  $html = '';
  foreach ($blocks as $block) {
    $block = trim($block);
    if ($block === '') {
      continue;
    }
    $lines = preg_split('~\n~', $block) ?: [];
    $isList = !empty($lines) && array_reduce($lines, function ($carry, $line) {
      if ($carry === false) {
        return false;
      }
      $line = trim($line);
      if ($line === '') {
        return $carry;
      }
      return (bool)preg_match('~^[-\*]\s+~', $line);
    }, true);
    if ($isList) {
      $html .= '<ul>';
      foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
          continue;
        }
        $text = trim(preg_replace('~^[-\*]\s+~', '', $line));
        $html .= '<li>'.h($text).'</li>';
      }
      $html .= '</ul>';
      continue;
    }
    $html .= '<p>'.nl2br(h($block)).'</p>';
  }
  return $html;
}

$otherPosts = [];
if ($post) {
  foreach ($posts as $candidate) {
    if (($candidate['slug'] ?? '') === $post['slug']) {
      continue;
    }
    $otherPosts[] = $candidate;
  }
  $otherPosts = array_slice($otherPosts, 0, 3);
}
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=h($pageTitle)?></title>
  <meta name="description" content="<?=h($pageDescription)?>">
  <link rel="canonical" href="<?=h($canonicalUrl)?>">
  <?=site_head_favicon($content)?>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <?=theme_head_assets()?>
  <style>
    <?=login_header_styles() . PHP_EOL . $pageStyles?>
  </style>
</head>
<body>
<?php site_public_header('blog', $content); ?>
  <div class="blog-shell">
    <?php if (!$post && !$posts): ?>
      <div class="empty-state">
        <h2 class="fw-bold mb-3">Henüz blog yazısı eklenmedi</h2>
        <p class="mb-0">Site içerikleri panelinden yeni bir blog kartı ekleyerek ziyaretçilerinize hikayenizi anlatabilirsiniz.</p>
      </div>
    <?php elseif (!$post): ?>
      <?php if ($notFoundSlug): ?>
        <div class="alert alert-warning mb-4">Aradığınız blog yazısı bulunamadı. Güncel içerikler aşağıda listelenmiştir.</div>
      <?php endif; ?>
      <div class="blog-hero">
        <?php if ($listingBadge !== ''): ?><span class="badge bg-light text-dark rounded-pill px-3 py-2 fw-semibold"><?=h($listingBadge)?></span><?php endif; ?>
        <h1><?=h($listingTitle)?></h1>
        <?php if ($listingText !== ''): ?><p class="lead text-muted mb-0" style="max-width:680px;"><?=nl2br(h($listingText))?></p><?php endif; ?>
      </div>
      <div class="blog-grid">
        <?php foreach ($posts as $item):
          $itemTitle = trim((string)($item['title'] ?? ''));
          $itemUrl = trim((string)($item['url'] ?? ''));
          if ($itemTitle === '' || $itemUrl === '') {
            continue;
          }
          $itemDesc = trim((string)($item['description'] ?? ''));
          $itemImage = trim((string)($item['image'] ?? ''));
          $mediaClasses = 'blog-card__media'.($itemImage === '' ? ' blog-card__media--empty' : '');
          $mediaStyle = $itemImage !== '' ? " style=\"background-image:url('".h($itemImage)."');\"" : '';
          $itemDate = '';
          if (!empty($item['published_at'])) {
            try {
              $dt = new DateTime($item['published_at']);
              $itemDate = $dt->format('d.m.Y');
            } catch (Throwable $e) {
              $itemDate = '';
            }
          }
        ?>
          <article class="blog-card">
            <div class="<?=$mediaClasses?>"<?=$mediaStyle?>>
              <?php if ($itemDate !== ''): ?><span class="blog-card__date badge text-dark px-3 py-2"><?=$itemDate?></span><?php endif; ?>
            </div>
            <div class="blog-card__body">
              <h3><?=h($itemTitle)?></h3>
              <?php if ($itemDesc !== ''): ?><p class="blog-card__desc"><?=nl2br(h($itemDesc))?></p><?php endif; ?>
              <a class="blog-card__link" href="<?=h($itemUrl)?>">Devamını oku <i class="bi bi-arrow-right"></i></a>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else:
      $coverImage = trim((string)($post['image'] ?? ''));
      $coverClasses = 'detail-hero__media'.($coverImage === '' ? ' detail-hero__media--empty' : '');
      $coverStyle = $coverImage !== '' ? " style=\"background-image:url('".h($coverImage)."');\"" : '';
      $postDate = '';
      if (!empty($post['published_at'])) {
        try {
          $dt = new DateTime($post['published_at']);
          $postDate = $dt->format('d.m.Y');
        } catch (Throwable $e) {
          $postDate = '';
        }
      }
      $shareUrl = $post['pretty_url'] ?? $post['url'] ?? $canonicalUrl;
      $articleHtml = blog_render_content((string)($post['content'] ?? ''));
    ?>
      <div class="detail-hero">
        <div class="<?=$coverClasses?>"<?=$coverStyle?>></div>
        <div class="detail-hero__overlay"></div>
        <div class="detail-hero__content">
          <span class="detail-hero__badge">BİKARE Blog</span>
          <h1 class="detail-hero__title"><?=h($post['title'])?></h1>
          <div class="detail-hero__meta">
            <?php if ($postDate !== ''): ?><span><i class="bi bi-calendar3"></i> <?=$postDate?></span><?php endif; ?>
            <span><i class="bi bi-file-text"></i> <?=mb_strlen((string)($post['content'] ?? '')) > 600 ? 'Okuma süresi ~4 dk' : 'Hızlı okuma'?></span>
          </div>
        </div>
      </div>
      <article class="blog-article">
        <?php if ($post['description'] ?? ''): ?><p class="lead text-muted mb-4"><?=nl2br(h($post['description']))?></p><?php endif; ?>
        <?= $articleHtml !== '' ? $articleHtml : '<p>'.h($post['description'] ?? '').'</p>' ?>
        <div class="blog-share">
          <button class="btn-share" type="button" data-share="<?=h($shareUrl)?>"><i class="bi bi-link-45deg"></i> Bağlantıyı Kopyala</button>
          <a class="btn-share" href="mailto:?subject=<?=rawurlencode($post['title'])?>&body=<?=rawurlencode($shareUrl)?>"><i class="bi bi-envelope"></i> E-posta ile paylaş</a>
        </div>
        <?php if (!empty($post['gallery'])): ?>
          <div class="blog-gallery">
            <?php foreach ($post['gallery'] as $image): ?>
              <img src="<?=h($image)?>" alt="<?=h($post['title'])?> görseli">
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </article>

      <?php if ($otherPosts): ?>
        <section class="other-posts">
          <h3>Diğer Yazılar</h3>
          <div class="blog-grid">
            <?php foreach ($otherPosts as $item):
              $itemTitle = trim((string)($item['title'] ?? ''));
              $itemUrl = trim((string)($item['url'] ?? ''));
              if ($itemTitle === '' || $itemUrl === '') {
                continue;
              }
              $itemDesc = trim((string)($item['description'] ?? ''));
              $itemImage = trim((string)($item['image'] ?? ''));
              $mediaClasses = 'blog-card__media'.($itemImage === '' ? ' blog-card__media--empty' : '');
              $mediaStyle = $itemImage !== '' ? " style=\"background-image:url('".h($itemImage)."');\"" : '';
              $itemDate = '';
              if (!empty($item['published_at'])) {
                try {
                  $dt = new DateTime($item['published_at']);
                  $itemDate = $dt->format('d.m.Y');
                } catch (Throwable $e) {
                  $itemDate = '';
                }
              }
            ?>
              <article class="blog-card">
                <div class="<?=$mediaClasses?>"<?=$mediaStyle?>>
                  <?php if ($itemDate !== ''): ?><span class="blog-card__date badge text-dark px-3 py-2"><?=$itemDate?></span><?php endif; ?>
                </div>
                <div class="blog-card__body">
                  <h3><?=h($itemTitle)?></h3>
                  <?php if ($itemDesc !== ''): ?><p class="blog-card__desc"><?=nl2br(h($itemDesc))?></p><?php endif; ?>
                  <a class="blog-card__link" href="<?=h($itemUrl)?>">Devamını oku <i class="bi bi-arrow-right"></i></a>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    document.querySelectorAll('[data-share]').forEach(btn => {
      btn.addEventListener('click', async () => {
        const url = btn.dataset.share;
        try {
          if (navigator.clipboard && url) {
            await navigator.clipboard.writeText(url);
            btn.classList.add('btn-share--done');
            btn.innerHTML = '<i class="bi bi-check2"></i> Kopyalandı';
            setTimeout(() => {
              btn.classList.remove('btn-share--done');
              btn.innerHTML = '<i class="bi bi-link-45deg"></i> Bağlantıyı Kopyala';
            }, 2400);
            return;
          }
        } catch (e) {
          console.warn('Kopyalama başarısız', e);
        }
        if (url) {
          window.prompt('Bağlantıyı kopyalayın:', url);
        }
      });
    });
  </script>
</body>
</html>
