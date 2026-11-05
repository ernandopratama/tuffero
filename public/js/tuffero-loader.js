/**
 * TUFFERO LOGO LIGHT-DRAW LOADER
 * File: js/tuffero-loader.js
 *
 * Dipindahkan dari proyek CoomingSoon-Tuffero dan disesuaikan untuk semua halaman toko.
 * Ditampilkan SATU KALI per hari (kunci localStorage "tuffero-loader-day", tanggal lokal); sisanya memakai
 * loader "LOADING" di welcome.blade.php. Skrip kecil di <head> menentukan keduanya dan memasang kelas
 * html.tf-first bila hari ini belum pernah. Animasi logo murni CSS (css/tuffero-loader.css). Script ini:
 * - membuat loader dari <template id="tuffero-loader-tpl"> (foto logo tidak diunduh bila tidak dipakai),
 * - menambah percikan cahaya,
 * - menunggu event "tuffero:app-ready" (skrip template sudah dimuat) dan foto hero,
 * - menahan loader 4 detik, lalu mencatat hari ini sebagai sudah tampil,
 * - memicu event "tuffero:loaded" dan menyelesaikan window.tufferoLoaded agar legacy.js memulai
 *   function.js (animasi halaman) dan video hero baru diputar sesudahnya.
 */

(function () {
    "use strict";

    const html = document.documentElement;

    // Bukan kunjungan pertama hari ini: loader "LOADING" (skrip di <head>) yang bekerja, bukan yang ini.
    if (!html.classList.contains("tf-first")) return;

    const template = document.getElementById("tuffero-loader-tpl");
    if (!template || !document.body) { html.classList.remove("tf-first"); return; }
    document.body.insertBefore(template.content.cloneNode(true), document.body.firstChild);

    const loader = document.getElementById("tuffero-loader");
    const stage = document.getElementById("tuffero-logo-stage");
    const logo = document.getElementById("tuffero-logo-source");

    let resolveLoaded;
    window.tufferoLoaded = new Promise(function (resolve) { resolveLoaded = resolve; });

    const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    // Animasi logo di CSS selesai sekitar 3,4 detik; total 4 detik. Reduced motion: logo langsung, 1,2 detik.
    const MIN_VISIBLE_MS = reduceMotion ? 1200 : 4000;
    const MAX_WAIT_MS = 15000;
    const SPARK_COUNT = 12;
    // Animasi CSS mulai di frame pertama, bukan saat script ini jalan.
    let startedAt = Date.now();
    requestAnimationFrame(function () {
        startedAt = Date.now();
    });

    let loaderHidden = false;

    // Percikan di sepanjang jalur pena, muncul mengikuti waktu pena melewati posisi tersebut.
    function buildSparks() {
        for (let i = 0; i < SPARK_COUNT; i++) {
            const spark = document.createElement("span");
            const progress = i / (SPARK_COUNT - 1);

            spark.className = "tuffero-spark";
            spark.style.setProperty("--x", (progress * 100) + "%");
            spark.style.setProperty("--y", (35 + Math.random() * 35) + "%");
            spark.style.setProperty("--size", (1 + Math.random() * 2.5) + "px");
            spark.style.setProperty("--dx", (-20 - Math.random() * 50) + "px");
            spark.style.setProperty("--dy", (Math.random() * 40 - 20) + "px");
            spark.style.setProperty("--delay", (progress * 1800) + "ms");

            stage.appendChild(spark);
        }
    }

    // Tunggu gambar hero saja; gambar di bawah layar dimuat browser sendiri.
    function waitForHeroImages() {
        const images = Array.from(document.querySelectorAll(".tf-hero img")).filter(function (image) {
            return image.loading !== "lazy";
        });

        return Promise.all(images.map(function (image) {
            if (image.complete) return Promise.resolve();

            return new Promise(function (resolve) {
                image.addEventListener("load", resolve, { once: true });
                image.addEventListener("error", resolve, { once: true });
            });
        }));
    }

    function hideLoader() {
        if (loaderHidden) return;
        loaderHidden = true;

        // Catat hari ini sebagai sudah tampil (kunci dan tanggal dari skrip di <head>).
        const day = window.tufferoLoaderDay;
        if (day) {
            try { localStorage.setItem(day.key, day.today); } catch (e) {
                try { sessionStorage.setItem(day.key, day.today); } catch (e2) { /* abaikan */ }
            }
        }

        if (window.performance && performance.mark) performance.mark("tuffero-loader-hidden");
        loader.classList.add("is-hidden");
        loader.setAttribute("aria-hidden", "true");
        document.dispatchEvent(new Event("tuffero:loaded"));
        resolveLoaded();

        window.setTimeout(function () {
            loader.style.display = "none";
            html.classList.remove("tf-first");
        }, 1400);
    }

    function hideAfterAnimation() {
        const remaining = MIN_VISIBLE_MS - (Date.now() - startedAt);
        window.setTimeout(hideLoader, Math.max(0, remaining));
    }

    // Logo gagal dimuat: tampilkan teks fallback
    logo.addEventListener("error", function () {
        stage.innerHTML =
            '<span class="tuffero-loader__fallback">Tuffero</span>';
    }, { once: true });

    if (!reduceMotion) {
        buildSparks();
    }

    // Jaring pengaman: loader tidak boleh menutup halaman selamanya.
    window.setTimeout(hideLoader, MAX_WAIT_MS);

    window.addEventListener("tuffero:app-ready", function () {
        waitForHeroImages().then(hideAfterAnimation, hideAfterAnimation);
    }, { once: true });
})();
