import Alpine from 'alpinejs';
import {
    registerDraftStore,
    registerGcsCalculator,
    registerPainCanvas,
    registerFormEngine,
    registerDosageCalculator,
} from './form-engine';

// Clinical microcopy lives in one place so Blade templates and Alpine
// validation messages cannot drift apart (ui-spec.md "Copy & Microcopy").
window.KlinikForm = {
    copy: {
        systolicError: 'Nilai sistol harus di antara 40 – 300 mmHg.',
        diastolicError: 'Nilai diastol harus di antara 30 – 200 mmHg.',
        gcsIncomplete: 'Pilih ketiga parameter (E, V, M) untuk menghitung GCS.',
        painModalTitle: 'Tingkat Keparahan Nyeri (Skala NRS)',
        painModalHelper: '0: Tidak Nyeri, 1–3: Ringan, 4–6: Sedang, 7–10: Berat',
        draftRecoveredBanner: 'Draf Belum Tersimpan Ditemukan',
        draftBtnRestore: 'Pulihkan Draf',
        draftBtnDiscard: 'Buang & Buat Baru',
        offlineAlert: 'Koneksi Terputus. Draft tersimpan lokal.',
        lockModalTitle: 'Konfirmasi Penguncian Rekam Medis',
        lockModalBody:
            'Sesuai Permenkes No. 24/2022, rekam medis yang telah ditandatangani tidak dapat diubah kembali tanpa prosedur addendum resmi. Lanjutkan?',
        lockBtnConfirm: 'Tandatangani & Kunci Permanen',
        signatureStampLabel: 'Dokumen Rekam Medis Terverifikasi Secara Elektronik',
        bpjsDriftAlert:
            'Waktu komputer lokal Anda berselisih lebih dari 300 detik dengan server SIMRS. Sinkronkan jam perangkat.',
        authUnauthorizedRole:
            'Akses dibatasi. Anda tidak memiliki wewenang untuk mengisi asesmen medis DPJP.',
    },
    triage: {
        resuscitation: { label: 'Kategori 1: Resusitasi (Merah)', css: 'triage-resus' },
        emergent: { label: 'Kategori 2: Emergensi (Oranye)', css: 'triage-emergent' },
        urgent: { label: 'Kategori 3: Urgensi (Kuning)', css: 'triage-urgent' },
        less_urgent: { label: 'Kategori 4: Semi-Urgensi (Hijau)', css: 'triage-lessurgent' },
        non_urgent: { label: 'Kategori 5: Non-Urgensi (Biru)', css: 'triage-nonurgent' },
    },
};

registerGcsCalculator(Alpine);
registerDosageCalculator(Alpine);
registerPainCanvas(Alpine);
registerFormEngine(Alpine);
registerDraftStore(Alpine);

window.Alpine = Alpine;
Alpine.start();