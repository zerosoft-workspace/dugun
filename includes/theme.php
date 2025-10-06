<?php
if (!function_exists('theme_head_assets')) {
  function theme_head_assets(): string {
    static $emitted = false;
    $style = <<<'STYLE'
<style>
  :root {
    color-scheme: light;
    --ink:#0f172a;
    --muted:#64748b;
    --surface:#ffffff;
    --surface-alt:#f8fafc;
    --outline:#e2e8f0;
    --shadow:0 18px 40px -28px rgba(15,23,42,.2);
    --brand:#0ea5b5;
    --brand-dark:#0b8b98;
    --admin-bg:#eef2f9;
    --admin-surface:#ffffff;
    --admin-ink:#0f172a;
    --admin-muted:#64748b;
    --admin-sidebar:#0ea5b5;
    --admin-sidebar-accent:#0b8b98;
    --rep-bg:#eef2f9;
    --rep-surface:#ffffff;
    --rep-ink:#0f172a;
    --rep-muted:#64748b;
    --rep-sidebar:#0ea5b5;
    --rep-sidebar-accent:#0b8b98;
    --dealer-bg:#eef2f9;
    --dealer-surface:#ffffff;
    --dealer-ink:#0f172a;
    --dealer-muted:#64748b;
    --dealer-sidebar:#0ea5b5;
    --dealer-sidebar-accent:#0b8b98;
  }
  :root[data-theme="dark"] {
    color-scheme: dark;
    --ink:#e2e8f0;
    --muted:#94a3b8;
    --surface:#0f172a;
    --surface-alt:#101a33;
    --outline:rgba(148,163,184,.35);
    --shadow:0 30px 70px -48px rgba(8,47,73,.6);
    --brand:#38bdf8;
    --brand-dark:#0ea5b5;
    --admin-bg:#020617;
    --admin-surface:#0f172a;
    --admin-ink:#e2e8f0;
    --admin-muted:#94a3b8;
    --admin-sidebar:#0c4a6e;
    --admin-sidebar-accent:#075985;
    --rep-bg:#020617;
    --rep-surface:#0f172a;
    --rep-ink:#e2e8f0;
    --rep-muted:#94a3b8;
    --rep-sidebar:#0c4a6e;
    --rep-sidebar-accent:#0ea5b5;
    --dealer-bg:#020617;
    --dealer-surface:#0f172a;
    --dealer-ink:#e2e8f0;
    --dealer-muted:#94a3b8;
    --dealer-sidebar:#0c4a6e;
    --dealer-sidebar-accent:#075985;
  }
  body,
  .card,
  .panel-card,
  .description-card,
  .filter-card,
  .listing-card,
  .hero,
  .hero-card,
  .finance-card,
  .dealer-hero-card,
  .admin-hero-card,
  .rep-hero-card,
  .rep-container,
  .rep-sidebar,
  .admin-sidebar,
  .dealer-sidebar,
  .rep-app,
  .dealer-app,
  .admin-app,
  .modal-content,
  .offcanvas,
  .dropdown-menu,
  .list-group-item {
    transition:background-color .3s ease,color .3s ease,border-color .3s ease,box-shadow .3s ease;
  }
  body {
    background:var(--surface-alt);
    color:var(--ink);
  }
  [data-theme="dark"] body {
    background:#020617;
    color:var(--ink);
  }
  [data-theme="dark"] .hero,
  [data-theme="dark"] .hero-card {
    background:linear-gradient(135deg,rgba(14,165,181,.32),rgba(15,23,42,.92));
    color:#e2e8f0;
  }
  [data-theme="dark"] .card,
  [data-theme="dark"] .modal-content,
  [data-theme="dark"] .dropdown-menu,
  [data-theme="dark"] .offcanvas,
  [data-theme="dark"] .filter-card,
  [data-theme="dark"] .listing-card,
  [data-theme="dark"] .panel-card,
  [data-theme="dark"] .description-card,
  [data-theme="dark"] .finance-card,
  [data-theme="dark"] .dealer-hero-card,
  [data-theme="dark"] .admin-hero-card,
  [data-theme="dark"] .rep-container,
  [data-theme="dark"] .rep-topbar,
  [data-theme="dark"] .rep-sidebar,
  [data-theme="dark"] .dealer-sidebar,
  [data-theme="dark"] .admin-sidebar,
  [data-theme="dark"] .list-group-item {
    background:var(--surface);
    color:var(--ink);
    border-color:var(--outline) !important;
    box-shadow:var(--shadow);
  }
  [data-theme="dark"] .listing-media.no-image {
    background:linear-gradient(135deg,rgba(59,130,246,.2),rgba(14,165,181,.24));
    color:#38bdf8;
  }
  [data-theme="dark"] .listing-title,
  [data-theme="dark"] .panel-title,
  [data-theme="dark"] .hero-title,
  [data-theme="dark"] h1,
  [data-theme="dark"] h2,
  [data-theme="dark"] h3,
  [data-theme="dark"] h4,
  [data-theme="dark"] h5 {
    color:#f8fafc;
  }
  [data-theme="dark"] .listing-summary,
  [data-theme="dark"] .listing-meta span,
  [data-theme="dark"] .hero-summary,
  [data-theme="dark"] .contact-chip,
  [data-theme="dark"] .info-list,
  [data-theme="dark"] .description-card p,
  [data-theme="dark"] .meta,
  [data-theme="dark"] .text-muted,
  [data-theme="dark"] .dealer-meta,
  [data-theme="dark"] .dealer-meta strong,
  [data-theme="dark"] .rep-sidebar-meta,
  [data-theme="dark"] .rep-topbar-info p,
  [data-theme="dark"] .rep-dealer-pill,
  [data-theme="dark"] .table td,
  [data-theme="dark"] .table th {
    color:#cbd5f5 !important;
  }
  [data-theme="dark"] .contact-chip {
    background:rgba(14,165,181,.18);
  }
  [data-theme="dark"] .contact-chip:hover {
    background:rgba(14,165,181,.28);
    color:#f8fafc;
  }
  [data-theme="dark"] .package-item,
  [data-theme="dark"] .packages-table tbody td,
  [data-theme="dark"] .packages-table thead th {
    background:rgba(15,23,42,.9);
    color:#e2e8f0;
  }
  [data-theme="dark"] .packages-table tbody td {
    border-top:1px solid rgba(148,163,184,.35);
  }
  [data-theme="dark"] .filter-reset,
  [data-theme="dark"] a,
  [data-theme="dark"] .breadcrumb-link,
  [data-theme="dark"] .detail-link {
    color:#38bdf8;
  }
  [data-theme="dark"] .detail-link {
    border-color:rgba(56,189,248,.45);
  }
  [data-theme="dark"] .detail-link:hover {
    background:rgba(56,189,248,.15);
  }
  [data-theme="dark"] .btn-primary,
  [data-theme="dark"] .btn-brand {
    background:linear-gradient(135deg,#22d3ee,#0ea5b5);
    border:none;
    color:#04121f;
  }
  [data-theme="dark"] .btn-outline-primary,
  [data-theme="dark"] .btn-outline-secondary,
  [data-theme="dark"] .btn-outline-danger {
    color:#cbd5f5;
    border-color:rgba(148,163,184,.5);
  }
  [data-theme="dark"] .btn-outline-primary:hover,
  [data-theme="dark"] .btn-outline-secondary:hover,
  [data-theme="dark"] .btn-outline-danger:hover {
    background:rgba(148,163,184,.15);
  }
  [data-theme="dark"] .table {
    color:#e2e8f0;
  }
  [data-theme="dark"] .table thead th {
    color:#94a3b8;
    background:rgba(14,165,181,.15);
  }
  [data-theme="dark"] .table tbody tr {
    --bs-table-bg:rgba(15,23,42,.9);
  }
  [data-theme="dark"] .table-striped>tbody>tr:nth-of-type(odd) {
    --bs-table-bg:rgba(15,23,42,.82);
  }
  [data-theme="dark"] .form-control,
  [data-theme="dark"] .form-select,
  [data-theme="dark"] .form-control:focus,
  [data-theme="dark"] .form-select:focus,
  [data-theme="dark"] .input-group-text,
  [data-theme="dark"] .form-check-input,
  [data-theme="dark"] textarea {
    background:rgba(15,23,42,.86);
    color:var(--ink);
    border-color:var(--outline);
  }
  [data-theme="dark"] .form-control::placeholder,
  [data-theme="dark"] .form-select option,
  [data-theme="dark"] textarea::placeholder {
    color:var(--muted);
  }
  [data-theme="dark"] .form-check-input:checked {
    background-color:#0ea5b5;
    border-color:#0ea5b5;
  }
  [data-theme="dark"] .finance-summary .card {
    background:linear-gradient(135deg,rgba(56,189,248,.22),rgba(15,23,42,.94));
    box-shadow:0 30px 70px -48px rgba(8,47,73,.7);
  }
  [data-theme="dark"] .finance-card .card-header {
    background:rgba(15,23,42,.72);
    border-bottom:1px solid var(--outline);
  }
  [data-theme="dark"] .finance-card table tr+tr {
    border-top:1px solid rgba(148,163,184,.25);
  }
  .theme-toggle {
    border:none;
    border-radius:999px;
    width:46px;
    height:46px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    background:rgba(14,165,181,.16);
    color:#0f172a;
    font-size:1.3rem;
    box-shadow:0 18px 36px -20px rgba(15,23,42,.45);
    cursor:pointer;
    transition:transform .2s ease, box-shadow .2s ease, background .2s ease;
  }
  .theme-toggle:hover {
    transform:translateY(-2px);
    box-shadow:0 24px 46px -26px rgba(15,23,42,.55);
  }
  body.theme-toggle-floating .theme-toggle {
    position:fixed;
    bottom:24px;
    right:24px;
    z-index:1080;
  }
  .theme-toggle-inline {
    display:flex;
    align-items:center;
  }
  .theme-toggle-inline .theme-toggle {
    position:static;
    margin:0 6px;
  }
  [data-theme="dark"] .theme-toggle {
    background:rgba(148,163,184,.32);
    color:#f8fafc;
  }
  .theme-toggle:focus-visible {
    outline:2px solid rgba(14,165,181,.6);
    outline-offset:2px;
  }
  .theme-toggle-label {
    display:none;
  }
  .word-safe {
    word-break:break-word;
    overflow-wrap:anywhere;
  }
</style>
STYLE;
    if ($emitted) {
      return '';
    }
    $emitted = true;
    $script = <<<'SCRIPT'
<script>
(function(){
  var STORAGE_KEY='bikare:theme';
  var doc=document.documentElement;
  var prefersDark=false;
  if (window.matchMedia){
    try { prefersDark=window.matchMedia('(prefers-color-scheme: dark)').matches; } catch(e) {}
  }
  function safeGet(){
    try { return localStorage.getItem(STORAGE_KEY); } catch(e) { return null; }
  }
  function safeSet(value){
    try { localStorage.setItem(STORAGE_KEY,value); } catch(e) {}
  }
  var stored=safeGet();
  var hasStored=stored==='dark'||stored==='light';
  var initial=hasStored?stored:(prefersDark?'dark':'light');
  function updateButton(btn,theme){
    if(!btn){return;}
    btn.dataset.mode=theme;
    var label=theme==='dark'?'Aydınlık mod':'Karanlık mod';
    btn.setAttribute('aria-label',label);
    btn.setAttribute('title',label);
    btn.textContent=theme==='dark'?'☀️':'🌙';
  }
  function applyTheme(theme,persist){
    doc.setAttribute('data-theme',theme);
    if(persist){
      hasStored=true;
      safeSet(theme);
    }
    updateButton(toggleBtn,theme);
  }
  doc.setAttribute('data-theme',initial);
  var toggleBtn=null;
  function mount(){
    if(toggleBtn){return;}
    toggleBtn=document.createElement('button');
    toggleBtn.type='button';
    toggleBtn.className='theme-toggle';
    toggleBtn.addEventListener('click',function(){
      var current=doc.getAttribute('data-theme')==='dark'?'dark':'light';
      var next=current==='dark'?'light':'dark';
      applyTheme(next,true);
    });
    updateButton(toggleBtn,initial);
    var anchor=document.querySelector('[data-theme-toggle-anchor]');
    if(anchor){
      anchor.classList.add('theme-toggle-inline');
      anchor.appendChild(toggleBtn);
    } else {
      document.body.appendChild(toggleBtn);
      document.body.classList.add('theme-toggle-floating');
    }
  }
  if(document.readyState==='loading'){
    document.addEventListener('DOMContentLoaded',mount);
  } else {
    mount();
  }
  if(window.matchMedia){
    try {
      var mq=window.matchMedia('(prefers-color-scheme: dark)');
      var listener=function(ev){
        if(hasStored){return;}
        applyTheme(ev.matches?'dark':'light',false);
      };
      if(mq.addEventListener){
        mq.addEventListener('change',listener);
      } else if(mq.addListener){
        mq.addListener(listener);
      }
    } catch(e){}
  }
})();
</script>
SCRIPT;
    return $style.$script;
  }
}
