<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/representatives.php';
require_once __DIR__.'/../includes/dealers.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/partials/ui.php';

require_admin();
install_schema();

$action = $_POST['do'] ?? '';
if ($action) {
  csrf_or_die();
  $contextDealerId = isset($_POST['context_dealer_id']) ? (int)$_POST['context_dealer_id'] : 0;
  try {
    if ($action === 'create') {
      $dealerIds = array_map('intval', $_POST['assign_dealer_ids'] ?? []);
      $repId = representative_create([
        'name' => $_POST['name'] ?? '',
        'email' => $_POST['email'] ?? '',
        'phone' => $_POST['phone'] ?? '',
        'status' => $_POST['status'] ?? REPRESENTATIVE_STATUS_ACTIVE,
        'commission_rate' => $_POST['commission_rate'] ?? 10,
        'password' => $_POST['password'] ?? '',
        'dealer_ids' => $dealerIds,
      ]);
      if (!empty($dealerIds)) {
        $contextDealerId = $dealerIds[0];
      }
      flash('ok', 'Temsilci oluşturuldu.');
      $params = ['id' => $repId];
      if ($contextDealerId > 0) {
        $params['dealer_id'] = $contextDealerId;
      }
      redirect($_SERVER['PHP_SELF'].'?'.http_build_query($params));
    }
    if ($action === 'update') {
      $repId = (int)($_POST['representative_id'] ?? 0);
      $data = [
        'name' => $_POST['name'] ?? '',
        'email' => $_POST['email'] ?? '',
        'phone' => $_POST['phone'] ?? '',
        'status' => $_POST['status'] ?? REPRESENTATIVE_STATUS_ACTIVE,
        'commission_rate' => $_POST['commission_rate'] ?? 10,
      ];
      $password = trim($_POST['password'] ?? '');
      if ($password !== '') {
        $data['password'] = $password;
      }
      representative_update($repId, $data);
      flash('ok', 'Temsilci bilgileri güncellendi.');
      $params = ['id' => $repId];
      if ($contextDealerId > 0) {
        $params['dealer_id'] = $contextDealerId;
      }
      redirect($_SERVER['PHP_SELF'].'?'.http_build_query($params));
    }
    if ($action === 'assign') {
      $repId = (int)($_POST['representative_id'] ?? 0);
      $dealerIds = array_map('intval', $_POST['dealer_ids'] ?? []);
      representative_update_assignments($repId, $dealerIds);
      flash('ok', $dealerIds ? 'Temsilci için bayi atamaları güncellendi.' : 'Temsilci tüm bayilerden kaldırıldı.');
      $contextDealerId = $dealerIds[0] ?? 0;
      $params = ['id' => $repId];
      if ($contextDealerId > 0) {
        $params['dealer_id'] = $contextDealerId;
      }
      redirect($_SERVER['PHP_SELF'].'?'.http_build_query($params));
    }
  } catch (Throwable $e) {
    flash('err', $e->getMessage());
    $params = [];
    if (!empty($_POST['representative_id'])) {
      $params['id'] = (int)$_POST['representative_id'];
    }
    if (!empty($contextDealerId)) {
      $params['dealer_id'] = $contextDealerId;
    }
    $back = $_SERVER['PHP_SELF'];
    if ($params) {
      $back .= '?'.http_build_query($params);
    }
    redirect($back);
  }
}

$dealerContextId = isset($_GET['dealer_id']) ? (int)$_GET['dealer_id'] : 0;
$statusFilter = $_GET['status'] ?? 'all';
$assignedFilter = $_GET['assigned'] ?? ($dealerContextId > 0 ? 'assigned' : 'all');
$search = trim($_GET['q'] ?? '');

$counts = representative_status_counts();
$listFilters = [
  'status' => $statusFilter,
  'assigned' => $assignedFilter,
  'q' => $search,
];
if ($dealerContextId > 0) {
  $listFilters['dealer_id'] = $dealerContextId;
}
$representatives = representative_list($listFilters);

$selectedId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$selectedRep = $selectedId ? representative_detail($selectedId) : null;
$selectedTotals = $selectedRep ? representative_commission_totals($selectedId) : null;

if (!$selectedRep && $dealerContextId > 0) {
  foreach ($representatives as $rep) {
    if ((int)($rep['dealer_id'] ?? 0) === $dealerContextId) {
      $selectedId = (int)$rep['id'];
      $selectedRep = representative_detail($selectedId);
      $selectedTotals = $selectedRep ? representative_commission_totals($selectedId) : null;
      break;
    }
  }
}

$dealers = pdo()->query("SELECT id, name, company, status FROM dealers ORDER BY name")->fetchAll();

function representative_filters(array $base = []): string {
  $query = array_filter($base, fn($value) => $value !== null && $value !== '' && $value !== 'all');
  return $query ? ('?'.http_build_query($query)) : '';
}
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h(APP_NAME)?> — Bayi Temsilcileri</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<?=admin_base_styles()?>
<style>
  .card-lite h5 { font-weight: 600; }
  .rep-stat {
    border-radius: 18px;
    background: linear-gradient(135deg, rgba(14,165,181,.12), rgba(255,255,255,.92));
    border: 1px solid rgba(14,165,181,.18);
    padding: 18px 20px;
    box-shadow: 0 22px 48px -32px rgba(14,165,181,.4);
    min-height: 138px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
  }
  .rep-stat .label {
    font-size: .78rem;
    text-transform: uppercase;
    letter-spacing: .14em;
    color: var(--admin-muted);
    font-weight: 600;
  }
  .rep-stat .value {
    font-size: 1.9rem;
    font-weight: 700;
    color: var(--admin-ink);
  }
  .rep-stat .muted {
    font-size: .85rem;
    color: var(--admin-muted);
  }
  .rep-assignment-list {
    display: flex;
    flex-wrap: wrap;
    gap: .35rem;
  }
  .rep-chip {
    display: inline-flex;
    align-items: center;
    padding: .3rem .65rem;
    border-radius: 999px;
    background: rgba(14,165,181,.14);
    color: var(--admin-ink);
    font-size: .78rem;
    font-weight: 600;
  }
  .card-lite form .form-label {
    font-weight: 600;
    color: var(--admin-ink);
  }
  .card-lite .form-control,
  .card-lite .form-select {
    border-radius: 12px;
    border-color: rgba(15,23,42,.12);
    padding: .55rem .75rem;
  }
  .card-lite .form-control:focus,
  .card-lite .form-select:focus {
    border-color: rgba(14,165,181,.45);
    box-shadow: 0 0 0 .15rem rgba(14,165,181,.15);
  }
  .card-lite .input-group-text {
    border-radius: 0 12px 12px 0;
    border-color: rgba(15,23,42,.12);
    background: rgba(14,165,181,.08);
    color: var(--admin-brand);
    font-weight: 600;
  }
  .table thead th {
    text-transform: uppercase;
    letter-spacing: .08em;
    font-size: .72rem;
  }
  .table tbody td {
    font-size: .92rem;
  }
  .table-info {
    --bs-table-bg: rgba(14,165,181,.08);
    --bs-table-color: var(--admin-ink);
  }
  .btn-outline-brand {
    border-radius: 12px;
    border: 1px solid rgba(14,165,181,.45);
    color: var(--admin-brand);
    font-weight: 600;
  }
  .btn-outline-brand:hover,
  .btn-outline-brand:focus {
    background: rgba(14,165,181,.12);
    color: var(--admin-brand-dark);
  }
  .badge.bg-success-subtle { background: rgba(34,197,94,.16)!important; color: #0f5132!important; }
  .badge.bg-secondary-subtle { background: rgba(148,163,184,.18)!important; color: #334155!important; }
  .list-unstyled strong { font-weight: 600; }
  @media (max-width: 991px) {
    .card-lite { padding: 20px; }
    .card-lite form .form-label { font-size: .92rem; }
  }
</style>
</head>
<body class="admin-body">
<?php admin_layout_start('representatives', 'Bayi Temsilcileri', 'Temsilci hesaplarını yönetin, bayilere atayın ve komisyon durumlarını takip edin.');
?>

  <?php flash_box(); ?>

  <div class="row g-4">
    <div class="col-lg-4">
      <div class="card-lite p-4 mb-4">
        <h5 class="mb-3">Yeni Temsilci Oluştur</h5>
        <form method="post" class="row g-3">
          <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="do" value="create">
          <?php if ($dealerContextId > 0): ?>
            <input type="hidden" name="context_dealer_id" value="<?=$dealerContextId?>">
          <?php endif; ?>
          <div class="col-12">
            <label class="form-label">Ad Soyad</label>
            <input class="form-control" name="name" required>
          </div>
          <div class="col-12">
            <label class="form-label">E-posta</label>
            <input type="email" class="form-control" name="email" required>
          </div>
          <div class="col-12">
            <label class="form-label">Telefon</label>
            <input class="form-control" name="phone">
          </div>
          <div class="col-6">
            <label class="form-label">Durum</label>
            <select class="form-select" name="status">
              <option value="active">Aktif</option>
              <option value="inactive">Pasif</option>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label">Komisyon (%)</label>
            <div class="input-group">
              <input type="number" step="0.1" min="0" max="100" class="form-control" name="commission_rate" value="10" required>
              <span class="input-group-text">%</span>
            </div>
          </div>
          <div class="col-12">
            <label class="form-label">Şifre</label>
            <input type="password" class="form-control" name="password" required>
          </div>
          <div class="col-12">
            <label class="form-label">Bayi Atamaları (Opsiyonel)</label>
            <select class="form-select" name="assign_dealer_ids[]" multiple size="6">
              <?php foreach ($dealers as $dealer): ?>
                <option value="<?= (int)$dealer['id'] ?>" <?=$dealerContextId === (int)$dealer['id'] ? 'selected' : ''?>><?=h($dealer['name'])?></option>
              <?php endforeach; ?>
            </select>
            <small class="text-muted">CTRL/CMD tuşu ile birden fazla bayi seçebilirsiniz.</small>
          </div>
          <div class="col-12 d-grid">
            <button class="btn btn-brand" type="submit">Temsilci Oluştur</button>
          </div>
        </form>
      </div>

      <div class="card-lite p-4">
        <h6 class="text-uppercase text-muted fw-semibold small mb-3">Durum Özeti</h6>
        <ul class="list-unstyled mb-0">
          <li class="d-flex justify-content-between align-items-center py-1"><span>Toplam</span><strong><?=$counts['total']?></strong></li>
          <li class="d-flex justify-content-between align-items-center py-1"><span>Aktif</span><strong><?=$counts[REPRESENTATIVE_STATUS_ACTIVE] ?? 0?></strong></li>
          <li class="d-flex justify-content-between align-items-center py-1"><span>Pasif</span><strong><?=$counts[REPRESENTATIVE_STATUS_INACTIVE] ?? 0?></strong></li>
          <li class="d-flex justify-content-between align-items-center py-1"><span>Atanmış</span><strong><?=$counts['assigned']?></strong></li>
          <li class="d-flex justify-content-between align-items-center py-1"><span>Atanmamış</span><strong><?=$counts['unassigned']?></strong></li>
        </ul>
      </div>
    </div>

    <div class="col-lg-8">
      <div class="card-lite p-4 mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
          <h5 class="mb-0">Temsilci Listesi</h5>
          <form class="d-flex gap-2" method="get">
            <input type="hidden" name="id" value="<?=$selectedId?>">
            <?php if ($dealerContextId > 0): ?>
              <input type="hidden" name="dealer_id" value="<?=$dealerContextId?>">
            <?php endif; ?>
            <input class="form-control" type="search" name="q" value="<?=h($search)?>" placeholder="Temsilci ara...">
            <select class="form-select" name="status">
              <option value="all" <?=$statusFilter === 'all' ? 'selected' : ''?>>Durum (tümü)</option>
              <option value="active" <?=$statusFilter === 'active' ? 'selected' : ''?>>Aktif</option>
              <option value="inactive" <?=$statusFilter === 'inactive' ? 'selected' : ''?>>Pasif</option>
            </select>
            <select class="form-select" name="assigned">
              <option value="all" <?=$assignedFilter === 'all' ? 'selected' : ''?>>Atama (tümü)</option>
              <option value="assigned" <?=$assignedFilter === 'assigned' ? 'selected' : ''?>>Atanmış</option>
              <option value="unassigned" <?=$assignedFilter === 'unassigned' ? 'selected' : ''?>>Atanmamış</option>
            </select>
            <button class="btn btn-outline-brand" type="submit">Filtrele</button>
          </form>
        </div>
        <div class="table-responsive">
          <table class="table align-middle">
            <thead>
              <tr><th>Temsilci</th><th>E-posta</th><th>Durum</th><th>Bayi</th><th></th></tr>
            </thead>
            <tbody>
              <?php if (!$representatives): ?>
                <tr><td colspan="5" class="text-center text-muted">Kayıt bulunamadı.</td></tr>
              <?php else: ?>
                <?php foreach ($representatives as $rep): ?>
                  <tr<?php if ($selectedId === (int)$rep['id']): ?> class="table-info"<?php endif; ?>>
                    <td class="fw-semibold"><?=h($rep['name'])?></td>
                    <td><?=h($rep['email'])?></td>
                    <td><span class="badge <?=($rep['status'] ?? '') === REPRESENTATIVE_STATUS_ACTIVE ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary'?>"><?=($rep['status'] ?? '') === REPRESENTATIVE_STATUS_ACTIVE ? 'Aktif' : 'Pasif'?></span></td>
                    <td>
                      <?php if (empty($rep['dealers'])): ?>
                        <span class="text-muted">Atanmamış</span>
                      <?php else: ?>
                        <span class="d-inline-flex align-items-center gap-1">
                          <?=h($rep['dealers'][0]['name'])?>
                          <?php if (count($rep['dealers']) > 1): ?>
                            <small class="text-muted">+<?=count($rep['dealers']) - 1?></small>
                          <?php endif; ?>
                        </span>
                      <?php endif; ?>
                    </td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-brand" href="<?=h($_SERVER['PHP_SELF']).'?id='.(int)$rep['id']?>">Görüntüle</a></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card-lite p-4">
        <?php if (!$selectedRep): ?>
          <p class="text-muted mb-0">Listeden bir temsilci seçerek detaylarını görüntüleyebilir ve bayilere atayabilirsiniz.</p>
        <?php else: ?>
          <div class="d-flex flex-wrap justify-content-between align-items-start mb-3 gap-3">
            <div>
              <h5 class="mb-1"><?=h($selectedRep['name'])?></h5>
              <p class="text-muted mb-0"><?=h($selectedRep['email'])?><?php if (!empty($selectedRep['phone'])): ?> · <?=h($selectedRep['phone'])?><?php endif; ?></p>
            </div>
            <span class="badge <?=$selectedRep['status'] === REPRESENTATIVE_STATUS_ACTIVE ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary'?>"><?=$selectedRep['status'] === REPRESENTATIVE_STATUS_ACTIVE ? 'Aktif' : 'Pasif'?></span>
          </div>

          <div class="row g-3 mb-4">
            <div class="col-md-4">
              <div class="rep-stat">
                <div class="label">Komisyon Oranı</div>
                <div class="value">%<?=h(number_format($selectedRep['commission_rate'], 1))?></div>
                <div class="muted">Son giriş: <?= $selectedRep['last_login_at'] ? h(date('d.m.Y H:i', strtotime($selectedRep['last_login_at']))) : '—' ?></div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="rep-stat">
                <div class="label">Toplam Komisyon</div>
                <div class="value"><?=h(format_currency($selectedTotals['total_amount'] ?? 0))?></div>
                <div class="muted">Bekleyen: <?=h(format_currency($selectedTotals['pending_amount'] ?? 0))?></div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="rep-stat">
                <div class="label">Atandığı Bayiler</div>
                <?php if (empty($selectedRep['dealers'])): ?>
                  <div class="muted">Atama yapılmamış</div>
                <?php else: ?>
                  <div class="rep-assignment-list">
                    <?php foreach ($selectedRep['dealers'] as $dealer): ?>
                      <span class="rep-chip"><?=h($dealer['name'])?></span>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <form method="post" class="row g-3 mb-4">
            <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="do" value="update">
            <input type="hidden" name="representative_id" value="<?=$selectedId?>">
            <?php if ($dealerContextId > 0): ?>
              <input type="hidden" name="context_dealer_id" value="<?=$dealerContextId?>">
            <?php endif; ?>
            <div class="col-md-4">
              <label class="form-label">Ad Soyad</label>
              <input class="form-control" name="name" value="<?=h($selectedRep['name'])?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">E-posta</label>
              <input type="email" class="form-control" name="email" value="<?=h($selectedRep['email'])?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Telefon</label>
              <input class="form-control" name="phone" value="<?=h($selectedRep['phone'] ?? '')?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Durum</label>
              <select class="form-select" name="status">
                <option value="active" <?=$selectedRep['status'] === REPRESENTATIVE_STATUS_ACTIVE ? 'selected' : ''?>>Aktif</option>
                <option value="inactive" <?=$selectedRep['status'] === REPRESENTATIVE_STATUS_INACTIVE ? 'selected' : ''?>>Pasif</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Komisyon (%)</label>
              <div class="input-group">
                <input type="number" step="0.1" min="0" max="100" class="form-control" name="commission_rate" value="<?=h(number_format($selectedRep['commission_rate'] ?? 10.0, 1, '.', ''))?>" required>
                <span class="input-group-text">%</span>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Şifre</label>
              <input type="password" class="form-control" name="password" placeholder="Yeni şifre belirlemek için doldurun">
            </div>
            <div class="col-12 d-grid">
              <button class="btn btn-brand" type="submit">Bilgileri Güncelle</button>
            </div>
          </form>

          <form method="post" class="row g-3">
            <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="do" value="assign">
            <input type="hidden" name="representative_id" value="<?=$selectedId?>">
            <?php if ($dealerContextId > 0): ?>
              <input type="hidden" name="context_dealer_id" value="<?=$dealerContextId?>">
            <?php endif; ?>
            <div class="col-md-8">
              <label class="form-label">Bayi Atamaları</label>
              <select class="form-select" name="dealer_ids[]" multiple size="8">
                <?php $selectedDealerIds = $selectedRep['dealer_ids'] ?? []; ?>
                <?php foreach ($dealers as $dealer): ?>
                  <?php $isSelected = in_array((int)$dealer['id'], $selectedDealerIds, true); ?>
                  <option value="<?= (int)$dealer['id'] ?>" <?=$isSelected ? 'selected' : ''?>><?=h($dealer['name'])?></option>
                <?php endforeach; ?>
              </select>
              <small class="text-muted d-block mt-1">Birden fazla bayi seçebilirsiniz. Seçimi temizlemek için CTRL/CMD ile tıklayın.</small>
            </div>
            <div class="col-md-4 d-grid align-items-end">
              <button class="btn btn-outline-brand" type="submit">Atamayı Güncelle</button>
            </div>
          </form>
          <?php if (!empty($selectedRep['dealers'])): ?>
            <div class="table-responsive mt-4">
              <table class="table table-sm align-middle mb-0">
                <thead>
                  <tr><th>Bayi</th><th>Durum</th><th>Atama Tarihi</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($selectedRep['dealers'] as $dealer): ?>
                    <?php
                      $status = $dealer['status'] ?? 'pending';
                      switch ($status) {
                        case 'active':
                        case 'approved':
                          $badgeClass = 'bg-success-subtle text-success';
                          $statusLabel = 'Aktif';
                          break;
                        case 'pending':
                          $badgeClass = 'bg-warning-subtle text-warning';
                          $statusLabel = 'Beklemede';
                          break;
                        case 'inactive':
                          $badgeClass = 'bg-secondary-subtle text-secondary';
                          $statusLabel = 'Pasif';
                          break;
                        case 'suspended':
                          $badgeClass = 'bg-danger-subtle text-danger';
                          $statusLabel = 'Askıda';
                          break;
                        case 'blocked':
                          $badgeClass = 'bg-danger-subtle text-danger';
                          $statusLabel = 'Engelli';
                          break;
                        default:
                          $badgeClass = 'bg-warning-subtle text-warning';
                          $statusLabel = ucfirst($status);
                          break;
                      }
                    ?>
                    <tr>
                      <td><?=h($dealer['name'])?></td>
                      <td><span class="badge <?=$badgeClass?>"><?=h($statusLabel)?></span></td>
                      <td><?= $dealer['assigned_at'] ? h(date('d.m.Y H:i', strtotime($dealer['assigned_at']))) : '—' ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

<?php admin_layout_end(); ?>
</body>
</html>
