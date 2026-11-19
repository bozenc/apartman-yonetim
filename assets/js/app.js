/* Apartman Yönetim Sistemi - ortak betikler */

/* Çerez tercihi: "tumu" ise tema kalıcı (localStorage), değilse yalnızca bu sekmede (sessionStorage) saklanır */
function cerezTercih() {
    const m = document.cookie.match(/(?:^|; )cerez_tercih=([^;]*)/);
    return m ? decodeURIComponent(m[1]) : '';
}
function temaKaydet(t) {
    try {
        if (cerezTercih() === 'tumu') localStorage.setItem('tema', t);
        else { localStorage.removeItem('tema'); sessionStorage.setItem('tema', t); }
    } catch (e) {}
}
(function () {
    const bant = document.getElementById('cerezBandi');
    document.addEventListener('click', function (ev) {
        const b = ev.target.closest('[data-cerez]');
        if (b) {
            const t = b.dataset.cerez === 'tumu' ? 'tumu' : 'zorunlu';
            document.cookie = 'cerez_tercih=' + t + '; max-age=31536000; path=' + (window.CEREZ_YOL || '/') + '; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
            const mevcut = document.documentElement.getAttribute('data-bs-theme') || 'light';
            try {
                if (t === 'tumu') { localStorage.setItem('tema', mevcut); }
                else { localStorage.removeItem('tema'); sessionStorage.setItem('tema', mevcut); }
            } catch (e) {}
            if (bant) bant.classList.add('d-none');
            return;
        }
        if (ev.target.closest('[data-cerez-ayar]')) {
            ev.preventDefault();
            if (bant) bant.classList.remove('d-none');
        }
    });
})();

(function () {
    // Mobil menü
    const sb = document.getElementById('sidebar');
    const bd = document.getElementById('sidebarBackdrop');
    const tg = document.getElementById('sidebarToggle');
    if (tg && sb) {
        const kapat = () => { sb.classList.remove('open'); bd.classList.remove('show'); };
        tg.addEventListener('click', () => { sb.classList.toggle('open'); bd.classList.toggle('show'); });
        bd.addEventListener('click', kapat);
    }

    // Tema (açık / koyu)
    const tt = document.getElementById('themeToggle');
    const ikon = () => {
        if (!tt) return;
        const koyu = document.documentElement.getAttribute('data-bs-theme') === 'dark';
        tt.innerHTML = koyu ? '<i class="bi bi-sun"></i>' : '<i class="bi bi-moon-stars"></i>';
    };
    ikon();
    if (tt) tt.addEventListener('click', () => {
        const koyu = document.documentElement.getAttribute('data-bs-theme') === 'dark';
        document.documentElement.setAttribute('data-bs-theme', koyu ? 'light' : 'dark');
        temaKaydet(koyu ? 'light' : 'dark');
        ikon();
        document.dispatchEvent(new Event('temaDegisti'));
    });

    // Silme onayı: <form data-confirm="Mesaj"> veya <button data-confirm>
    const modalEl = document.getElementById('confirmModal');
    let bekleyenForm = null;
    if (modalEl) {
        const modal = new bootstrap.Modal(modalEl);
        document.addEventListener('submit', function (ev) {
            const f = ev.target;
            if (f.dataset.confirm !== undefined && !f.dataset.onaylandi) {
                ev.preventDefault();
                bekleyenForm = f;
                document.getElementById('confirmText').textContent = f.dataset.confirm || 'Bu işlem geri alınamaz.';
                modal.show();
            }
        });
        document.getElementById('confirmOk').addEventListener('click', function () {
            if (bekleyenForm) { bekleyenForm.dataset.onaylandi = '1'; bekleyenForm.submit(); }
            modal.hide();
        });
    }

    // Düzenleme modalı doldurma: <button data-edit='{"id":1,...}' data-bs-target="#modal">
    document.addEventListener('click', function (ev) {
        const b = ev.target.closest('[data-edit]');
        if (!b) return;
        const hedef = document.querySelector(b.dataset.bsTarget || b.getAttribute('href'));
        if (!hedef) return;
        const veri = JSON.parse(b.dataset.edit || '{}');
        const form = hedef.querySelector('form');
        // reset() onay kutularını varsayılanına döndürür; yalnızca veride olan alanlar aşağıda ayarlanır
        form.reset();
        Object.keys(veri).forEach(k => {
            const alanlar = form.querySelectorAll('[name="' + k + '"], [name="' + k + '[]"]');
            alanlar.forEach(el => {
                if (el.type === 'checkbox') {
                    const v = veri[k];
                    el.checked = Array.isArray(v) ? v.map(String).includes(el.value) : (String(v) === '1' || v === true);
                } else el.value = veri[k] ?? '';
            });
        });
        const baslik = hedef.querySelector('.modal-title');
        if (baslik && b.dataset.title) baslik.textContent = b.dataset.title;
    });

    // "Yeni" butonları formu temizler
    document.addEventListener('click', function (ev) {
        const b = ev.target.closest('[data-new]');
        if (!b) return;
        const hedef = document.querySelector(b.dataset.bsTarget);
        if (!hedef) return;
        const form = hedef.querySelector('form');
        form.reset();
        const id = form.querySelector('[name=id]');
        if (id) id.value = '';
        const baslik = hedef.querySelector('.modal-title');
        if (baslik && b.dataset.title) baslik.textContent = b.dataset.title;
    });

    // Otomatik kaybolan uyarılar
    setTimeout(() => document.querySelectorAll('.alert-success').forEach(a => {
        try { bootstrap.Alert.getOrCreateInstance(a).close(); } catch (e) {}
    }), 5000);
})();

/* Grafik renkleri temaya göre */
function grafikRenk() {
    const koyu = document.documentElement.getAttribute('data-bs-theme') === 'dark';
    return { metin: koyu ? '#8b9ab3' : '#64748b', izgara: koyu ? '#1f2a40' : '#eef1f6' };
}
function paraFmt(v) {
    return new Intl.NumberFormat('tr-TR', { style: 'currency', currency: 'TRY', maximumFractionDigits: 0 }).format(v);
}
