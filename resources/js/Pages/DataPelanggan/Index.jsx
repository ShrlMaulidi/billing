import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Eye } from 'react-feather';
import { Button, Modal, ModalBody, ModalFooter, ModalHeader } from 'reactstrap';
import CrudPage from '../../Components/CrudPage';
import { ActionButton, StatusBadge } from '../../Components/ui';
import { CABANG_DEFAULTS, CabangFormInfo, cabangFields } from '../../lib/cabang';
import { angka, rupiah, tanggal } from '../../lib/format';
import { api, deleteFrom, notify, reloadData, saveTo, useBisa } from '../../lib/ui';

/** Reseller & Retail disimpan sebagai cabang (/cabang); Korporat & Instansi Pemerintah di /data-pelanggan/instansi. */
const CABANG = ['reseller', 'retail'];
const isCabang = (tipe) => CABANG.includes(tipe);
const WARNA = { reseller: 'primary', korporat: 'info', pemerintah: 'success', retail: 'warning' };
const BASE_INSTANSI = '/data-pelanggan/instansi';
const DOKUMEN_TERSIMPAN = 'Dokumen tersimpan — klik untuk mengganti';

const JNS_TAGIHAN = [{ value: 'prabayar', label: 'Prabayar' }, { value: 'pascabayar', label: 'Pascabayar' }];
const STATUS_LANGGANAN = [{ value: 'berlangganan', label: 'Berlangganan' }, { value: 'berhenti', label: 'Berhenti' }];
const DOKUMEN_HINT = 'JPG / PNG / WEBP / PDF, maks 2 MB';
const KOLOM_INSTANSI = ['nama', 'alamat', 'kapasitas', 'harga', 'metro', 'nik', 'npwp', 'tanggal_aktif', 'tanggal_berhenti', 'jns_tagihan', 'status', 'sales_id'];

/** Field korporat & instansi pemerintah, urut sesuai formulir masing-masing tipe. */
const instansiFields = (tipe, salesOptions) => {
    const f = {
        nama: { name: 'nama', label: tipe === 'korporat' ? 'Nama Perusahaan' : 'Nama Instansi', required: true, col: 'col-12' },
        kapasitas: { name: 'kapasitas', label: 'Kapasitas', required: true, placeholder: '100 Mbps' },
        harga: { name: 'harga', label: 'Harga / Bulan', type: 'currency', required: true },
        metro: { name: 'metro', label: 'Metro' },
        alamat: { name: 'alamat', label: 'Alamat', type: 'textarea', required: true, col: 'col-12' },
        nik: { name: 'nik', label: 'NIK', placeholder: '16 digit', help: 'NIK penanggung jawab / pemilik' },
        npwp: { name: 'npwp', label: 'NPWP', placeholder: '15 atau 16 digit' },
        file_ktp: { name: 'file_ktp', label: 'Upload KTP', type: 'file', accept: '.jpg,.jpeg,.png,.webp,.pdf', hint: DOKUMEN_HINT },
        file_npwp: { name: 'file_npwp', label: 'Upload NPWP', type: 'file', accept: '.jpg,.jpeg,.png,.webp,.pdf', hint: DOKUMEN_HINT },
        tanggal_aktif: { name: 'tanggal_aktif', label: 'Tanggal Aktif', type: 'date', required: true },
        jns_tagihan: { name: 'jns_tagihan', label: 'Jenis Tagihan', type: 'radio', required: true, options: JNS_TAGIHAN },
        sales_id: { name: 'sales_id', label: 'Sales', type: 'select', options: salesOptions, help: 'Opsional · sales yang membawa pelanggan ini' },
        status: { name: 'status', label: 'Status', type: 'radio', required: true, options: STATUS_LANGGANAN },
        tanggal_berhenti: {
            name: 'tanggal_berhenti', label: 'Tanggal Berhenti', type: 'date', required: true, show: (v) => v.status === 'berhenti',
            help: 'Bulan sebelum tanggal ini tetap ditagih di laporan keuangan',
        },
    };
    const urutan = tipe === 'korporat'
        ? ['nama', 'kapasitas', 'harga', 'metro', 'alamat', 'nik', 'npwp', 'file_ktp', 'file_npwp', 'tanggal_aktif', 'jns_tagihan', 'sales_id', 'status', 'tanggal_berhenti']
        : ['nama', 'alamat', 'kapasitas', 'harga', 'npwp', 'file_npwp', 'jns_tagihan', 'tanggal_aktif', 'sales_id', 'status', 'tanggal_berhenti'];
    return urutan.map((k) => f[k]);
};

/** Simpan korporat / instansi sebagai multipart (bisa membawa file KTP & NPWP); edit memakai _method=PUT. */
const simpanInstansi = async (values, row) => {
    const data = new FormData();
    if (!row) data.append('tipe', values.tipe);
    KOLOM_INSTANSI.forEach((k) => values[k] !== undefined && values[k] !== null && data.append(k, values[k]));
    ['file_ktp', 'file_npwp'].forEach((k) => values[k] instanceof File && data.append(k, values[k]));
    if (row) data.append('_method', 'PUT');
    try {
        const res = await api('POST', row ? `${BASE_INSTANSI}/${row.id}` : BASE_INSTANSI, data);
        notify(res.message);
        reloadData(['rows']);
    } catch (err) {
        if (!err.errors) notify(err.message, 'danger', 7000);
        throw err;
    }
};

const dokumenLink = (r, jenis) => (r[`ada_${jenis}`]
    ? <a key={jenis} href={`${BASE_INSTANSI}/${r.id}/dokumen/${jenis}`} target="_blank" rel="noopener noreferrer" className="badge badge-light-primary">{jenis.toUpperCase()}</a>
    : null);
const dokumen = (r) => {
    const links = [r.tipe === 'korporat' && dokumenLink(r, 'ktp'), dokumenLink(r, 'npwp')].filter(Boolean);
    return links.length ? <div className="d-flex gap-1">{links}</div> : '-';
};
const strip = (v) => v || '-';

/**
 * Data Pelanggan semua tipe: Mitra Reseller, Korporat, Instansi Pemerintah, Retail / Shafa.net.
 * Tambah data dimulai dengan memilih tipe; isi formulir menyesuaikan tipe yang dipilih.
 */
export default function DataPelanggan({ filters, rows, tipeOptions, periodeSekarang, hariIni, salesOptions }) {
    const { auth } = usePage().props;
    const bisa = useBisa();
    const [detail, setDetail] = useState(null);
    const labelTipe = Object.fromEntries(tipeOptions.map((o) => [o.value, o.label]));
    const tipeBadge = (r) => <span className={`badge badge-light-${WARNA[r.tipe] ?? 'secondary'}`}>{labelTipe[r.tipe] ?? r.tipe}</span>;

    // Nilai bawaan saat tipe dipilih di form tambah
    const bawaan = (tipe) => (isCabang(tipe) ? CABANG_DEFAULTS : { status: 'berlangganan', jns_tagihan: 'prabayar', tanggal_aktif: hariIni });

    const fields = [
        {
            name: 'tipe', label: 'Tipe Pelanggan', type: 'select', required: true, col: 'col-12', options: tipeOptions, show: (v) => !v.id, resets: bawaan,
            help: 'Isi formulir menyesuaikan tipe yang dipilih',
        },
        { name: 'tipe_tampil', label: 'Tipe Pelanggan', type: 'static', col: 'col-12', show: (v) => !!v.id, render: (v) => tipeBadge(v) },
        ...cabangFields({ adminFullTerkunci: !!auth.user?.id_lokasi }).map((f) => ({ ...f, show: (v) => isCabang(v.tipe) && (!f.show || f.show(v)) })),
        ...['korporat', 'pemerintah'].flatMap((tipe) => instansiFields(tipe, salesOptions).map((f) => ({ ...f, show: (v) => v.tipe === tipe && (!f.show || f.show(v)) }))),
    ];

    const kolomCabang = [
        { key: 'kode_lokasi', label: 'Kode' }, { key: 'nama', label: 'Nama' }, { key: 'nama_mitra', label: 'Mitra', render: (r) => strip(r.nama_mitra) },
        { key: 'alamat', label: 'Alamat' }, { key: 'tlp_lokasi', label: 'Tlp', render: (r) => strip(r.tlp_lokasi) },
        { key: 'jumlah_area', label: 'Jml Area', align: 'end', render: (r) => angka(r.jumlah_area) },
        { key: 'jumlah_pelanggan', label: 'Jml Pelanggan', align: 'end', render: (r) => angka(r.jumlah_pelanggan) },
        { key: 'status', label: 'Status', render: (r) => <StatusBadge value={r.status} /> },
    ];
    const kolomUmum = {
        kapasitas: { key: 'kapasitas', label: 'Kapasitas', render: (r) => strip(r.kapasitas) },
        harga: { key: 'harga', label: 'Harga', align: 'end', render: (r) => (r.sumber === 'instansi' ? rupiah(r.harga) : '-') },
        tanggal_aktif: { key: 'tanggal_aktif', label: 'Tgl Aktif', render: (r) => tanggal(r.tanggal_aktif) },
        jns_tagihan: { key: 'jns_tagihan', label: 'Jenis Tagihan', render: (r) => (r.jns_tagihan ? <StatusBadge value={r.jns_tagihan} text={r.jns_tagihan === 'pascabayar' ? 'Pascabayar' : 'Prabayar'} /> : '-') },
        status: { key: 'status', label: 'Status', render: (r) => <StatusBadge value={r.status} /> },
        dokumen: { key: 'dokumen', label: 'Dokumen', sortValue: (r) => (r.ada_ktp ? 1 : 0) + (r.ada_npwp ? 1 : 0), render: dokumen },
        sales: { key: 'nama_sales', label: 'Sales', render: (r) => strip(r.nama_sales) },
    };
    const k = kolomUmum;
    const columns = {
        reseller: kolomCabang,
        retail: kolomCabang,
        korporat: [
            { key: 'nama', label: 'Nama Perusahaan' }, k.kapasitas, k.harga, { key: 'metro', label: 'Metro', render: (r) => strip(r.metro) }, { key: 'alamat', label: 'Alamat' },
            { key: 'nik', label: 'NIK', render: (r) => strip(r.nik) }, { key: 'npwp', label: 'NPWP', render: (r) => strip(r.npwp) }, k.dokumen, k.tanggal_aktif, k.jns_tagihan, k.sales, k.status,
        ],
        pemerintah: [
            { key: 'nama', label: 'Nama Instansi' }, { key: 'alamat', label: 'Alamat' }, k.kapasitas, k.harga,
            { key: 'npwp', label: 'NPWP', render: (r) => strip(r.npwp) }, k.dokumen, k.jns_tagihan, k.tanggal_aktif, k.sales, k.status,
        ],
    }[filters.tipe] ?? [
        { key: 'tipe', label: 'Tipe', sortValue: (r) => labelTipe[r.tipe], render: tipeBadge }, { key: 'nama', label: 'Nama' }, { key: 'alamat', label: 'Alamat' },
        k.kapasitas, k.harga, k.tanggal_aktif, k.jns_tagihan, k.status,
    ];

    // Form edit menampilkan dokumen yang sudah tersimpan; hanya file baru yang dikirim ulang
    const data = rows.map((r) => (r.sumber === 'instansi'
        ? { ...r, file_ktp: r.ada_ktp ? DOKUMEN_TERSIMPAN : null, file_npwp: r.ada_npwp ? DOKUMEN_TERSIMPAN : null }
        : r));

    return (
        <>
            <CrudPage
                key={filters.tipe ?? 'semua'}
                title="Data Pelanggan" crumbs={['Data & Transaksi']} entity="Data Pelanggan" rows={data} filters={filters}
                filterFields={[{ name: 'tipe', label: 'Tipe', options: tipeOptions, width: 220 }]}
                tableProps={{ rowKey: 'key' }}
                columns={columns}
                fields={fields}
                defaults={{}}
                deleteLabel={(r) => r.nama}
                deleteMessage={(r) => (r.sumber === 'lokasi'
                    ? <>Yakin ingin menghapus <strong>{r.nama}</strong> ({labelTipe[r.tipe]})? Cabang yang masih dipakai pelanggan, router, paket, akun user, pembayaran, atau rekening tidak bisa dihapus.</>
                    : <>Yakin ingin menghapus <strong>{r.nama}</strong> ({labelTipe[r.tipe]})? Dokumen KTP / NPWP yang tersimpan ikut terhapus.</>)}
                onSave={(values, row) => (isCabang(values.tipe) ? saveTo('/cabang')(values, row) : simpanInstansi(values, row))}
                onDelete={(row) => deleteFrom(row.sumber === 'lokasi' ? '/cabang' : BASE_INSTANSI)(row)}
                canAdd={bisa('data_pelanggan.create')} canEdit={bisa('data_pelanggan.update')} canDelete={bisa('data_pelanggan.delete')}
                rowActions={(r) => <ActionButton icon={Eye} label="Lihat Detail" color="info" onClick={() => setDetail(r)} />}
                formExtra={(v) => (isCabang(v.tipe) ? <CabangFormInfo values={v} periodeSekarang={periodeSekarang} /> : null)}
            />
            <DetailModal row={detail} toggle={() => setDetail(null)} tipeBadge={tipeBadge} />
        </>
    );
}

function DetailModal({ row, toggle, tipeBadge }) {
    const r = row ?? {};
    const isi = r.sumber === 'lokasi'
        ? [
            ['Kode Lokasi', r.kode_lokasi], ['Nama Lokasi', r.nama], ['Mitra / Pemilik', strip(r.nama_mitra)], ['Alamat', r.alamat],
            ['Tlp Lokasi', strip(r.tlp_lokasi)], ['Tlp Owner', strip(r.tlp_owner)], ['ID Grup WA', strip(r.group_wa)],
            ['Jumlah Area', angka(r.jumlah_area)], ['Jumlah Pelanggan Aktif', angka(r.jumlah_pelanggan)],
            ['Terima Cash', <StatusBadge key="cash" value={r.terima_cash} />], ['Admin Full', r.admin_full ? 'Ya' : 'Tidak'], ['Status', <StatusBadge key="st" value={r.status} />],
        ]
        : [
            [r.tipe === 'korporat' ? 'Nama Perusahaan' : 'Nama Instansi', r.nama], ['Alamat', r.alamat], ['Kapasitas', r.kapasitas], ['Harga / Bulan', rupiah(r.harga)],
            ...(r.tipe === 'korporat' ? [['Metro', strip(r.metro)], ['NIK', strip(r.nik)]] : []),
            ['NPWP', strip(r.npwp)], ['Dokumen', dokumen(r)], ['Tanggal Aktif', tanggal(r.tanggal_aktif)],
            ['Jenis Tagihan', r.jns_tagihan === 'pascabayar' ? 'Pascabayar' : 'Prabayar'], ['Sales', strip(r.nama_sales)], ['Status', <StatusBadge key="st" value={r.status} />],
            ...(r.status === 'berhenti' ? [['Tanggal Berhenti', tanggal(r.tanggal_berhenti)]] : []),
        ];
    return (
        <Modal isOpen={!!row} toggle={toggle} centered scrollable>
            <ModalHeader toggle={toggle}>Detail Pelanggan</ModalHeader>
            <ModalBody>
                <div className="mb-3">{row && tipeBadge(r)}</div>
                <dl className="row mb-0">
                    {isi.map(([label, nilai]) => (
                        <div key={label} className="col-12 d-flex border-bottom py-2">
                            <dt className="col-5 fw-normal f-light">{label}</dt>
                            <dd className="col-7 mb-0 text-break">{nilai ?? '-'}</dd>
                        </div>
                    ))}
                </dl>
            </ModalBody>
            <ModalFooter><Button color="light" onClick={toggle}>Tutup</Button></ModalFooter>
        </Modal>
    );
}
