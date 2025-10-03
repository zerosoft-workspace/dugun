<?php
// couple/_auth.php — Çift paneli ortak koruma (tek URL login modeli)
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/couple_auth.php';

install_schema();

// Global login kontrolü
if (!couple_is_global_logged_in()) {
  redirect(BASE_URL.'/couple/login.php');
}

// Aktif düğün var mı?
$EVENT_ID = couple_current_event_id();
if ($EVENT_ID <= 0) {
  // Henüz seçilmemiş → seçme sayfasına gönder
  redirect(BASE_URL.'/couple/switch_event.php');
}

// Force reset gerekiyorsa password sayfasına yönlendir
couple_require_password_reset_if_needed_for_current();

// Oturum bilgileri
$COUPLE = couple_global_user();

/** İsterseniz etkinlik satırını çekecek yardımcı */
function couple_event_row_current(): ?array {
  $eid = couple_current_event_id();
  if ($eid <= 0) return null;
  $st = pdo()->prepare("SELECT * FROM events WHERE id=? LIMIT 1");
  $st->execute([$eid]);
  return $st->fetch() ?: null;
}
// _auth.php — en üstlere yakın bir yere ekle:
if (!defined('COUPLE_ALLOW_INACTIVE')) {
  define('COUPLE_ALLOW_INACTIVE', false);
}

// ... etkinlik kontrolünde:
$requireActive = !COUPLE_ALLOW_INACTIVE; // ödeme sayfaları bunu false yapacak

// örnek kontrol:
$st = pdo()->prepare("SELECT * FROM events WHERE id=? LIMIT 1");
$st->execute([$EVENT_ID]);
$ev = $st->fetch();
if (!$ev) { http_response_code(404); exit('Etkinlik bulunamadı'); }
if ($requireActive && (int)$ev['is_active']!==1) { http_response_code(403); exit('Erişim yok veya etkinlik pasif'); }

// lisans kontrolü varsa yine $requireActive esas alın:
if ($requireActive) {
  // buraya önceki lisans aktifliği şartlarını koy
}
