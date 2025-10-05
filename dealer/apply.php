<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/dealers.php';

install_schema();

$submitted = isset($_GET['done']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_or_die();
  $name = trim($_POST['name'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $phone = trim($_POST['phone'] ?? '');
  $company = trim($_POST['company'] ?? '');
  $notes = trim($_POST['notes'] ?? '');

  try {
    $billing = dealer_validate_billing_inputs($_POST);
  } catch (Throwable $e) {
    flash('err', $e->getMessage());
    redirect($_SERVER['PHP_SELF']);
  }

  try {
    $taxDocumentPath = dealer_process_tax_document($_FILES['tax_document'] ?? null, true);
  } catch (Throwable $e) {
    flash('err', $e->getMessage());
    redirect($_SERVER['PHP_SELF']);
  }

  if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash('err', 'Lütfen adınızı ve geçerli bir e-posta adresini girin.');
    redirect($_SERVER['PHP_SELF']);
  }

  if (dealer_find_by_email($email)) {
    flash('err', 'Bu e-posta ile kayıtlı bir bayi başvurusu bulunuyor.');
    redirect($_SERVER['PHP_SELF']);
  }

  $st = pdo()->prepare("INSERT INTO dealers (name,email,phone,company,billing_title,billing_address,tax_office,tax_number,invoice_email,tax_document_path,notes,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,'pending',?,?)");
  $st->execute([
    $name,
    $email,
    $phone,
    $company,
    $billing['billing_title'],
    $billing['billing_address'],
    $billing['tax_office'],
    $billing['tax_number'],
    $billing['invoice_email'],
    $taxDocumentPath,
    $notes,
    now(),
    now(),
  ]);
  $dealerId = (int)pdo()->lastInsertId();
  dealer_ensure_codes($dealerId);

  $dealer = dealer_get($dealerId);
  dealer_notify_new_application($dealer);
  dealer_send_application_receipt($dealer);

  flash('ok', 'Başvurunuz alınmıştır. En kısa sürede sizinle iletişime geçeceğiz.');
  redirect($_SERVER['PHP_SELF'].'?done=1');
}
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?=h(APP_NAME)?> — Bayi Başvurusu</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root{ --brand:#0ea5b5; --brand-dark:#0b8b98; --ink:#0f172a; --muted:#5b6678; }
    *{box-sizing:border-box;}
    body{margin:0;min-height:100vh;padding:2.5rem;background:radial-gradient(circle at top,#e0f7fb 0%,#f8fafc 55%,#fff);font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;color:var(--ink);display:flex;align-items:center;justify-content:center;}
    .apply-shell{width:100%;max-width:1200px;background:#fff;border-radius:32px;border:1px solid rgba(148,163,184,.18);box-shadow:0 50px 140px -65px rgba(15,23,42,.55);display:flex;overflow:hidden;}
    .apply-visual{flex:1.1;position:relative;padding:3rem 3.4rem;background:linear-gradient(145deg,rgba(14,165,181,.9),rgba(14,116,144,.85)),url('https://images.unsplash.com/photo-1526948128573-703ee1aeb6fa?auto=format&fit=crop&w=1200&q=80') center/cover;color:#fff;display:flex;flex-direction:column;justify-content:space-between;}
    .apply-visual::after{content:"";position:absolute;inset:0;background:linear-gradient(155deg,rgba(15,23,42,.18),rgba(15,23,42,.5));}
    .apply-visual > *{position:relative;z-index:1;}
    .badge{display:inline-flex;align-items:center;gap:.65rem;padding:.5rem 1.25rem;border-radius:999px;background:rgba(255,255,255,.18);font-weight:600;letter-spacing:.08em;text-transform:uppercase;font-size:.78rem;}
    .visual-title{font-size:2.3rem;font-weight:800;line-height:1.15;margin:1.6rem 0 1rem;max-width:430px;}
    .visual-text{font-size:1.04rem;line-height:1.7;color:rgba(255,255,255,.9);max-width:460px;}
    .feature-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1.1rem;margin-top:2rem;}
    .feature-card{padding:1.1rem 1.2rem;border-radius:18px;background:rgba(255,255,255,.14);backdrop-filter:blur(6px);display:flex;flex-direction:column;gap:.45rem;}
    .feature-card strong{font-size:1.05rem;}
    .feature-card p{margin:0;font-size:.92rem;color:rgba(255,255,255,.82);line-height:1.5;}
    .visual-footer{margin-top:2.6rem;font-size:.84rem;color:rgba(255,255,255,.75);max-width:340px;}
    .apply-form{flex:.9;padding:3.2rem 3.4rem;display:flex;flex-direction:column;gap:2rem;}
    .apply-form header{display:flex;flex-direction:column;gap:.6rem;}
    .apply-form h1{font-size:2rem;font-weight:800;margin:0;}
    .apply-form p{margin:0;color:var(--muted);font-size:.98rem;line-height:1.6;}
    form{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1.4rem;}
    form .full{grid-column:1/-1;}
    label{font-weight:600;color:var(--muted);margin-bottom:.35rem;display:block;}
    input,textarea{width:100%;border-radius:14px;border:1px solid rgba(148,163,184,.28);padding:.8rem 1rem;font-size:1rem;}
    textarea{min-height:140px;resize:vertical;}
    input:focus,textarea:focus{border-color:var(--brand);box-shadow:0 0 0 .25rem rgba(14,165,181,.18);outline:none;}
    .btn-brand{grid-column:1/-1;background:linear-gradient(135deg,#0ea5b5,#0b8b98);color:#fff;border:none;border-radius:16px;padding:1rem 1.2rem;font-weight:700;font-size:1rem;transition:transform .2s ease,box-shadow .2s ease;}
    .btn-brand:hover{transform:translateY(-1px);box-shadow:0 24px 38px -26px rgba(14,165,181,.6);color:#fff;}
    .alert{border-radius:16px;font-weight:500;}
    .success-card{background:#f0fdf9;border:1px solid rgba(14,165,181,.25);border-radius:20px;padding:2.2rem;display:flex;flex-direction:column;gap:1rem;}
    .success-card h2{margin:0;font-weight:800;color:var(--ink);}
    .success-card p{margin:0;color:var(--muted);line-height:1.6;}
    .success-card a{align-self:flex-start;font-weight:700;color:var(--brand);text-decoration:none;}
    .success-card a:hover{text-decoration:underline;color:var(--brand-dark);}
    .contact-hint{font-size:.9rem;color:var(--muted);} 
    @media(max-width:992px){body{padding:1.5rem;} .apply-shell{flex-direction:column;} .apply-visual{padding:2.6rem;} .apply-form{padding:2.4rem;}}
    @media(max-width:576px){.apply-form{padding:2rem;} .visual-title{font-size:1.8rem;}}
  </style>
</head>
<body>
  <div class="apply-shell">
    <aside class="apply-visual">
      <div>
        <span class="badge">Bayi Ekosistemi</span>
        <h1 class="visual-title">BİKARE ile salonunuzun dijital gelirini artırın.</h1>
        <p class="visual-text">Etkinlik sahiplerine sunduğunuz deneyimi BİKARE teknoloji altyapısıyla güçlendirin. QR davetiye yönetimi, misafir paneli ve cashback yapısıyla salonlarınızın tercih edilirliğini artırın.</p>
        <div class="feature-grid">
          <div class="feature-card">
            <strong>Hızlı Aktivasyon</strong>
            <p>Onaylandıktan sonra paneliniz ve kalıcı QR yönetiminiz dakikalar içinde hazır hale gelir.</p>
          </div>
          <div class="feature-card">
            <strong>Gelir Paylaşımı</strong>
            <p>Satılan paketlerden Cashback kazanarak cari bakiyenizi artırın, yeni etkinlikler üretin.</p>
          </div>
          <div class="feature-card">
            <strong>Markalı Deneyim</strong>
            <p>BİKARE tasarımlı misafir sayfalarıyla çiftlerinize unutulmaz, paylaşılabilir galeriler sunun.</p>
          </div>
          <div class="feature-card">
            <strong>Özel Destek</strong>
            <p>Zerosoft müşteri başarısı ekibi, operasyonlarınıza özel eğitim ve lansman desteği sağlar.</p>
          </div>
        </div>
      </div>
      <p class="visual-footer">BİKARE bayi programı; düğün salonları, organizasyon firmaları ve etkinlik ajansları için tasarlandı.</p>
    </aside>
    <section class="apply-form">
      <header>
        <h1>Bayi Başvuru Formu</h1>
        <p>Bize birkaç bilgi bırakmanız yeterli. Ekibimiz başvurunuzu en kısa sürede inceleyip sizinle iletişime geçecek.</p>
      </header>
      <?php if ($submitted): ?>
        <?php flash_box(); ?>
        <div class="success-card">
          <h2>Başvurunuz bize ulaştı! 🎉</h2>
          <p>BİKARE bayi ekibi en kısa sürede sizinle iletişime geçerek panel kurulumunu ve eğitim sürecini planlayacak. Bu süreçte ek dokümanlara ihtiyaç duyarsak belirttiğiniz e-posta adresinden ulaşacağız.</p>
          <a href="login.php">Panel hesabınız mı var? Giriş yapın →</a>
          <div class="contact-hint">Sorularınız için <strong>hello@zerosoft.com.tr</strong> adresine e-posta gönderebilirsiniz.</div>
        </div>
      <?php else: ?>
        <?php flash_box(); ?>
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
          <div>
            <label>Ad Soyad</label>
            <input name="name" required placeholder="Örn. Ayşe Yılmaz">
          </div>
          <div>
            <label>Firma Adı</label>
            <input name="company" placeholder="Salon veya organizasyon şirketiniz">
          </div>
          <div>
            <label>E-posta</label>
            <input type="email" name="email" required placeholder="ornek@firma.com">
          </div>
          <div>
            <label>Telefon</label>
            <input name="phone" placeholder="05xx xxx xx xx">
          </div>
          <div>
            <label>Fatura Ünvanı</label>
            <input name="billing_title" required placeholder="Vergi levhasındaki ünvan">
          </div>
          <div>
            <label>Vergi Dairesi</label>
            <input name="tax_office" required placeholder="Örn. Kadıköy">
          </div>
          <div>
            <label>Vergi Numarası</label>
            <input name="tax_number" required placeholder="Örn. 1234567890">
          </div>
          <div>
            <label>Fatura E-postası</label>
            <input type="email" name="invoice_email" placeholder="finans@firma.com">
          </div>
          <div class="full">
            <label>Notlarınız</label>
            <textarea name="notes" placeholder="Salon sayısı, bulunduğunuz şehir ve işbirliği beklentileriniz"></textarea>
          </div>
          <div class="full">
            <label>Fatura Adresi</label>
            <textarea name="billing_address" required placeholder="Vergi levhasında yer alan resmi adres"></textarea>
          </div>
          <div class="full">
            <label>Vergi Levhası (PDF, JPG veya PNG)</label>
            <input type="file" name="tax_document" accept=".pdf,.jpg,.jpeg,.png" required>
          </div>
          <button class="btn-brand" type="submit">Başvuruyu Gönder</button>
        </form>
        <div class="contact-hint">Başvurunuzu göndermek için vergi levhanızı yüklemeniz zorunludur. Belgeleriniz yalnızca sözleşme ve faturalandırma süreçlerinde kullanılacaktır.</div>
      <?php endif; ?>
    </section>
  </div>
</body>
</html>
