<?php
// includes/license.php — Çift panel lisans süresi yönetimi (korumalı)
// NOT: Bu dosya lisans fonksiyonlarının KANONİK kaynağı olmalı.
// Eğer aynı fonksiyonları includes/functions.php içine de koyduysanız,
// ya oradan silin (önerilen) ya da bu dosyayı function_exists kontrolleri ile kullanın.

require_once __DIR__.'/../config.php';
require_once __DIR__.'/db.php';
require_once __DIR__.'/functions.php';

if (!function_exists('license_ensure_columns')) {
  function license_ensure_columns(): void {
    $pdo = pdo();
    $has_years = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='events' AND COLUMN_NAME='license_years'")->fetchColumn();
    if (!$has_years) {
      try { $pdo->exec("ALTER TABLE events ADD COLUMN license_years INT NOT NULL DEFAULT 1"); } catch(Throwable $e){}
    }
    $has_until = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='events' AND COLUMN_NAME='license_until'")->fetchColumn();
    if (!$has_until) {
      try { $pdo->exec("ALTER TABLE events ADD COLUMN license_until DATETIME NULL"); } catch(Throwable $e){}
    }
  }
}

if (!function_exists('license_ensure_active')) {
  /**
   * “İlk açıldığı gün 365 gün lisanslı” davranışı.
   * license_until boşsa created_at + license_years (varsayılan 1 yıl) yazılır.
   */
  function license_ensure_active(int $event_id): void {
    license_ensure_columns();

    $st = pdo()->prepare("SELECT license_years, license_until, created_at FROM events WHERE id=? LIMIT 1");
    $st->execute([$event_id]);
    $ev = $st->fetch();
    if (!$ev) return;

    $years = (int)($ev['license_years'] ?? 0);
    $until = $ev['license_until'] ?? null;
    if ($years <= 0) $years = 1;

    if (empty($until)) {
      $created = $ev['created_at'] ?: now();
      $dt = new DateTime($created);
      $dt->modify('+' . $years . ' year');
      $until = $dt->format('Y-m-d H:i:s');

      $up = pdo()->prepare("UPDATE events SET license_years=?, license_until=? WHERE id=?");
      $up->execute([$years, $until, $event_id]);
    }
  }
}

if (!function_exists('license_is_active')) {
  /** Lisans aktif mi? (bugün <= license_until) */
  function license_is_active(int $event_id): bool {
    license_ensure_columns();
    $st = pdo()->prepare("SELECT license_until FROM events WHERE id=? LIMIT 1");
    $st->execute([$event_id]);
    $until = $st->fetchColumn();
    if (!$until) return false;
    return (new DateTime() <= new DateTime($until));
  }
}

if (!function_exists('license_remaining_days')) {
  /** Kalan gün (negatifse geçmiş) */
  function license_remaining_days(int $event_id): int {
    $st = pdo()->prepare("SELECT license_until FROM events WHERE id=? LIMIT 1");
    $st->execute([$event_id]);
    $until = $st->fetchColumn();
    if (!$until) return -9999;
    $now = new DateTime();
    $u   = new DateTime($until);
    $diff = (int)$now->diff($u)->format('%r%a');
    return $diff;
  }
}

if (!function_exists('license_set_years')) {
  /**
   * Lisansı X yıl olarak AYARLA (bugünden itibaren X yıl).
   * Not: Ödeme onayı sonrasında kullanın.
   */
  function license_set_years(int $event_id, int $years): bool {
    if ($years < 1) $years = 1;
    $dt = new DateTime(); $dt->modify("+{$years} year");
    $until = $dt->format('Y-m-d H:i:s');
    $st = pdo()->prepare("UPDATE events SET license_years=?, license_until=? WHERE id=?");
    return $st->execute([$years, $until, $event_id]);
  }
}

if (!function_exists('license_extend_years')) {
  /**
   * Lisansı X yıl UZAT (mevcut license_until tarihine ekler).
   * Not: Ödeme onayı sonrasında kullanın.
   */
  function license_extend_years(int $event_id, int $years): bool {
    if ($years < 1) $years = 1;
    $st = pdo()->prepare("SELECT license_until, license_years FROM events WHERE id=?");
    $st->execute([$event_id]);
    $row = $st->fetch();
    if (!$row) return false;

    $base = $row['license_until'] ?: now();
    $dt = new DateTime($base);
    $dt->modify("+{$years} year");
    $until = $dt->format('Y-m-d H:i:s');

    $newYears = max(1, (int)$row['license_years']) + $years;
    $up = pdo()->prepare("UPDATE events SET license_years=?, license_until=? WHERE id=?");
    return $up->execute([$newYears, $until, $event_id]);
  }
}

if (!function_exists('license_badge_text')) {
  /** “Kalan: 4 yıl 120 gün” gibi okunabilir rozet metni */
  function license_badge_text(int $event_id): string {
    $st = pdo()->prepare("SELECT license_until FROM events WHERE id=?");
    $st->execute([$event_id]);
    $until = $st->fetchColumn();
    if (!$until) return 'Lisans: ayarlı değil';

    $now = new DateTime();
    $u   = new DateTime($until);
    if ($now > $u) return 'Lisans: süresi doldu';

    $diff = $now->diff($u);
    $years = (int)$diff->y;
    $days  = (int)$diff->days - $years * 365;
    if ($years > 0) {
      return "Kalan: {$years} yıl " . ($days > 0 ? "{$days} gün" : '');
    }
    return "Kalan: {$diff->days} gün";
  }
}
