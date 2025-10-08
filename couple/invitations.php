<?php
require_once __DIR__.'/_auth.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/invitations.php';
require_once __DIR__.'/../includes/mailer.php';

$EVENT_ID = couple_current_event_id();
$ev = couple_event_row_current();
if (!$ev) {
  http_response_code(404);
  exit('Etkinlik bulunamadı');
}

$action = $_POST['do'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '') {
  csrf_or_die();
  try {
    switch ($action) {
      case 'save_template':
        invitation_template_save($EVENT_ID, [
          'title' => $_POST['title'] ?? '',
          'subtitle' => $_POST['subtitle'] ?? '',
          'message' => $_POST['message'] ?? '',
          'primary_color' => $_POST['primary_color'] ?? '',
          'accent_color' => $_POST['accent_color'] ?? '',
          'button_label' => $_POST['button_label'] ?? '',
        ]);
        flash('ok', 'Davetiyeniz güncellendi.');
        break;
      case 'add_contact':
        $name = $_POST['name'] ?? '';
        $email = $_POST['email'] ?? '';
        $phone = $_POST['phone'] ?? '';
        invitation_contact_create($EVENT_ID, $name, $email, $phone);
        flash('ok', 'Yeni davetli kaydedildi.');
        break;
      case 'update_contact':
        $contactId = (int)($_POST['contact_id'] ?? 0);
        $name = $_POST['name'] ?? '';
        $email = $_POST['email'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $password = $_POST['new_password'] ?? null;
        $password = is_string($password) ? trim($password) : null;
        if ($password === '') {
          $password = null;
        }
        invitation_contact_update($EVENT_ID, $contactId, $name, $email, $phone, $password);
        if ($password !== null) {
          flash('ok', 'Davetli bilgileri ve şifre güncellendi.');
        } else {
          flash('ok', 'Davetli bilgileri güncellendi.');
        }
        break;
      case 'delete_contact':
        $contactId = (int)($_POST['contact_id'] ?? 0);
        invitation_contact_delete($EVENT_ID, $contactId);
        flash('ok', 'Davetli silindi.');
        break;
      case 'send_email':
        $contactId = (int)($_POST['contact_id'] ?? 0);
        $contact = invitation_contact_by_id($EVENT_ID, $contactId);
        if (!$contact) {
          throw new RuntimeException('Davetli bulunamadı.');
        }
        $email = trim((string)($contact['email'] ?? ''));
        if ($email === '') {
          throw new RuntimeException('Bu davetlinin e-posta adresi eksik.');
        }
        $template = invitation_template_get($EVENT_ID);
        $inviteUrl = public_invitation_url((string)$contact['invite_token']);
        $subject = invitation_contact_email_subject($template, $ev);
        $body = invitation_contact_email_body($template, $ev, $inviteUrl);
        $sent = send_mail_simple($email, $subject, $body);
        if (!$sent) {
          $err = $GLOBALS['MAIL_LAST_ERROR'] ?? 'Mail gönderilemedi. Lütfen SMTP ayarlarınızı kontrol edin.';
          throw new RuntimeException($err);
        }
        invitation_contact_mark_sent($EVENT_ID, $contactId);
        flash('ok', 'Davet e-postası gönderildi.');
        break;
      default:
        throw new RuntimeException('Geçersiz işlem.');
    }
  } catch (Throwable $e) {
    flash('err', $e->getMessage());
  }
  redirect($_SERVER['REQUEST_URI']);
}

$template = invitation_template_get($EVENT_ID);
$contacts = invitation_contacts_list($EVENT_ID);
$invitePreviewUrl = !empty($contacts) ? public_invitation_url((string)$contacts[0]['invite_token']) : '';
$accent = invitation_color_or_default($template['accent_color'] ?? null, '#f8fafc');
$primary = invitation_color_or_default($template['primary_color'] ?? null, '#0ea5b5');
$cardShareUrl = public_invitation_card_share_url((string)$template['share_token']);
$cardVersion = substr(md5(json_encode([
  $template['title'],
  $template['subtitle'],
  $template['message'],
  $template['primary_color'],
  $template['accent_color'],
  $template['button_label'],
], JSON_UNESCAPED_UNICODE)), 0, 12);
$cardPreviewUrl = $cardShareUrl.($cardVersion ? '&v='.$cardVersion : '');
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($ev['title'])?> — Davetiye Yönetimi</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
:root{ --ink:#0f172a; --muted:#64748b; --zs:<?=$primary?>; --accent:<?=$accent?>; }
body{ background:#f8fafc; font-family:'Inter','Segoe UI','Helvetica Neue',sans-serif; color:var(--ink); }
.card-lite{ border:1px solid rgba(148,163,184,.18); border-radius:20px; background:#fff; box-shadow:0 12px 45px -25px rgba(15,23,42,.25); }
.section-title{ font-weight:700; font-size:1.35rem; margin-bottom:1rem; }
.btn-zs{ background:var(--zs); border:none; border-radius:12px; color:#fff; font-weight:600; padding:.55rem 1.1rem; }
.btn-zs:hover{ color:#fff; filter:brightness(.95); }
.form-control, .form-select{ border-radius:12px; border:1px solid rgba(148,163,184,.28); }
.form-control:focus, .form-select:focus{ border-color:var(--zs); box-shadow:0 0 0 .2rem rgba(14,165,181,.15); }
.preview-card{ border-radius:24px; overflow:hidden; border:1px solid rgba(148,163,184,.18); background:#fff; box-shadow:0 35px 80px -60px rgba(14,165,181,.4); }
.preview-head{ background:var(--accent); padding:28px 32px 18px 32px; }
.preview-head h2{ margin:0; font-weight:800; font-size:1.8rem; color:var(--ink); }
.preview-head p{ margin:0; color:var(--muted); }
.preview-body{ padding:28px 32px; color:#1f2937; line-height:1.6; font-size:1rem; }
.preview-footer{ padding:0 32px 32px; text-align:center; }
.preview-footer a{ display:inline-block; padding:12px 26px; border-radius:999px; background:var(--zs); color:#fff; font-weight:600; text-decoration:none; }
.preview-footer .brand{ margin-top:18px; font-size:.8rem; color:#94a3b8; letter-spacing:.12em; text-transform:uppercase; }
.invite-card{ padding:20px; }
.invite-card + .invite-card{ margin-top:18px; }
.contact-meta{ color:var(--muted); font-size:.9rem; }
.copy-input{ font-size:.85rem; background:#f8fafc; }
.card-image-box img{ width:100%; border-radius:18px; border:1px solid rgba(148,163,184,.25); box-shadow:0 22px 45px -25px rgba(15,23,42,.28); }
.card-image-box .form-text{ font-size:.85rem; }
.badge-soft{ background:rgba(14,165,181,.12); color:var(--zs); border-radius:999px; padding:.25rem .75rem; font-weight:600; }
@media (max-width:767px){
  .preview-card{ margin-top:1.5rem; }
}
</style>
</head>
<body>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
      <h4 class="mb-0"><?=h($ev['title'])?> — Davetiye Yönetimi</h4>
      <div class="text-muted small">Davetiyenizi tasarlayın, davetlileri kaydedin ve tek tuşla paylaşın.</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary" href="index.php"><i class="bi bi-speedometer2 me-1"></i>Kontrol Paneli</a>
      <a class="btn btn-outline-secondary" href="engage.php"><i class="bi bi-stars me-1"></i>Etkileşim Araçları</a>
    </div>
  </div>

  <?php flash_box(); ?>

  <div class="row g-4 mb-4">
    <div class="col-lg-7">
      <div class="card-lite p-4">
        <h2 class="section-title">Davetiyeyi Tasarla</h2>
        <p class="text-muted">Mesajınıza <strong>bikara.com</strong> ifadesi otomatik olarak eklenir. Renkleri ve buton metnini özelleştirebilirsiniz.</p>
        <form method="post" class="row g-3">
          <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="do" value="save_template">
          <div class="col-12">
            <label class="form-label">Başlık</label>
            <input class="form-control" name="title" value="<?=h($template['title'])?>" required>
          </div>
          <div class="col-12">
            <label class="form-label">Alt başlık</label>
            <input class="form-control" name="subtitle" value="<?=h($template['subtitle'] ?? '')?>" placeholder="(Opsiyonel)">
          </div>
          <div class="col-12">
            <label class="form-label">Mesaj</label>
            <textarea class="form-control" name="message" rows="6" required><?=h($template['message'])?></textarea>
            <div class="form-text">Mesajda davet detaylarını paylaşın. "bikara.com" ifadesi zorunlu ve otomatik korunur.</div>
          </div>
          <div class="col-md-4">
            <label class="form-label">Tema rengi</label>
            <input class="form-control" name="primary_color" value="<?=h($template['primary_color'])?>" placeholder="#0ea5b5">
          </div>
          <div class="col-md-4">
            <label class="form-label">Arka plan rengi</label>
            <input class="form-control" name="accent_color" value="<?=h($template['accent_color'])?>" placeholder="#f8fafc">
          </div>
          <div class="col-md-4">
            <label class="form-label">Buton metni</label>
            <input class="form-control" name="button_label" value="<?=h($template['button_label'])?>" placeholder="Davetiyeyi Görüntüle">
          </div>
          <div class="col-12 d-flex justify-content-end">
            <button class="btn btn-zs" type="submit"><i class="bi bi-brush me-1"></i>Kaydet</button>
          </div>
        </form>
      </div>
    </div>
    <div class="col-lg-5">
      <div class="preview-card">
        <div class="preview-head">
          <h2><?=h($template['title'])?></h2>
          <?php if (!empty($template['subtitle'])): ?>
            <p><?=h($template['subtitle'])?></p>
          <?php endif; ?>
        </div>
        <div class="preview-body"><?=nl2br(h($template['message']))?></div>
        <div class="preview-footer">
          <?php if (!empty($invitePreviewUrl)): ?>
            <a href="<?=h($invitePreviewUrl)?>" target="_blank" rel="noopener"><?=h($template['button_label'] ?: 'Davetiyeyi Görüntüle')?></a>
          <?php else: ?>
            <a href="#" onclick="return false;" style="pointer-events:none;opacity:.5;"><?=h($template['button_label'] ?: 'Davetiyeyi Görüntüle')?></a>
          <?php endif; ?>
          <div class="brand">bikara.com</div>
        </div>
      </div>
      <div class="card-lite p-3 mt-3 card-image-box">
        <h2 class="section-title fs-5">WhatsApp Kartı</h2>
        <p class="text-muted">Tasarladığınız davetiye otomatik olarak bir kart görseline dönüştürülür. İndirerek WhatsApp veya sosyal medya üzerinden görsel olarak paylaşabilirsiniz.</p>
        <img src="<?=h($cardPreviewUrl)?>" alt="Davetiye kartı önizlemesi">
        <div class="d-grid gap-2 mt-3">
          <a class="btn btn-zs" href="<?=h($cardShareUrl)?>&download=1"><i class="bi bi-download me-1"></i>Kartı indir</a>
          <a class="btn btn-outline-secondary" href="<?=h($cardPreviewUrl)?>" target="_blank" rel="noopener"><i class="bi bi-image me-1"></i>Kartı yeni sekmede aç</a>
        </div>
        <label class="form-label mt-3">Genel kart bağlantısı</label>
        <input class="form-control copy-input" value="<?=$cardShareUrl?>" readonly>
        <div class="form-text">Bu bağlantıyı herkese açık paylaşımlarda kullanabilirsiniz. Davetliler için kişiye özel kartlar otomatik hazırlanır.</div>
      </div>
    </div>
  </div>

  <div class="card-lite p-4 mb-4">
    <h2 class="section-title">Davetlileri Yönet</h2>
    <div class="row g-3 align-items-end">
      <form method="post" class="row g-3">
        <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="do" value="add_contact">
        <div class="col-md-3">
          <label class="form-label">İsim</label>
          <input class="form-control" name="name" placeholder="Davetli adı" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">E-posta</label>
          <input class="form-control" name="email" placeholder="ornek@eposta.com">
        </div>
        <div class="col-md-3">
          <label class="form-label">Telefon</label>
          <input class="form-control" name="phone" placeholder="05xx ...">
        </div>
        <div class="col-md-3 d-grid">
          <button class="btn btn-zs" type="submit"><i class="bi bi-person-plus me-1"></i>Davetli Ekle</button>
        </div>
      </form>
    </div>
    <p class="text-muted mt-3">Davetlilerin telefonlarını önceden kaydedin. İsterseniz şifre belirleyerek davetiyeye girişlerini kontrol altında tutabilirsiniz.</p>
  </div>

  <?php if (!$contacts): ?>
    <div class="alert alert-info">Henüz davetli eklemediniz. Yukarıdaki formu kullanarak ilk davetliyi kaydedin.</div>
  <?php else: ?>
    <div class="row g-3">
      <?php foreach ($contacts as $contact):
        $inviteUrl = public_invitation_url((string)$contact['invite_token']);
        $whatsapp = invitation_contact_whatsapp_url($contact, $template, $ev);
        $emailSet = !empty($contact['password_hash']);
        $lastSent = $contact['last_sent_at'] ? (new DateTime($contact['last_sent_at']))->format('d.m.Y H:i') : null;
        $lastView = $contact['last_viewed_at'] ? (new DateTime($contact['last_viewed_at']))->format('d.m.Y H:i') : null;
        $hasEmail = !empty($contact['email']);
        $cardUrl = public_invitation_card_url((string)$contact['invite_token']);
      ?>
      <div class="col-lg-6">
        <div class="card-lite invite-card h-100 d-flex flex-column">
          <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
            <div>
              <h5 class="mb-1"><?=h($contact['name'])?></h5>
              <div class="contact-meta"><i class="bi bi-envelope me-1"></i><?= $contact['email'] ? h($contact['email']) : 'E-posta eklenmemiş' ?></div>
              <div class="contact-meta"><i class="bi bi-telephone me-1"></i><?= $contact['phone'] ? h($contact['phone']) : 'Telefon eklenmemiş' ?></div>
              <div class="contact-meta"><i class="bi bi-link-45deg me-1"></i><a href="<?=h($inviteUrl)?>" target="_blank" rel="noopener">Bağlantıyı aç</a></div>
              <div class="contact-meta"><i class="bi bi-lock me-1"></i><?= $emailSet ? 'Şifre tanımlandı' : 'Şifre henüz belirlenmedi' ?></div>
              <div class="contact-meta"><i class="bi bi-send-check me-1"></i><?= (int)$contact['send_count'] ?> gönderim · <?= $lastSent ? 'Son: '.h($lastSent) : 'Henüz gönderilmedi' ?></div>
              <?php if ($lastView): ?>
                <div class="contact-meta"><i class="bi bi-eye me-1"></i>Son görüntüleme: <?=h($lastView)?></div>
              <?php endif; ?>
            </div>
            <div class="d-flex flex-column gap-2">
              <?php if ($hasEmail): ?>
                <form method="post">
                  <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                  <input type="hidden" name="do" value="send_email">
                  <input type="hidden" name="contact_id" value="<?=$contact['id']?>">
                  <button class="btn btn-outline-primary" type="submit"><i class="bi bi-envelope-paper me-1"></i>E-posta Gönder</button>
                </form>
              <?php else: ?>
                <button class="btn btn-outline-primary" type="button" disabled><i class="bi bi-envelope-paper me-1"></i>E-posta eksik</button>
              <?php endif; ?>
              <?php if ($whatsapp): ?>
                <a class="btn btn-outline-success" href="<?=h($whatsapp)?>" target="_blank" rel="noopener"><i class="bi bi-whatsapp me-1"></i>WhatsApp</a>
              <?php else: ?>
                <button class="btn btn-outline-success" type="button" disabled><i class="bi bi-whatsapp me-1"></i>Telefon eksik</button>
              <?php endif; ?>
              <a class="btn btn-outline-secondary" href="<?=h($cardUrl)?>&download=1"><i class="bi bi-card-image me-1"></i>Kartı İndir</a>
              <form method="post" onsubmit="return confirm('Bu davetli silinsin mi?');">
                <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                <input type="hidden" name="do" value="delete_contact">
                <input type="hidden" name="contact_id" value="<?=$contact['id']?>">
                <button class="btn btn-outline-danger" type="submit"><i class="bi bi-trash me-1"></i>Sil</button>
              </form>
            </div>
          </div>
          <div class="mt-3">
            <label class="form-label small text-muted">Bağlantı</label>
            <input class="form-control copy-input" value="<?=$inviteUrl?>" readonly>
          </div>
          <form method="post" class="mt-3">
            <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="do" value="update_contact">
            <input type="hidden" name="contact_id" value="<?=$contact['id']?>">
            <div class="row g-2">
              <div class="col-md-6">
                <label class="form-label">İsim</label>
                <input class="form-control" name="name" value="<?=h($contact['name'])?>" required>
              </div>
              <div class="col-md-6">
                <label class="form-label">E-posta</label>
                <input class="form-control" name="email" value="<?=h($contact['email'] ?? '')?>" placeholder="ornek@eposta.com">
              </div>
              <div class="col-md-6">
                <label class="form-label">Telefon</label>
                <input class="form-control" name="phone" value="<?=h($contact['phone'] ?? '')?>" placeholder="05xx ...">
              </div>
              <div class="col-md-6">
                <label class="form-label">Yeni şifre</label>
                <input class="form-control" name="new_password" placeholder="(Opsiyonel) en az 6 karakter">
              </div>
            </div>
            <div class="d-flex justify-content-end mt-3">
              <button class="btn btn-zs" type="submit"><i class="bi bi-save me-1"></i>Kaydet</button>
            </div>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
