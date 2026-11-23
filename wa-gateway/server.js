/**
 * MSS WA Gateway: satu sesi WhatsApp (Baileys, multi-device) per cabang, dipakai MSS Billing untuk scan QR dan mengirim
 * tagihan. Hanya mendengarkan 127.0.0.1 dan setiap permintaan wajib membawa header X-Api-Key = WA_GATEWAY_KEY, jadi
 * tidak bisa dipanggil dari internet; billing (Laravel) satu-satunya klien.
 *
 * Konfigurasi dibaca dari .env aplikasi (folder induk) bila belum ada di environment:
 *   WA_GATEWAY_KEY   kunci rahasia bersama billing (minimal 32 karakter)
 *   WA_GATEWAY_PORT  port lokal, bawaan 3100
 *   WA_GATEWAY_DATA  folder data login WhatsApp, bawaan ../storage/app/private/wa-gateway (tidak bisa diakses dari web)
 *
 * API (JSON):
 *   GET    /sessions                  status semua sesi
 *   GET    /sessions/cabang-{id}      status satu sesi (+ teks QR saat menunggu scan)
 *   POST   /sessions/cabang-{id}/start   mulai sesi / tampilkan QR baru
 *   POST   /sessions/cabang-{id}/logout  keluarkan WhatsApp & hapus data login
 *   POST   /sessions/cabang-{id}/send    { to: "628…", text: "…" } kirim pesan teks
 */
import { timingSafeEqual } from 'node:crypto';
import { readFileSync } from 'node:fs';
import fs from 'node:fs/promises';
import http from 'node:http';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseEnv } from 'node:util';
import makeWASocket, { Browsers, DisconnectReason, fetchLatestBaileysVersion, useMultiFileAuthState } from 'baileys';
import pino from 'pino';

const FOLDER = path.dirname(fileURLToPath(import.meta.url));
const AKAR_APLIKASI = path.resolve(FOLDER, '..');

/* Hanya variabel WA_GATEWAY_* yang diambil dari .env aplikasi (bukan password database dll.) */
if (!process.env.WA_GATEWAY_KEY) {
    try {
        const isi = parseEnv(readFileSync(path.join(AKAR_APLIKASI, '.env'), 'utf8'));
        for (const [k, v] of Object.entries(isi)) if (k.startsWith('WA_GATEWAY_') && !(k in process.env)) process.env[k] = v;
    } catch { /* .env tidak ada: pakai environment saja */ }
}

const KUNCI = process.env.WA_GATEWAY_KEY ?? '';
const PORT = Number(process.env.WA_GATEWAY_PORT || 3100);
const DATA = path.resolve(AKAR_APLIKASI, process.env.WA_GATEWAY_DATA || 'storage/app/private/wa-gateway');
/* Batas QR yang ditampilkan per percobaan (±20 detik per QR); setelah itu sesi berhenti sampai tombol Hubungkan ditekan lagi */
const MAKS_QR = 6;
const MAKS_TEKS = 4096;
const POLA_SESI = /^cabang-\d{1,10}$/;
const POLA_NOMOR = /^62\d{8,15}$/;

if (KUNCI.length < 32) {
    console.error('WA_GATEWAY_KEY belum diisi atau kurang dari 32 karakter (isi di .env aplikasi).');
    process.exit(1);
}

const logger = pino({ level: process.env.WA_GATEWAY_LOG || 'warn' });
let versiWa;

/** @type {Map<string, {id: string, status: string, qr: string|null, qrKe: number, nomor: string|null, nama: string|null, sejak: string|null, galat: string|null, sock: any, berhenti: boolean, coba: number}>} */
const sesi = new Map();

const nomorDariJid = (jid) => (jid ? jid.split('@')[0].split(':')[0] : null);
const folderSesi = (id) => path.join(DATA, id);
const hapusDataLogin = (id) => fs.rm(folderSesi(id), { recursive: true, force: true });

function ringkas(s) {
    return { id: s.id, status: s.status, qr: s.status === 'qr' ? s.qr : null, nomor: s.nomor, nama: s.nama, sejak: s.sejak, galat: s.galat };
}

function sesiKosong(id) {
    return { id, status: 'disconnected', qr: null, qrKe: 0, nomor: null, nama: null, sejak: null, galat: null, sock: null, berhenti: false, coba: 0 };
}

/** Mulai (atau sambung ulang) sesi. Bila belum pernah login, Baileys memancarkan QR untuk discan dari HP. */
async function mulai(id) {
    const s = sesi.get(id) ?? sesiKosong(id);
    sesi.set(id, s);
    if (s.sock && ['connecting', 'qr', 'connected'].includes(s.status)) return s;

    Object.assign(s, { status: 'connecting', qr: null, qrKe: 0, galat: null, berhenti: false });
    await fs.mkdir(folderSesi(id), { recursive: true, mode: 0o700 });
    const { state, saveCreds } = await useMultiFileAuthState(folderSesi(id));
    if (!versiWa) versiWa = (await fetchLatestBaileysVersion().catch(() => ({}))).version;

    const sock = makeWASocket({
        auth: state,
        version: versiWa,
        logger,
        browser: Browsers.ubuntu('MSS Billing'),
        markOnlineOnConnect: false,
        // Riwayat chat lama tidak diunduh penuh; gateway ini hanya untuk mengirim
        syncFullHistory: false,
    });
    s.sock = sock;
    sock.ev.on('creds.update', saveCreds);

    sock.ev.on('connection.update', async (u) => {
        if (s.sock !== sock) return; // event dari socket lama
        if (u.qr) {
            s.qrKe += 1;
            if (s.qrKe > MAKS_QR) {
                s.berhenti = true;
                s.galat = 'QR kedaluwarsa, tekan Hubungkan untuk menampilkan QR baru';
                sock.end(undefined);
                return;
            }
            s.qr = u.qr;
            s.status = 'qr';
        }
        if (u.connection === 'open') {
            Object.assign(s, { status: 'connected', qr: null, qrKe: 0, galat: null, coba: 0, sejak: new Date().toISOString() });
            s.nomor = nomorDariJid(sock.user?.id);
            s.nama = sock.user?.name ?? sock.user?.verifiedName ?? null;
            console.log(`[${id}] terhubung sebagai ${s.nomor}`);
        }
        if (u.connection === 'close') {
            const kode = u.lastDisconnect?.error?.output?.statusCode;
            const pernahLogin = !!state.creds?.me;
            s.sock = null;
            s.qr = null;
            if (kode === DisconnectReason.loggedOut) {
                await hapusDataLogin(id);
                Object.assign(s, { status: 'disconnected', nomor: null, nama: null, sejak: null, galat: s.berhenti ? null : 'WhatsApp dikeluarkan dari HP (Perangkat Tertaut)' });
                console.log(`[${id}] logout`);
                return;
            }
            // Berhenti sengaja (QR habis / logout), atau QR tidak pernah discan: jangan sambung ulang terus-menerus
            if (s.berhenti || (!pernahLogin && kode !== DisconnectReason.restartRequired)) {
                if (!pernahLogin) await hapusDataLogin(id);
                Object.assign(s, { status: 'disconnected', berhenti: false });
                if (!s.galat && !pernahLogin) s.galat = 'QR kedaluwarsa, tekan Hubungkan untuk menampilkan QR baru';
                return;
            }
            // Setelah scan WhatsApp meminta restart (515); putus jaringan dll.: sambung ulang dengan jeda bertahap
            s.status = 'connecting';
            s.coba += 1;
            const jeda = kode === DisconnectReason.restartRequired ? 0 : Math.min(60000, 2000 * s.coba);
            setTimeout(() => mulai(id).catch((e) => console.error(`[${id}] gagal menyambung ulang`, e.message)), jeda);
        }
    });

    return s;
}

async function keluar(id) {
    const s = sesi.get(id);
    if (s?.sock) {
        s.berhenti = true;
        try { await s.sock.logout(); } catch { s.sock?.end(undefined); }
    }
    await hapusDataLogin(id);
    sesi.set(id, sesiKosong(id));
}

async function kirim(id, nomor, teks) {
    const s = sesi.get(id);
    if (!s || s.status !== 'connected' || !s.sock) throw galat(409, 'WhatsApp cabang belum terhubung. Scan QR di menu Notifikasi.');
    const [cek] = (await s.sock.onWhatsApp(nomor)) ?? [];
    if (!cek?.exists) throw galat(422, `Nomor ${nomor} tidak terdaftar di WhatsApp`);
    const hasil = await s.sock.sendMessage(cek.jid, { text: teks });
    return { id: hasil?.key?.id ?? null };
}

function galat(status, pesan) {
    return Object.assign(new Error(pesan), { status });
}

/** Sesi yang sudah pernah login (ada creds.json) disambungkan lagi saat layanan menyala. */
async function pulihkan() {
    await fs.mkdir(DATA, { recursive: true, mode: 0o700 });
    for (const nama of await fs.readdir(DATA)) {
        if (!POLA_SESI.test(nama)) continue;
        try {
            await fs.access(path.join(DATA, nama, 'creds.json'));
            await mulai(nama);
        } catch { /* belum pernah login */ }
    }
}

function kunciCocok(diberikan) {
    const a = Buffer.from(String(diberikan ?? ''));
    const b = Buffer.from(KUNCI);
    return a.length === b.length && timingSafeEqual(a, b);
}

function bacaJson(req) {
    return new Promise((selesai, gagal) => {
        let isi = '';
        req.setEncoding('utf8');
        req.on('data', (bagian) => {
            isi += bagian;
            if (isi.length > 32 * 1024) gagal(galat(413, 'Permintaan terlalu besar'));
        });
        req.on('end', () => {
            try { selesai(isi ? JSON.parse(isi) : {}); } catch { gagal(galat(400, 'JSON tidak valid')); }
        });
        req.on('error', gagal);
    });
}

function jawab(res, status, data) {
    res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' });
    res.end(JSON.stringify(data));
}

const server = http.createServer(async (req, res) => {
    try {
        if (!kunciCocok(req.headers['x-api-key'])) return jawab(res, 401, { message: 'Tidak diizinkan' });
        const url = new URL(req.url, 'http://lokal');
        if (req.method === 'GET' && url.pathname === '/sessions') {
            return jawab(res, 200, { sessions: [...sesi.values()].map(ringkas) });
        }
        const cocok = url.pathname.match(/^\/sessions\/([^/]+)(?:\/(start|logout|send))?$/);
        if (!cocok || !POLA_SESI.test(cocok[1])) return jawab(res, 404, { message: 'Tidak ditemukan' });
        const [, id, aksi] = cocok;

        if (req.method === 'GET' && !aksi) return jawab(res, 200, ringkas(sesi.get(id) ?? sesiKosong(id)));
        if (req.method !== 'POST' || !aksi) return jawab(res, 405, { message: 'Metode tidak didukung' });
        if (aksi === 'start') return jawab(res, 200, ringkas(await mulai(id)));
        if (aksi === 'logout') {
            await keluar(id);
            return jawab(res, 200, { message: 'WhatsApp diputuskan' });
        }
        const { to, text } = await bacaJson(req);
        if (!POLA_NOMOR.test(String(to ?? ''))) throw galat(422, 'Nomor tujuan tidak valid');
        if (typeof text !== 'string' || !text.trim() || text.length > MAKS_TEKS) throw galat(422, 'Isi pesan kosong atau terlalu panjang');
        return jawab(res, 200, await kirim(id, to, text));
    } catch (e) {
        if (!e.status) console.error(e);
        return jawab(res, e.status ?? 500, { message: e.status ? e.message : 'Gagal memproses permintaan WhatsApp' });
    }
});

const matikan = () => {
    for (const s of sesi.values()) s.sock?.end(undefined);
    server.close(() => process.exit(0));
    setTimeout(() => process.exit(0), 3000).unref();
};
process.on('SIGTERM', matikan);
process.on('SIGINT', matikan);

await pulihkan();
server.listen(PORT, '127.0.0.1', () => console.log(`MSS WA Gateway siap di 127.0.0.1:${PORT} (data: ${DATA})`));
