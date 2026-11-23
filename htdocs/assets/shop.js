/* 台灣好物商場：前台互動（Cookie 同意、辨識碼換一張、規格切換、結帳金額與 GA4 事件、字數提示） */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  window.dataLayer = window.dataLayer || [];

  // ---------------------------------------------------------------
  // Cookie 同意（Consent Mode v2）
  // ---------------------------------------------------------------
  var cfg = window.SHOP_CONSENT;
  var panel = $('#consent-panel');
  function setCookie(a, d) {
    var v = cfg.version + '.' + (a ? 1 : 0) + '.' + (d ? 1 : 0) + '.' + Math.floor(Date.now() / 1000);
    var secure = location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = 'shop_consent=' + v + '; Max-Age=' + (180 * 86400) + '; Path=' + (cfg.path || '/') + '; SameSite=Lax' + secure;
  }
  function activate(state) {
    // 取得同意後，把等待同意的 <script type="text/plain" data-consent> 轉為可執行
    $$('script[type="text/plain"][data-consent]').forEach(function (old) {
      var need = old.getAttribute('data-consent');
      var ok = need === 'any' ? (state.analytics || state.ads) : !!state[need];
      if (!ok) { return; }
      var s = document.createElement('script');
      Array.prototype.slice.call(old.attributes).forEach(function (at) {
        if (at.name !== 'type' && at.name !== 'data-consent') { s.setAttribute(at.name, at.value); }
      });
      s.text = old.text;
      old.parentNode.replaceChild(s, old);
    });
  }
  function apply(a, d) {
    setCookie(a, d);
    var g = function (x) { return x ? 'granted' : 'denied'; };
    if (typeof window.gtag === 'function') {
      window.gtag('consent', 'update', { analytics_storage: g(a), ad_storage: g(d), ad_user_data: g(d), ad_personalization: g(d) });
    }
    window.dataLayer.push({ event: 'consent_update', analytics_storage: g(a), ad_storage: g(d) });
    cfg.state = { analytics: a, ads: d };
    activate(cfg.state);
    hide();
  }
  function show() {
    if (!panel) { return; }
    var st = cfg.state || { analytics: false, ads: false };
    $('#consent-analytics').checked = !!st.analytics;
    $('#consent-ads').checked = !!st.ads;
    panel.hidden = false;
    var first = $('#consent-analytics');
    if (first) { first.focus(); }
  }
  function hide() { if (panel) { panel.hidden = true; } }
  if (cfg && panel) {
    $$('[data-consent-open]').forEach(function (b) { b.addEventListener('click', show); });
    $$('[data-consent]', panel).forEach(function (b) {
      b.addEventListener('click', function () {
        var k = b.getAttribute('data-consent');
        if (k === 'necessary') { apply(false, false); }
        else if (k === 'all') { apply(true, true); }
        else if (k === 'save') { apply($('#consent-analytics').checked, $('#consent-ads').checked); }
        else { hide(); }  // 收合不代表同意
      });
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hidden) { hide(); } });
    document.addEventListener('click', function (e) {
      if (!panel.hidden && !panel.contains(e.target) && !e.target.closest('[data-consent-open]')) { hide(); }
    });
    if (cfg.state) { activate(cfg.state); }
    if (cfg.autoShow) { setTimeout(show, 300); }
  } else if (cfg && cfg.state) {
    activate(cfg.state);
  }

  // ---------------------------------------------------------------
  // 辨識碼「換一張」
  // ---------------------------------------------------------------
  $$('[data-captcha-refresh]').forEach(function (b) {
    b.addEventListener('click', function () {
      var img = b.parentNode.querySelector('[data-captcha-img]');
      if (img) { img.src = img.src.replace(/([?&]r=)[^&]*/, '$1' + Math.random().toString(36).slice(2)); }
      var input = b.closest('.captcha').querySelector('input');
      if (input) { input.value = ''; input.focus(); }
    });
  });

  // ---------------------------------------------------------------
  // 字數提示
  // ---------------------------------------------------------------
  $$('[data-count]').forEach(function (el) {
    var max = parseInt(el.getAttribute('maxlength'), 10);
    var tip = document.createElement('span');
    tip.className = 'muted small count';
    el.insertAdjacentElement('afterend', tip);
    var upd = function () { tip.textContent = Array.from(el.value).length + (max ? ' / ' + max : '') + ' 字'; };
    el.addEventListener('input', upd);
    upd();
  });

  // ---------------------------------------------------------------
  // 商品頁：縮圖與規格切換
  // ---------------------------------------------------------------
  var main = $('#main-image');
  $$('.thumb-btn').forEach(function (b) {
    b.addEventListener('click', function () { if (main) { main.src = b.getAttribute('data-large'); } });
  });
  var vdataEl = $('#variant-data');
  var sel = $('#variant-select');
  if (vdataEl && sel) {
    var vd = JSON.parse(vdataEl.textContent);
    var upd = function () {
      var v = vd.variants[sel.value];
      if (!v) { return; }
      $('#v-price').textContent = v.price;
      $('#v-sku').textContent = v.sku;
      $('#v-stock').textContent = v.stock > 0 ? v.stock + ' 件' : '缺貨';
      var qty = $('#v-qty');
      var ok = vd.active && v.active && v.available > 0;
      qty.max = Math.max(1, v.available);
      qty.disabled = !ok;
      $('#v-add').disabled = !ok;
      $('#v-note').textContent = !v.active ? '此規格已停售。' : (v.stock <= 0 ? '目前缺貨，暫時無法加入購物車。'
        : (v.available <= 0 ? '您的購物車已達此規格的庫存上限（購物車內 ' + v.in_cart + ' 件）。'
          : '可再加入 ' + v.available + ' 件（目前庫存 ' + v.stock + ' 件，購物車內已有 ' + v.in_cart + ' 件）。'));
      if (v.image && main) { main.src = v.image; }
      try { history.replaceState(null, '', location.pathname + location.search.replace(/([?&])variant=\d+&?/, '$1').replace(/[?&]$/, '') + (location.search.indexOf('?') >= 0 ? '&' : '?') + 'variant=' + sel.value); } catch (e) {}
    };
    sel.addEventListener('change', upd);
  }

  // ---------------------------------------------------------------
  // 結帳頁：配送與付款切換、金額、門市篩選、GA4 add_shipping_info／add_payment_info
  // ---------------------------------------------------------------
  var cdEl = $('#checkout-data');
  var form = $('#checkout-form');
  if (cdEl && form) {
    var cd = JSON.parse(cdEl.textContent);
    var ship = function () { var r = $('input[name="shipping_method"]:checked', form); return r ? r.value : ''; };
    var pay = function () { var r = $('input[name="payment_method"]:checked', form); return r ? r.value : ''; };
    var push = function (event, extra) {
      if (!cd.ga) { return; }
      var q = cd.quotes[ship() + '|' + pay()] || {};
      var ec = { currency: 'TWD', value: q.total_raw ? parseFloat(q.total_raw) : 0, items: cd.items };
      for (var k in extra) { ec[k] = extra[k]; }
      window.dataLayer.push({ ecommerce: null });
      window.dataLayer.push({ event: event, ecommerce: ec });
    };
    var refresh = function () {
      var s = ship();
      $$('[data-ship-panel]', form).forEach(function (p) {
        var on = p.getAttribute('data-ship-panel') === s;
        p.hidden = !on;
        $$('input[data-pay]', p).forEach(function (i) { i.disabled = !on; });
      });
      if (!pay()) { var first = $('[data-ship-panel="' + s + '"] input[data-pay]', form); if (first) { first.checked = true; } }
      var q = cd.quotes[s + '|' + pay()];
      if (q) {
        $('#q-subtotal').textContent = q.subtotal;
        $('#q-shipping').textContent = q.shipping;
        $('#q-cod').textContent = q.cod;
        $('#q-total').textContent = q.total;
        $('#expected-total').value = q.total_raw;
      }
    };
    $$('input[data-ship]', form).forEach(function (r) {
      r.addEventListener('change', function () {
        $$('input[data-pay]', form).forEach(function (i) { i.checked = false; });
        refresh();
        push('add_shipping_info', { shipping_tier: cd.shipping_labels[ship()] });
      });
    });
    $$('input[data-pay]', form).forEach(function (r) {
      r.addEventListener('change', function () { refresh(); push('add_payment_info', { payment_type: cd.payment_labels[pay()] }); });
    });
    var chain = $('#cvs-chain');
    var filter = $('#cvs-filter');
    var store = $('#cvs-store');
    var filterStores = function () {
      if (!store) { return; }
      var c = chain ? chain.value : '';
      var t = filter ? filter.value.trim() : '';
      $$('option[data-chain]', store).forEach(function (o) {
        o.hidden = (c && o.getAttribute('data-chain') !== c) || (t && o.getAttribute('data-text').indexOf(t) < 0);
      });
    };
    if (chain) { chain.addEventListener('change', filterStores); }
    if (filter) { filter.addEventListener('input', filterStores); }
    refresh();
  }
})();
