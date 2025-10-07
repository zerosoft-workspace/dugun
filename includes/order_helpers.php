<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/db.php';
require_once __DIR__.'/functions.php';

function site_order_recalculate_totals(int $orderId, ?int $addonsTotal = null, ?int $campaignsTotal = null): void {
  $pdo = pdo();
  $st = $pdo->prepare('SELECT base_price_cents FROM site_orders WHERE id=? LIMIT 1');
  $st->execute([$orderId]);
  $row = $st->fetch();
  if (!$row) {
    return;
  }

  if ($addonsTotal === null) {
    $st = $pdo->prepare('SELECT COALESCE(SUM(total_cents),0) FROM site_order_addons WHERE order_id=?');
    $st->execute([$orderId]);
    $addonsTotal = (int)$st->fetchColumn();
  }

  if ($campaignsTotal === null) {
    try {
      $st = $pdo->prepare('SELECT COALESCE(SUM(total_cents),0) FROM site_order_campaigns WHERE order_id=?');
      $st->execute([$orderId]);
      $campaignsTotal = (int)$st->fetchColumn();
    } catch (Throwable $e) {
      $campaignsTotal = 0;
    }
  }

  $addonsTotal = max(0, (int)$addonsTotal);
  $campaignsTotal = max(0, (int)$campaignsTotal);
  $basePrice = max(0, (int)($row['base_price_cents'] ?? 0));
  $price = $basePrice + $addonsTotal + $campaignsTotal;

  $sql = 'UPDATE site_orders SET addons_total_cents=?, campaigns_total_cents=?, price_cents=?, updated_at=? WHERE id=?';
  $pdo->prepare($sql)->execute([
    $addonsTotal,
    $campaignsTotal,
    $price,
    now(),
    $orderId,
  ]);
}
