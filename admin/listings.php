<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/dealers.php';
require_once __DIR__.'/../includes/listings.php';
require_once __DIR__.'/partials/ui.php';

require_admin();
install_schema();

$me = admin_user();
$action = $_POST['do'] ?? '';

if ($action !== '') {
  csrf_or_die();
  try {
    if ($action === 'save_category') {
      $categoryId = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
      $data = [
        'name' => $_POST['name'] ?? '',
        'description' => $_POST['description'] ?? '',
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
      ];
      $savedId = listing_category_save($data, $categoryId ?: null);
      flash('ok', $categoryId ? 'Kategori güncellendi.' : 'Yeni kategori eklendi.');
      $params = $categoryId ? ['category' => $savedId] : [];
      $redirect = $_SERVER['PHP_SELF'];
      if ($params) {
        $redirect .= '?'.http_build_query($params);
      }
      redirect($redirect.'#categories');
    }

    if ($action === 'handle_request') {
      $requestId = (int)($_POST['request_id'] ?? 0);
      $decision = $_POST['decision'] ?? '';
      if (!$requestId) {
        throw new InvalidArgumentException('Talep bulunamadı.');
      }
      if ($decision === 'approve') {
        listing_category_request_update($requestId, LISTING_CATEGORY_REQUEST_APPROVED, [
          'admin_id' => (int)$me['id'],
          'note' => $_POST['note'] ?? '',
          'create_category' => !empty($_POST['create_category']),
        ]);
        flash('ok', 'Kategori talebi onaylandı.');
      } elseif ($decision === 'reject') {
        listing_category_request_update($requestId, LISTING_CATEGORY_REQUEST_REJECTED, [
          'admin_id' => (int)$me['id'],
          'note' => $_POST['note'] ?? '',
        ]);
        flash('ok', 'Kategori talebi reddedildi.');
      } else {
        throw new InvalidArgumentException('Geçersiz işlem.');
      }
      redirect($_SERVER['PHP_SELF'].'#requests');
    }

    if ($action === 'set_status') {
      $listingId = (int)($_POST['listing_id'] ?? 0);
      $status = $_POST['status'] ?? '';
      if (!$listingId || $status === '') {
        throw new InvalidArgumentException('İlan durumu güncellenemedi.');
      }
      listing_admin_set_status($listingId, $status, (int)$me['id'], $_POST['note'] ?? '');
      flash('ok', 'İlan durumu güncellendi.');
      $anchor = !empty($_POST['anchor']) ? '#'.preg_replace('~[^a-z0-9_-]+~i', '', $_POST['anchor']) : '';
      redirect($_SERVER['PHP_SELF'].$anchor);
    }
  } catch (Throwable $e) {
    flash('err', $e->getMessage());
    redirect($_SERVER['PHP_SELF']);
  }
}

$statusFilter = $_GET['status'] ?? LISTING_STATUS_PENDING;
$categoryFilter = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$search = trim($_GET['q'] ?? '');
$requestStatus = $_GET['request_status'] ?? LISTING_CATEGORY_REQUEST_PENDING;

$listings = listing_admin_search([
  'status' => $statusFilter,
  'category_id' => $categoryFilter ?: null,
  'q' => $search !== '' ? $search : null,
]);
$counts = listing_admin_counts();
$categories = listing_category_all();
$categoryEditId = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$categoryEdit = $categoryEditId ? listing_category_get($categoryEditId) : null;
$requests = listing_category_requests($requestStatus === 'all' ? 'all' : $requestStatus);

$title = 'Anlaşmalı Şirketler';
$subtitle = 'Bayi ilanlarını onaylayın, kategorileri yönetin ve vitrinleri güncel tutun.';

$pageStyles = <<<'CSS'
<style>
  .summary-card { border-radius:20px; background:#fff; border:1px solid rgba(148,163,184,.2); box-shadow:0 28px 60px -44px rgba(15,23,42,.35); padding:1.4rem 1.6rem; height:100%; }
  .summary-card .label { font-size:.75rem; text-transform:uppercase; letter-spacing:.08em; color:#64748b; }
  .summary-card .value { font-size:2rem; font-weight:700; }
  .listing-status-badge { border-radius:999px; padding:.45rem 1.1rem; font-weight:600; font-size:.8rem; }
  .listing-review-card { border-radius:26px; border:1px solid rgba(148,163,184,.18); background:linear-gradient(135deg,rgba(255,255,255,.95),rgba(241,245,249,.92)); box-shadow:0 40px 90px -50px rgba(15,23,42,.45); padding:1.8rem; margin-bottom:2rem; position:relative; overflow:hidden; }
  .listing-filter-card { border-radius:24px; background:#fff; border:1px solid rgba(148,163,184,.18); }
  .listing-filter-card .card-body { border-radius:24px; }
  .listing-review-card::after { content:""; position:absolute; inset:auto -30% -40% -30%; height:220px; background:radial-gradient(circle at top, rgba(14,165,181,.25), transparent 70%); opacity:.6; pointer-events:none; }
  .listing-review-header { position:relative; display:flex; gap:1.8rem; flex-wrap:wrap; align-items:flex-start; z-index:1; }
  .listing-cover { width:260px; min-height:210px; border-radius:22px; overflow:hidden; position:relative; flex-shrink:0; background-size:cover; background-position:center; background-repeat:no-repeat; display:flex; align-items:center; justify-content:center; }
  .listing-cover::after { content:""; position:absolute; inset:0; background:linear-gradient(180deg, rgba(15,23,42,.1), rgba(15,23,42,.55)); opacity:.75; }
  .listing-cover.has-image::after { opacity:.45; }
  .listing-cover .cover-initial { position:relative; font-size:3.2rem; font-weight:700; color:#fff; text-shadow:0 18px 38px rgba(15,23,42,.45); letter-spacing:.04em; }
  .listing-cover .category-pill { position:absolute; left:16px; bottom:16px; padding:.45rem 1rem; border-radius:999px; font-weight:600; font-size:.85rem; background:rgba(15,23,42,.75); color:#f8fafc; backdrop-filter:blur(6px); }
  .listing-cover.tone-1 { background-image:linear-gradient(135deg,#bae6fd,#0ea5b5); }
  .listing-cover.tone-2 { background-image:linear-gradient(135deg,#ede9fe,#a855f7); }
  .listing-cover.tone-3 { background-image:linear-gradient(135deg,#fee2e2,#fb7185); }
  .listing-cover.tone-4 { background-image:linear-gradient(135deg,#dcfce7,#22c55e); }
  .listing-cover.tone-5 { background-image:linear-gradient(135deg,#fef3c7,#f97316); }
  .listing-header-body { flex:1; min-width:260px; }
  .listing-title { font-size:1.6rem; font-weight:700; color:#0f172a; }
  .meta-chips { display:flex; flex-wrap:wrap; gap:.6rem; margin-top:1.2rem; }
  .meta-chip { display:inline-flex; align-items:center; gap:.45rem; padding:.45rem .9rem; border-radius:999px; background:rgba(15,23,42,.06); color:#0f172a; font-weight:500; font-size:.85rem; }
  .meta-chip i { color:#0ea5b5; }
  .listing-review-body { position:relative; z-index:1; display:grid; grid-template-columns:minmax(0,1fr) minmax(260px,320px); gap:1.8rem; margin-top:1.6rem; }
  @media (max-width: 1200px) { .listing-review-body { grid-template-columns:1fr; } .listing-review-aside { order:-1; } }
  .listing-review-main { color:#334155; font-size:.98rem; }
  .summary-callout { border-radius:20px; background:linear-gradient(135deg,rgba(14,165,181,.16),rgba(99,102,241,.12)); padding:1.1rem 1.3rem; font-weight:600; color:#0f172a; display:flex; align-items:center; gap:.6rem; margin-bottom:1.2rem; }
  .description-content { line-height:1.65; white-space:pre-line; }
  .status-note-block { border-radius:18px; background:rgba(253,230,138,.28); padding:1rem 1.2rem; color:#92400e; font-weight:600; margin-top:1.2rem; }
  .package-grid { display:grid; gap:1rem; margin-top:1.6rem; }
  .package-card { border-radius:18px; border:1px solid rgba(14,165,181,.2); background:linear-gradient(135deg,rgba(240,253,255,.85),#fff); padding:1rem 1.25rem; box-shadow:0 20px 50px -46px rgba(14,165,181,.45); }
  .package-card h6 { font-weight:700; color:#0f172a; }
  .package-card p { margin-bottom:0; color:#475569; }
  .package-price { font-weight:700; color:#0ea5b5; font-size:1.05rem; }
  .listing-review-aside { display:flex; flex-direction:column; gap:1.2rem; }
  .meta-panel { border-radius:20px; background:#fff; border:1px solid rgba(148,163,184,.22); padding:1.15rem 1.3rem; box-shadow:0 28px 60px -48px rgba(15,23,42,.35); }
  .meta-panel h6 { font-weight:700; font-size:1rem; color:#0f172a; margin-bottom:.75rem; }
  .meta-dl { margin:0; padding:0; }
  .meta-dl div { display:flex; justify-content:space-between; gap:.75rem; margin-bottom:.55rem; font-size:.9rem; color:#475569; }
  .meta-dl div:last-child { margin-bottom:0; }
  .meta-dl dt { margin:0; font-weight:600; color:#0f172a; }
  .meta-dl dd { margin:0; text-align:right; font-weight:500; color:#1f2937; }
  .contact-block { background:linear-gradient(135deg,rgba(14,165,181,.12),rgba(59,130,246,.12)); }
  .contact-line { display:flex; align-items:center; gap:.55rem; color:#0f172a; font-weight:600; font-size:.92rem; margin-bottom:.5rem; }
  .contact-line i { color:#0ea5b5; }
  .contact-block .contact-line.small, .contact-block .contact-line.text-muted { font-weight:500; }
  .contact-block .contact-line:last-child { margin-bottom:0; }
  .listing-review-actions { position:relative; z-index:1; margin-top:1.8rem; padding-top:1.3rem; display:flex; flex-wrap:wrap; gap:1rem; align-items:center; border-top:1px dashed rgba(148,163,184,.35); }
  .listing-review-actions form { display:flex; flex-wrap:wrap; align-items:center; gap:.75rem; }
  .listing-review-actions .form-select, .listing-review-actions .form-control { border-radius:12px; min-width:170px; }
  .listing-review-actions .btn { border-radius:12px; padding:.6rem 1.4rem; }
  .listing-review-card.highlight { border-color:rgba(14,165,181,.55); box-shadow:0 55px 120px -45px rgba(14,165,181,.6); }
  .filter-form .form-select, .filter-form .form-control { border-radius:12px; }
  .category-card, .request-card { border-radius:22px; border:1px solid rgba(14,165,181,.18); background:linear-gradient(135deg,rgba(14,165,181,.09),#fff); box-shadow:0 26px 60px -42px rgba(14,165,181,.45); }
  .category-card .card-body, .request-card .card-body { padding:1.5rem; }
  .request-actions { display:flex; gap:.5rem; flex-wrap:wrap; }
  .request-actions button { flex:1; min-width:120px; }
  .status-badge-approved { background:#dcfce7; color:#166534; }
  .status-badge-pending { background:#fef3c7; color:#92400e; }
  .status-badge-rejected { background:#fee2e2; color:#b91c1c; }
</style>
CSS;
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=h(APP_NAME)?> — Anlaşmalı Şirketler</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <?=admin_base_styles()?>
  <?=$pageStyles?>
</head>
<body class="admin-body">
<?php admin_layout_start('listings', $title, $subtitle, 'bi-card-list'); ?>
<?=flash_messages()?>
<div class="container-xxl py-4">
  <div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
      <div class="summary-card">
        <div class="label">Onay Bekleyen</div>
        <div class="value text-warning"><?=h($counts[LISTING_STATUS_PENDING] ?? 0)?></div>
        <div class="small text-muted">Editör kontrolüne hazır</div>
      </div>
    </div>
    <div class="col-sm-6 col-xl-3">
      <div class="summary-card">
        <div class="label">Yayında</div>
        <div class="value text-success"><?=h($counts[LISTING_STATUS_APPROVED] ?? 0)?></div>
        <div class="small text-muted">Ziyaretçilere açık ilanlar</div>
      </div>
    </div>
    <div class="col-sm-6 col-xl-3">
      <div class="summary-card">
        <div class="label">Reddedilen</div>
        <div class="value text-danger"><?=h($counts[LISTING_STATUS_REJECTED] ?? 0)?></div>
        <div class="small text-muted">Geri bildirim bekleyen ilanlar</div>
      </div>
    </div>
    <div class="col-sm-6 col-xl-3">
      <div class="summary-card">
        <div class="label">Taslaklar</div>
        <div class="value text-primary"><?=h($counts[LISTING_STATUS_DRAFT] ?? 0)?></div>
        <div class="small text-muted">Bayi tarafından hazırlanan taslaklar</div>
      </div>
    </div>
  </div>

  <div class="row g-4">
    <div class="col-lg-8">
      <div class="listing-filter-card card border-0 shadow-sm mb-4">
        <div class="card-body p-4">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">İlan İnceleme</h5>
            <span class="badge bg-light text-dark"><?=count($listings)?> kayıt</span>
          </div>
          <form method="get" class="row g-2 align-items-end filter-form">
            <input type="hidden" name="request_status" value="<?=h($requestStatus)?>">
            <div class="col-md-4">
              <label class="form-label">Durum</label>
              <select class="form-select" name="status">
                <?php $statusOptions = [
                  'all' => 'Tümü',
                  LISTING_STATUS_PENDING => 'Onay Bekleyen',
                  LISTING_STATUS_APPROVED => 'Yayında',
                  LISTING_STATUS_REJECTED => 'Reddedilen',
                  LISTING_STATUS_DRAFT => 'Taslak',
                  LISTING_STATUS_ARCHIVED => 'Arşivlenen',
                ];
                foreach ($statusOptions as $value => $label): ?>
                  <option value="<?=h($value)?>" <?=$statusFilter === $value ? 'selected' : ''?>><?=h($label)?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Kategori</label>
              <select class="form-select" name="category_id">
                <option value="">Tümü</option>
                <?php foreach ($categories as $category): ?>
                  <option value="<?=h($category['id'])?>" <?=$categoryFilter === (int)$category['id'] ? 'selected' : ''?>><?=h($category['name'])?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Arama</label>
              <div class="input-group">
                <input type="search" class="form-control" name="q" placeholder="İlan veya bayi ara" value="<?=h($search)?>">
                <button type="submit" class="btn btn-outline-primary"><i class="bi bi-search"></i></button>
              </div>
            </div>
          </form>
        </div>
      </div>

      <?php if (!$listings): ?>
        <div class="listing-review-card text-center">
          <p class="text-muted mb-0">Seçilen filtrelere uygun ilan bulunamadı.</p>
        </div>
      <?php else: ?>
        <?php foreach ($listings as $listing): ?>
          <?php [$statusLabel, $statusColor] = listing_status_label($listing['status']); ?>
          <?php $anchorId = 'listing-'.$listing['id']; ?>
          <?php
            $heroImage = '';
            if (!empty($listing['hero_image'])) {
              $heroImage = $listing['hero_image'];
              if ($heroImage && !preg_match('~^https?://~', $heroImage)) {
                $heroImage = rtrim(BASE_URL, '/').'/'.ltrim($heroImage, '/');
              }
            }
            $tone = (($listing['id'] ?? 0) % 5) + 1;
            $initialSource = trim((string)($listing['dealer_name'] ?: $listing['title']));
            if ($initialSource === '') {
              $initialSource = APP_NAME;
            }
            $initial = mb_strtoupper(mb_substr($initialSource, 0, 1, 'UTF-8'), 'UTF-8');
            $coverClasses = 'listing-cover '.($heroImage ? 'has-image' : 'fallback tone-'.$tone);
            $coverStyleAttr = '';
            if ($heroImage) {
              $coverStyleAttr = sprintf(' style="background-image:url(\'%s\')"', h($heroImage));
            }
            $formatTime = static function (?string $value): string {
              return $value ? date('d.m.Y H:i', strtotime($value)) : '—';
            };
            $taxParts = array_filter([
              trim((string)($listing['dealer_tax_office'] ?? '')),
              trim((string)($listing['dealer_tax_number'] ?? '')),
            ]);
            $packageCount = count($listing['packages'] ?? []);
          ?>
          <article class="listing-review-card" id="<?=h($anchorId)?>">
            <div class="listing-review-header">
              <div class="<?=h($coverClasses)?>"<?=$coverStyleAttr?>>
                <?php if (!$heroImage): ?>
                  <div class="cover-initial"><?=h($initial)?></div>
                <?php endif; ?>
                <span class="category-pill"><?=h($listing['category_name'] ?? 'Kategori belirtilmedi')?></span>
              </div>
              <div class="listing-header-body">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                  <div>
                    <h5 class="listing-title mb-1"><?=h($listing['title'])?></h5>
                    <div class="text-muted small">Bayi: <?=h($listing['dealer_name'])?><?php if (!empty($listing['dealer_company'])): ?> · <?=h($listing['dealer_company'])?><?php endif; ?></div>
                    <div class="text-muted small">Son güncelleme: <?=h($formatTime($listing['updated_at'] ?? $listing['created_at'] ?? null))?></div>
                  </div>
                  <span class="listing-status-badge bg-<?=$statusColor?> text-white"><?=$statusLabel?></span>
                </div>
                <div class="meta-chips">
                  <span class="meta-chip"><i class="bi bi-geo-alt-fill"></i> <?=h($listing['city'])?> / <?=h($listing['district'])?></span>
                  <?php if (!empty($listing['requested_at'])): ?>
                    <span class="meta-chip"><i class="bi bi-send"></i> Onaya gönderim: <?=h(date('d.m.Y', strtotime($listing['requested_at'])))?></span>
                  <?php endif; ?>
                  <?php if (!empty($listing['published_at'])): ?>
                    <span class="meta-chip"><i class="bi bi-broadcast-pin"></i> Yayında: <?=h(date('d.m.Y', strtotime($listing['published_at'])))?></span>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <div class="listing-review-body">
              <div class="listing-review-main">
                <?php if (!empty($listing['summary'])): ?>
                  <div class="summary-callout"><i class="bi bi-stars"></i><?=h($listing['summary'])?></div>
                <?php endif; ?>
                <?php if (!empty($listing['description'])): ?>
                  <div class="description-content"><?=nl2br(h($listing['description']))?></div>
                <?php else: ?>
                  <div class="description-content text-muted fst-italic">Bayi bu ilan için detaylı açıklama eklemedi.</div>
                <?php endif; ?>
                <?php if (!empty($listing['status_note'])): ?>
                  <div class="status-note-block"><i class="bi bi-exclamation-triangle me-2"></i><?=nl2br(h($listing['status_note']))?></div>
                <?php endif; ?>
                <?php if (!empty($listing['packages'])): ?>
                  <div class="package-grid">
                    <?php foreach ($listing['packages'] as $package): ?>
                      <div class="package-card">
                        <div class="d-flex justify-content-between align-items-start gap-3 mb-1">
                          <h6 class="mb-0"><?=h($package['name'])?></h6>
                          <div class="package-price"><?=format_currency((int)$package['price_cents'])?></div>
                        </div>
                        <?php if (!empty($package['description'])): ?>
                          <p class="mb-0"><?=h($package['description'])?></p>
                        <?php else: ?>
                          <p class="mb-0 text-muted">Paket açıklaması eklenmedi.</p>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
              <aside class="listing-review-aside">
                <div class="meta-panel">
                  <h6>İlan Bilgileri</h6>
                  <dl class="meta-dl">
                    <div>
                      <dt>Durum</dt>
                      <dd><span class="badge bg-<?=$statusColor?>"><?=$statusLabel?></span></dd>
                    </div>
                    <div>
                      <dt>Kategori</dt>
                      <dd><?=h($listing['category_name'] ?? 'Belirtilmedi')?></dd>
                    </div>
                    <div>
                      <dt>Paket sayısı</dt>
                      <dd><?=h($packageCount)?></dd>
                    </div>
                    <div>
                      <dt>Oluşturulma</dt>
                      <dd><?=h($formatTime($listing['created_at'] ?? null))?></dd>
                    </div>
                    <?php if (!empty($listing['requested_at'])): ?>
                      <div>
                        <dt>Onaya gönderim</dt>
                        <dd><?=h($formatTime($listing['requested_at']))?></dd>
                      </div>
                    <?php endif; ?>
                    <?php if (!empty($listing['approved_at'])): ?>
                      <div>
                        <dt>Onay tarihi</dt>
                        <dd><?=h($formatTime($listing['approved_at']))?></dd>
                      </div>
                    <?php endif; ?>
                    <?php if (!empty($listing['rejected_at'])): ?>
                      <div>
                        <dt>Red tarihi</dt>
                        <dd><?=h($formatTime($listing['rejected_at']))?></dd>
                      </div>
                    <?php endif; ?>
                    <?php if (!empty($listing['published_at'])): ?>
                      <div>
                        <dt>Yayın tarihi</dt>
                        <dd><?=h($formatTime($listing['published_at']))?></dd>
                      </div>
                    <?php endif; ?>
                  </dl>
                </div>
                <div class="meta-panel contact-block">
                  <h6>Bayi İletişim</h6>
                  <div class="contact-line"><i class="bi bi-building"></i> <?=h($listing['dealer_company'] ?: $listing['dealer_name'])?></div>
                  <?php if (!empty($listing['dealer_phone'])): ?>
                    <div class="contact-line"><i class="bi bi-telephone-outbound"></i> <?=h($listing['dealer_phone'])?></div>
                  <?php endif; ?>
                  <?php if (!empty($listing['dealer_email'])): ?>
                    <div class="contact-line"><i class="bi bi-envelope-open"></i> <?=h($listing['dealer_email'])?></div>
                  <?php endif; ?>
                  <?php if (!empty($listing['dealer_invoice_email'])): ?>
                    <div class="contact-line small text-muted"><i class="bi bi-receipt"></i> Fatura: <?=h($listing['dealer_invoice_email'])?></div>
                  <?php endif; ?>
                  <?php if (!empty($listing['dealer_billing_address'])): ?>
                    <div class="contact-line small"><i class="bi bi-geo-alt"></i> <?=h($listing['dealer_billing_address'])?></div>
                  <?php endif; ?>
                  <?php if ($taxParts): ?>
                    <div class="contact-line small text-muted"><i class="bi bi-file-earmark-text"></i> <?=h(implode(' / ', $taxParts))?></div>
                  <?php endif; ?>
                  <?php if (empty($listing['dealer_phone']) && empty($listing['dealer_email']) && empty($listing['dealer_invoice_email']) && empty($listing['dealer_billing_address']) && !$taxParts): ?>
                    <div class="text-muted small">Bayi profilinde iletişim bilgisi bulunmuyor.</div>
                  <?php endif; ?>
                </div>
              </aside>
            </div>
            <div class="listing-review-actions">
              <form method="post">
                <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                <input type="hidden" name="do" value="set_status">
                <input type="hidden" name="listing_id" value="<?=h($listing['id'])?>">
                <input type="hidden" name="anchor" value="<?=h($anchorId)?>">
                <select class="form-select" name="status" required>
                  <option value="">Durum seçin</option>
                  <option value="<?=LISTING_STATUS_APPROVED?>">Onayla</option>
                  <option value="<?=LISTING_STATUS_REJECTED?>">Reddet</option>
                  <option value="<?=LISTING_STATUS_ARCHIVED?>">Arşivle</option>
                </select>
                <input type="text" class="form-control" name="note" placeholder="Not (opsiyonel)">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check2"></i> Güncelle</button>
              </form>
              <?php if ($listing['status'] === LISTING_STATUS_APPROVED): ?>
                <a class="btn btn-outline-success" target="_blank" href="<?=h(BASE_URL.'/public/partners.php?listing='.urlencode($listing['slug']))?>"><i class="bi bi-box-arrow-up-right"></i> Yayındaki ilanı aç</a>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="col-lg-4">
      <div class="card category-card mb-4" id="categories">
        <div class="card-body">
          <h5 class="mb-3">Kategori Yönetimi</h5>
          <form method="post" class="mb-4">
            <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="do" value="save_category">
            <?php if ($categoryEdit): ?>
              <input type="hidden" name="category_id" value="<?=h($categoryEdit['id'])?>">
            <?php endif; ?>
            <div class="mb-2">
              <label class="form-label">Kategori Adı</label>
              <input type="text" class="form-control" name="name" value="<?=h($categoryEdit['name'] ?? '')?>" required>
            </div>
            <div class="mb-2">
              <label class="form-label">Açıklama</label>
              <textarea class="form-control" name="description" rows="2" placeholder="Kısa bilgi"><?=h($categoryEdit['description'] ?? '')?></textarea>
            </div>
            <div class="form-check form-switch mb-3">
              <input class="form-check-input" type="checkbox" id="category-active" name="is_active" <?=(!empty($categoryEdit) ? (int)$categoryEdit['is_active'] : 1) ? 'checked' : ''?>>
              <label class="form-check-label" for="category-active">Aktif olarak yayınla</label>
            </div>
            <div class="d-grid">
              <button type="submit" class="btn btn-primary"><?=$categoryEdit ? 'Kategoriyi Güncelle' : 'Kategori Ekle'?></button>
            </div>
          </form>

          <div class="list-group list-group-flush small">
            <?php foreach ($categories as $category): ?>
              <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="<?=h($_SERVER['PHP_SELF'].'?category='.(int)$category['id'].'#categories')?>">
                <span>
                  <strong><?=h($category['name'])?></strong>
                  <?php if (!empty($category['description'])): ?>
                    <div class="text-muted small"><?=h($category['description'])?></div>
                  <?php endif; ?>
                </span>
                <span class="badge <?=((int)$category['is_active']) ? 'status-badge-approved' : 'status-badge-rejected'?>"><?=((int)$category['is_active']) ? 'Aktif' : 'Pasif'?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="card request-card" id="requests">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">Kategori Talepleri</h5>
            <form method="get" class="d-flex gap-2 align-items-center">
              <input type="hidden" name="status" value="<?=h($statusFilter)?>">
              <input type="hidden" name="category_id" value="<?=h($categoryFilter)?>">
              <input type="hidden" name="q" value="<?=h($search)?>">
              <select class="form-select form-select-sm" name="request_status" onchange="this.form.submit()">
                <?php $requestOptions = [
                  LISTING_CATEGORY_REQUEST_PENDING => 'Bekleyen',
                  LISTING_CATEGORY_REQUEST_APPROVED => 'Onaylanan',
                  LISTING_CATEGORY_REQUEST_REJECTED => 'Reddedilen',
                  'all' => 'Tümü',
                ];
                foreach ($requestOptions as $value => $label): ?>
                  <option value="<?=h($value)?>" <?=$requestStatus === $value ? 'selected' : ''?>><?=h($label)?></option>
                <?php endforeach; ?>
              </select>
            </form>
          </div>

          <?php if (!$requests): ?>
            <p class="text-muted mb-0">Bu filtreyle eşleşen talep bulunamadı.</p>
          <?php else: ?>
            <?php foreach ($requests as $request): ?>
              <div class="border rounded-4 p-3 mb-3 bg-white">
                <div class="d-flex justify-content-between align-items-start">
                  <div>
                    <strong><?=h($request['name'])?></strong>
                    <div class="text-muted small">Bayi: <?=h($request['dealer_name'])?></div>
                    <?php if (!empty($request['details'])): ?>
                      <div class="text-muted small">Talep: <?=h($request['details'])?></div>
                    <?php endif; ?>
                  </div>
                  <?php $statusClass = $request['status'] === LISTING_CATEGORY_REQUEST_APPROVED ? 'status-badge-approved' : ($request['status'] === LISTING_CATEGORY_REQUEST_REJECTED ? 'status-badge-rejected' : 'status-badge-pending'); ?>
                  <span class="badge <?=$statusClass?>"><?=h(ucfirst($request['status']))?></span>
                </div>
                <div class="text-muted small mt-2">Gönderim: <?=date('d.m.Y H:i', strtotime($request['created_at']))?></div>
                <?php if ($request['status'] === LISTING_CATEGORY_REQUEST_PENDING): ?>
                  <form method="post" class="mt-3 request-actions">
                    <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="do" value="handle_request">
                    <input type="hidden" name="request_id" value="<?=h($request['id'])?>">
                    <textarea class="form-control" name="note" rows="2" placeholder="Not (opsiyonel)"></textarea>
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" value="1" id="create-category-<?=$request['id']?>" name="create_category" checked>
                      <label class="form-check-label small" for="create-category-<?=$request['id']?>">Onaylandığında kategori oluştur</label>
                    </div>
                    <div class="d-flex gap-2 w-100">
                      <button type="submit" name="decision" value="approve" class="btn btn-success btn-sm flex-fill"><i class="bi bi-check2"></i> Onayla</button>
                      <button type="submit" name="decision" value="reject" class="btn btn-outline-danger btn-sm flex-fill"><i class="bi bi-x"></i> Reddet</button>
                    </div>
                  </form>
                <?php elseif (!empty($request['response_note'])): ?>
                  <div class="alert alert-light border mt-3 mb-0 small">Not: <?=h($request['response_note'])?></div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<?php admin_layout_end(); ?>
</body>
</html>
