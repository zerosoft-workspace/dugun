<?php
// admin/marketing_contacts.php — Pazarlama & mailing listesi yönetimi
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/partials/ui.php';

require_admin();
install_schema();

function format_local_datetime(?string $value): string {
  if (!$value) {
    return '—';
  }
  try {
    $dt = new DateTime($value);
    return $dt->format('d.m.Y H:i');
  } catch (Throwable $e) {
    return $value;
  }
}

function format_local_date(?string $value): ?string {
  if (!$value) {
    return null;
  }
  try {
    $dt = new DateTime($value);
    return $dt->format('d.m.Y');
  } catch (Throwable $e) {
    return $value;
  }
}

function normalize_phone(string $phone): string {
  $digits = preg_replace('/\D+/', '', $phone);
  return $digits ?? '';
}

function unique_values(array $values, bool $numeric = false): array {
  $unique = [];
  $seen = [];
  foreach ($values as $value) {
    if (!is_string($value)) {
      continue;
    }
    $trimmed = trim($value);
    if ($trimmed === '') {
      continue;
    }
    $key = $numeric ? normalize_phone($trimmed) : mb_strtolower($trimmed, 'UTF-8');
    if ($key === '') {
      $key = $trimmed;
    }
    if (isset($seen[$key])) {
      continue;
    }
    $seen[$key] = true;
    $unique[] = $trimmed;
  }
  return $unique;
}

function fetch_marketing_contacts(string $category): array {
  switch ($category) {
    case 'events':
      if (!table_exists('events')) {
        return [];
      }
      $sql = "
        SELECT e.id, e.title, e.contact_email, e.couple_phone, e.event_date, e.license_expires_at,
               e.created_at, v.name AS venue_name
          FROM events e
          LEFT JOIN venues v ON v.id = e.venue_id
         WHERE e.is_active = 1
         ORDER BY e.created_at DESC
      ";
      try {
        $rows = pdo()->query($sql)->fetchAll();
      } catch (Throwable $e) {
        return [];
      }
      $contacts = [];
      foreach ($rows as $row) {
        $email = trim($row['contact_email'] ?? '');
        $phone = trim($row['couple_phone'] ?? '');
        if ($email === '' && $phone === '') {
          continue;
        }
        $badges = [];
        if (!empty($row['venue_name'])) {
          $badges[] = 'Salon: '.trim($row['venue_name']);
        }
        if (!empty($row['event_date'])) {
          $badges[] = 'Etkinlik: '.format_local_date($row['event_date']);
        }
        if (!empty($row['license_expires_at'])) {
          $badges[] = 'Lisans: '.format_local_datetime($row['license_expires_at']);
        }
        $contacts[] = [
          'name'       => $row['title'] !== '' ? $row['title'] : ('Etkinlik #'.$row['id']),
          'email'      => $email !== '' ? $email : null,
          'phone'      => $phone !== '' ? $phone : null,
          'context'    => 'Etkinlik Sahibi',
          'badges'     => $badges,
          'created_at' => $row['created_at'] ?? null,
          'notes'      => null,
        ];
      }
      return $contacts;

    case 'dealers':
      if (!table_exists('dealers')) {
        return [];
      }
      $sql = "
        SELECT id, name, email, phone, company, status, code, created_at
          FROM dealers
         ORDER BY created_at DESC
      ";
      try {
        $rows = pdo()->query($sql)->fetchAll();
      } catch (Throwable $e) {
        return [];
      }
      $contacts = [];
      foreach ($rows as $row) {
        $email = trim($row['email'] ?? '');
        $phone = trim($row['phone'] ?? '');
        if ($email === '' && $phone === '') {
          continue;
        }
        $badges = [];
        if (!empty($row['company'])) {
          $badges[] = 'Firma: '.trim($row['company']);
        }
        if (!empty($row['status'])) {
          $badges[] = 'Durum: '.trim($row['status']);
        }
        if (!empty($row['code'])) {
          $badges[] = 'Kod: '.trim($row['code']);
        }
        $contacts[] = [
          'name'       => $row['name'] ?? 'Bayi #'.$row['id'],
          'email'      => $email !== '' ? $email : null,
          'phone'      => $phone !== '' ? $phone : null,
          'context'    => 'Bayi Hesabı',
          'badges'     => $badges,
          'created_at' => $row['created_at'] ?? null,
          'notes'      => null,
        ];
      }
      return $contacts;

    case 'representatives':
      if (!table_exists('dealer_representatives')) {
        return [];
      }
      $sql = "
        SELECT r.id, r.name, r.email, r.phone, r.status, r.created_at,
               d.name AS dealer_name
          FROM dealer_representatives r
          LEFT JOIN dealers d ON d.id = r.dealer_id
         ORDER BY r.created_at DESC
      ";
      try {
        $rows = pdo()->query($sql)->fetchAll();
      } catch (Throwable $e) {
        return [];
      }
      $contacts = [];
      foreach ($rows as $row) {
        $email = trim($row['email'] ?? '');
        $phone = trim($row['phone'] ?? '');
        if ($email === '' && $phone === '') {
          continue;
        }
        $badges = [];
        if (!empty($row['dealer_name'])) {
          $badges[] = 'Bayi: '.trim($row['dealer_name']);
        }
        if (!empty($row['status'])) {
          $badges[] = 'Durum: '.trim($row['status']);
        }
        $contacts[] = [
          'name'       => $row['name'] ?? 'Temsilci #'.$row['id'],
          'email'      => $email !== '' ? $email : null,
          'phone'      => $phone !== '' ? $phone : null,
          'context'    => 'Temsilci',
          'badges'     => $badges,
          'created_at' => $row['created_at'] ?? null,
          'notes'      => null,
        ];
      }
      return $contacts;

    case 'leads':
      if (!table_exists('representative_leads')) {
        return [];
      }
      $sql = "
        SELECT l.id, l.name, l.email, l.phone, l.company, l.status, l.source,
               l.potential_value_cents, l.created_at,
               r.name AS representative_name
          FROM representative_leads l
          LEFT JOIN dealer_representatives r ON r.id = l.representative_id
         ORDER BY l.created_at DESC
      ";
      try {
        $rows = pdo()->query($sql)->fetchAll();
      } catch (Throwable $e) {
        return [];
      }
      $contacts = [];
      foreach ($rows as $row) {
        $email = trim($row['email'] ?? '');
        $phone = trim($row['phone'] ?? '');
        if ($email === '' && $phone === '') {
          continue;
        }
        $badges = [];
        if (!empty($row['status'])) {
          $badges[] = 'Durum: '.trim($row['status']);
        }
        if (!empty($row['representative_name'])) {
          $badges[] = 'Temsilci: '.trim($row['representative_name']);
        }
        if (!empty($row['company'])) {
          $badges[] = 'Firma: '.trim($row['company']);
        }
        if (!empty($row['source'])) {
          $badges[] = 'Kaynak: '.trim($row['source']);
        }
        $notes = null;
        if (!empty($row['potential_value_cents'])) {
          $notes = 'Potansiyel değer: '.format_currency((int)$row['potential_value_cents']);
        }
        $contacts[] = [
          'name'       => $row['name'] ?? 'Lead #'.$row['id'],
          'email'      => $email !== '' ? $email : null,
          'phone'      => $phone !== '' ? $phone : null,
          'context'    => 'Satış Lead\'i',
          'badges'     => $badges,
          'created_at' => $row['created_at'] ?? null,
          'notes'      => $notes,
        ];
      }
      return $contacts;

    case 'orders':
      if (!table_exists('site_orders')) {
        return [];
      }
      $sql = "
        SELECT id, customer_name, customer_email, customer_phone, event_title,
               event_date, status, created_at
          FROM site_orders
         ORDER BY created_at DESC
      ";
      try {
        $rows = pdo()->query($sql)->fetchAll();
      } catch (Throwable $e) {
        return [];
      }
      $contacts = [];
      foreach ($rows as $row) {
        $email = trim($row['customer_email'] ?? '');
        $phone = trim($row['customer_phone'] ?? '');
        if ($email === '' && $phone === '') {
          continue;
        }
        $badges = [];
        if (!empty($row['event_title'])) {
          $badges[] = 'Etkinlik: '.trim($row['event_title']);
        }
        if (!empty($row['event_date'])) {
          $badges[] = 'Tarih: '.format_local_date($row['event_date']);
        }
        if (!empty($row['status'])) {
          $badges[] = 'Durum: '.trim($row['status']);
        }
        $contacts[] = [
          'name'       => $row['customer_name'] ?? 'Sipariş #'.$row['id'],
          'email'      => $email !== '' ? $email : null,
          'phone'      => $phone !== '' ? $phone : null,
          'context'    => 'Online Sipariş',
          'badges'     => $badges,
          'created_at' => $row['created_at'] ?? null,
          'notes'      => null,
        ];
      }
      return $contacts;

    case 'guests':
      if (!table_exists('guest_profiles')) {
        return [];
      }
      $sql = "
        SELECT gp.id, gp.name, gp.display_name, gp.email, gp.marketing_opted_at,
               gp.created_at, gp.last_seen_at,
               e.title AS event_title, e.event_date,
               v.name AS venue_name
          FROM guest_profiles gp
          LEFT JOIN events e ON e.id = gp.event_id
          LEFT JOIN venues v ON v.id = e.venue_id
         WHERE gp.marketing_opt_in = 1
         ORDER BY gp.marketing_opted_at DESC, gp.created_at DESC
      ";
      try {
        $rows = pdo()->query($sql)->fetchAll();
      } catch (Throwable $e) {
        return [];
      }
      $contacts = [];
      foreach ($rows as $row) {
        $email = trim($row['email'] ?? '');
        if ($email === '') {
          continue;
        }
        $badges = [];
        if (!empty($row['event_title'])) {
          $badges[] = 'Etkinlik: '.trim($row['event_title']);
        }
        if (!empty($row['event_date'])) {
          $badges[] = 'Tarih: '.format_local_date($row['event_date']);
        }
        if (!empty($row['venue_name'])) {
          $badges[] = 'Salon: '.trim($row['venue_name']);
        }
        if (!empty($row['last_seen_at'])) {
          $badges[] = 'Son ziyaret: '.format_local_datetime($row['last_seen_at']);
        }
        $notes = null;
        if (!empty($row['marketing_opted_at'])) {
          $notes = 'İzin: '.format_local_datetime($row['marketing_opted_at']);
        }
        $displayName = $row['display_name'] ?? $row['name'] ?? null;
        $contacts[] = [
          'name'       => $displayName && trim($displayName) !== '' ? trim($displayName) : ('Misafir #'.$row['id']),
          'email'      => $email,
          'phone'      => null,
          'context'    => 'Misafir (İzinli)',
          'badges'     => $badges,
          'created_at' => $row['created_at'] ?? null,
          'notes'      => $notes,
        ];
      }
      return $contacts;
  }

  return [];
}

$categories = [
  'events'          => ['label' => 'Etkinlik Sahipleri',        'icon' => 'bi-calendar-heart',  'description' => 'Paneli yöneten çiftlerin iletişim bilgileri.'],
  'dealers'         => ['label' => 'Bayiler',                    'icon' => 'bi-shop',             'description' => 'Platformdaki bayi ve çözüm ortakları.'],
  'representatives' => ['label' => 'Temsilciler',                'icon' => 'bi-person-badge',     'description' => 'Satış temsilcileri ve sorumluları.'],
  'leads'           => ['label' => 'Lead Havuzu',                'icon' => 'bi-lightning-charge', 'description' => 'Temsilci CRM üzerindeki potansiyel müşteriler.'],
  'orders'          => ['label' => 'Online Siparişler',          'icon' => 'bi-bag-check',        'description' => 'Web sitesi üzerinden paket satın alan çiftler.'],
  'guests'          => ['label' => 'Misafir İzinlileri',         'icon' => 'bi-people',           'description' => 'Pazarlama izni vermiş etkinlik misafirleri.'],
];

$selected = $_GET['category'] ?? 'events';
if (!isset($categories[$selected])) {
  $selected = 'events';
}

$search = trim($_GET['q'] ?? '');

$allContacts = [];
$counts = [];
foreach (array_keys($categories) as $key) {
  $contacts = fetch_marketing_contacts($key);
  $allContacts[$key] = $contacts;
  $counts[$key] = count($contacts);
}

$activeContacts = $allContacts[$selected] ?? [];

if ($search !== '') {
  $needle = mb_strtolower($search, 'UTF-8');
  $activeContacts = array_values(array_filter($activeContacts, function (array $contact) use ($needle): bool {
    $haystack = [
      $contact['name'] ?? '',
      $contact['email'] ?? '',
      $contact['phone'] ?? '',
      $contact['context'] ?? '',
      $contact['notes'] ?? '',
    ];
    foreach ($contact['badges'] ?? [] as $badge) {
      $haystack[] = $badge;
    }
    foreach ($haystack as $field) {
      if (!is_string($field)) {
        continue;
      }
      if ($field !== '' && mb_strpos(mb_strtolower($field, 'UTF-8'), $needle) !== false) {
        return true;
      }
    }
    return false;
  }));
}

$emails = unique_values(array_map(function ($contact) {
  return $contact['email'] ?? '';
}, $activeContacts));

$phones = unique_values(array_map(function ($contact) {
  return $contact['phone'] ?? '';
}, $activeContacts), true);

if (($activeContacts !== []) && isset($_GET['export']) && $_GET['export'] === 'csv') {
  $filename = 'marketing-'.preg_replace('~[^a-z0-9]+~i', '-', $selected).'-'.date('Ymd_His').'.csv';
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="'.$filename.'"');
  $fh = fopen('php://output', 'w');
  fputcsv($fh, ['Kategori', 'İsim', 'E-posta', 'Telefon', 'Bilgi', 'Etiketler', 'Oluşturulma']);
  foreach ($activeContacts as $contact) {
    fputcsv($fh, [
      $categories[$selected]['label'] ?? ucfirst($selected),
      $contact['name'] ?? '',
      $contact['email'] ?? '',
      $contact['phone'] ?? '',
      $contact['context'] ?? '',
      implode(' | ', $contact['badges'] ?? []),
      $contact['created_at'] ?? '',
    ]);
  }
  exit;
}

$activeMeta = $categories[$selected];
$activeCount = count($activeContacts);
$emailCount = count($emails);
$phoneCount = count($phones);

function export_url(string $category, string $search): string {
  $params = ['category' => $category, 'export' => 'csv'];
  if ($search !== '') {
    $params['q'] = $search;
  }
  return '?'.http_build_query($params);
}

function filter_url(string $category, string $search): string {
  $params = ['category' => $category];
  if ($search !== '') {
    $params['q'] = $search;
  }
  return '?'.http_build_query($params);
}
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h(APP_NAME)?> — Pazarlama & Mailing</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<?=admin_base_styles()?>
<style>
  .card-lite{
    border-radius:20px;
    border:1px solid rgba(148,163,184,.16);
    box-shadow:0 18px 40px -28px rgba(15,23,42,.35);
    background:var(--admin-surface);
  }
  .summary-card{
    display:flex;
    flex-direction:column;
    gap:6px;
    padding:18px;
    border-radius:18px;
    border:1px solid rgba(15,23,42,.08);
    background:linear-gradient(135deg, rgba(14,165,181,.1), rgba(14,165,181,.03));
    height:100%;
  }
  .summary-card .icon{
    width:44px;
    height:44px;
    border-radius:14px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    background:rgba(14,165,181,.18);
    color:var(--admin-brand);
    font-size:1.2rem;
  }
  .summary-card .count{
    font-size:1.6rem;
    font-weight:700;
    color:var(--admin-ink);
  }
  .summary-card .muted{
    color:var(--admin-muted);
    font-size:.85rem;
  }
  .btn-zs{
    background:var(--admin-brand);
    border:none;
    color:#fff;
    border-radius:12px;
    padding:.55rem 1rem;
    font-weight:600;
  }
  .btn-zs:hover{
    background:var(--admin-brand-dark);
    color:#fff;
  }
  .btn-zs-outline{
    background:#fff;
    border:1px solid rgba(14,165,181,.45);
    color:var(--admin-brand);
    border-radius:12px;
    font-weight:600;
  }
  .btn-zs-outline:hover{
    background:rgba(14,165,181,.12);
    color:var(--admin-brand-dark);
  }
  .badge-soft{
    border-radius:999px;
    background:rgba(14,165,181,.12);
    color:var(--admin-brand-dark);
    padding:.32rem .75rem;
    font-weight:600;
    font-size:.78rem;
  }
  .contact-badge{
    background:rgba(15,23,42,.05);
    color:var(--admin-ink);
    border-radius:999px;
    padding:.2rem .65rem;
    margin-right:.25rem;
    margin-bottom:.25rem;
    font-size:.78rem;
    display:inline-flex;
    align-items:center;
    gap:.25rem;
  }
  .contact-badge i{
    font-size:.75rem;
  }
  textarea.copy-field{
    min-height:140px;
    resize:vertical;
    font-family:monospace;
  }
  .table thead th{
    font-size:.78rem;
    text-transform:uppercase;
    letter-spacing:.02em;
    color:var(--admin-muted);
  }
  .table tbody td{
    vertical-align:middle;
  }
  .empty-state{
    padding:32px;
    text-align:center;
    color:var(--admin-muted);
  }
</style>
</head>
<body class="admin-body">
<?php admin_layout_start('marketing', 'Pazarlama & Mailing', 'Tüm iletişim kanallarını tek ekranda toplayın ve kampanyalarınızı hızlandırın.', 'bi-envelope-paper'); ?>

  <?php flash_box(); ?>

  <div class="row g-3 mb-3">
    <?php foreach ($categories as $key => $meta): ?>
      <div class="col-12 col-sm-6 col-xl-4">
        <div class="summary-card<?= $selected === $key ? ' border-2 border-brand' : '' ?>">
          <div class="d-flex align-items-center justify-content-between">
            <div class="icon"><i class="bi <?=h($meta['icon'])?>"></i></div>
            <a class="btn btn-sm btn-light" href="<?=h(filter_url($key, $search))?>">Görüntüle</a>
          </div>
          <div>
            <h5 class="mb-1"><?=h($meta['label'])?></h5>
            <div class="count"><?=number_format($counts[$key] ?? 0, 0, ',', '.')?></div>
            <div class="muted"><?=h($meta['description'])?></div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card-lite p-3 mb-4">
    <form method="get" class="row g-3 align-items-end">
      <div class="col-sm-6 col-lg-4">
        <label class="form-label">Arama</label>
        <input type="search" class="form-control" name="q" value="<?=h($search)?>" placeholder="Ad, e-posta, telefon veya etiket">
      </div>
      <div class="col-sm-6 col-lg-3">
        <label class="form-label">Kategori</label>
        <select class="form-select" name="category">
          <?php foreach ($categories as $key => $meta): ?>
            <option value="<?=h($key)?>" <?=$selected === $key ? 'selected' : ''?>><?=h($meta['label'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-4 col-lg-2">
        <label class="form-label d-block">&nbsp;</label>
        <button class="btn btn-zs w-100" type="submit">Filtrele</button>
      </div>
      <?php if ($search !== ''): ?>
        <div class="col-sm-4 col-lg-2">
          <label class="form-label d-block">&nbsp;</label>
          <a class="btn btn-outline-secondary w-100" href="<?=h(filter_url($selected, ''))?>">Sıfırla</a>
        </div>
      <?php endif; ?>
      <div class="col-sm-4 col-lg-3 ms-auto">
        <label class="form-label d-block">&nbsp;</label>
        <a class="btn btn-zs-outline w-100" href="<?=h(export_url($selected, $search))?>"><i class="bi bi-download me-1"></i>CSV indir</a>
      </div>
    </form>
  </div>

  <div class="mb-3">
    <h4 class="mb-1 d-flex align-items-center gap-2">
      <i class="bi <?=h($activeMeta['icon'])?>"></i>
      <?=h($activeMeta['label'])?>
    </h4>
    <div class="text-muted">Toplam <?=number_format($activeCount, 0, ',', '.')?> kişi — <?=number_format($emailCount, 0, ',', '.')?> e-posta, <?=number_format($phoneCount, 0, ',', '.')?> telefon kaydı</div>
  </div>

  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card-lite p-3 h-100">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h5 class="m-0">E-posta listesi</h5>
          <button class="btn btn-sm btn-light" type="button" data-copy="emails"><i class="bi bi-clipboard"></i> Kopyala</button>
        </div>
        <textarea id="emails" class="form-control copy-field" readonly><?=h(implode("\n", $emails))?></textarea>
        <div class="small text-muted mt-2">Liste virgül veya satır sonu ile ayrılmıştır; e-posta gönderim aracınıza doğrudan yapıştırabilirsiniz.</div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card-lite p-3 h-100">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h5 class="m-0">SMS / Telefon listesi</h5>
          <button class="btn btn-sm btn-light" type="button" data-copy="phones"><i class="bi bi-clipboard"></i> Kopyala</button>
        </div>
        <textarea id="phones" class="form-control copy-field" readonly><?=h(implode("\n", $phones))?></textarea>
        <div class="small text-muted mt-2">Telefon numaraları düz metin olarak listelenir; toplu SMS araçlarına aktarmadan önce doğrulama yapmayı unutmayın.</div>
      </div>
    </div>
  </div>

  <div class="card-lite mt-4">
    <?php if (empty($activeContacts)): ?>
      <div class="empty-state">
        <i class="bi bi-inbox fs-1 mb-2"></i>
        <p class="mb-0">Bu kategori için listelenecek kişi bulunamadı. Filtreleri genişletmeyi deneyin.</p>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th scope="col">Kişi / Kurum</th>
              <th scope="col">E-posta</th>
              <th scope="col">Telefon</th>
              <th scope="col">Etiketler</th>
              <th scope="col">Oluşturulma</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($activeContacts as $contact): ?>
              <tr>
                <td>
                  <div class="fw-semibold"><?=h($contact['name'] ?? '')?></div>
                  <div class="text-muted small">
                    <?=h($contact['context'] ?? '')?>
                    <?php if (!empty($contact['notes'])): ?>
                      <span class="d-block mt-1"><?=h($contact['notes'])?></span>
                    <?php endif; ?>
                  </div>
                </td>
                <td><?=h($contact['email'] ?? '—')?></td>
                <td><?=h($contact['phone'] ?? '—')?></td>
                <td>
                  <?php foreach ($contact['badges'] ?? [] as $badge): ?>
                    <span class="contact-badge"><i class="bi bi-tag"></i><?=h($badge)?></span>
                  <?php endforeach; ?>
                </td>
                <td><?=h($contact['created_at'] ? format_local_datetime($contact['created_at']) : '—')?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

<?php admin_layout_end(); ?>
<script>
document.querySelectorAll('[data-copy]').forEach(function(button){
  button.addEventListener('click', function(){
    var targetId = button.getAttribute('data-copy');
    var field = document.getElementById(targetId);
    if (!field) {
      return;
    }
    var value = field.value;
    if (!value) {
      return;
    }
    navigator.clipboard.writeText(value).then(function(){
      var original = button.innerHTML;
      button.innerHTML = '<i class="bi bi-check2"></i> Kopyalandı';
      setTimeout(function(){ button.innerHTML = original; }, 2000);
    }).catch(function(){
      field.select();
      document.execCommand('copy');
      var original = button.innerHTML;
      button.innerHTML = '<i class="bi bi-check2"></i> Kopyalandı';
      setTimeout(function(){ button.innerHTML = original; }, 2000);
    });
  });
});
</script>
</body>
</html>
