<?php
/* ===================== CONFIG ====================== */
const DB_HOST   = 'srv1776.hstgr.io';
const DB_NAME   = 'u111878875_foto';
const DB_USER   = 'u111878875_foto';
const DB_PASS   = 'Zero671901*';
const BASE_URL  = 'https://drive.demozerosoft.com.tr'; // kök URL (sonda / yok)
const APP_NAME  = 'Wedding Share';
const SECRET_KEY = 'CHANGE_THIS_LONG_RANDOM_SECRET_32+CHARS';

const MAX_UPLOAD_BYTES = 25 * 1024 * 1024; // 25 MB
const ALLOWED_MIMES = [
  'image/jpeg' => 'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif',
  'video/mp4'=>'mp4','video/quicktime'=>'mov','video/webm'=>'webm',
  
];
const MAIL_FROM = 'test@zerosoft.com.tr';
const MAIL_FROM_NAME = 'Wedding Share';
const SMTP_HOST = 'smtp.hostinger.com';
const SMTP_PORT = 465;
const SMTP_USER = 'test@zerosoft.com.tr';
const SMTP_PASS = 'Zero671901*';
const SMTP_SECURE = 'SSL';
/* ================= PAYTR ================== */
const PAYTR_MERCHANT_ID  = '615552';
const PAYTR_MERCHANT_KEY = 'TnzafFzaQwrpF23s';
const PAYTR_MERCHANT_SALT= 'eNaS2ot4C8naRR47';
const PAYTR_OK_URL       = BASE_URL.'/couple/paytr_ok.php';
const PAYTR_FAIL_URL     = BASE_URL.'/couple/paytr_fail.php';
const PAYTR_CALLBACK_URL = BASE_URL.'/couple/paytr_callback.php';
const PAYTR_SITE_OK_URL  = BASE_URL.'/order_paytr_ok.php';
const PAYTR_SITE_FAIL_URL= BASE_URL.'/order_paytr_fail.php';
const PAYTR_DEALER_OK_URL   = BASE_URL.'/dealer/paytr_ok.php';
const PAYTR_DEALER_FAIL_URL = BASE_URL.'/dealer/paytr_fail.php';
const PAYTR_TEST_MODE    = 1; // 1=test, 0=canlı
/* ========================================= */

/* =================================================== */

date_default_timezone_set('Europe/Istanbul');
session_start();
