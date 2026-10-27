import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { MessageSquare } from 'react-feather';
import { Modal, ModalBody, ModalHeader } from 'reactstrap';
import CrudPage from '../../Components/CrudPage';
import { ActionButton, StatusBadge } from '../../Components/ui';
import { label } from '../../lib/format';

const SAMPLE = { '{nama}': 'Budi Santoso', '{tagihan}': 'Rp 222.000', '{jatuh_tempo}': '10 Nov 2026', '{link}': 'https://pay.mss.net.id/INV261100123' };
const render = (text = '', link = true) => Object.entries(SAMPLE).reduce((t, [k, v]) => t.replaceAll(k, k === '{link}' && !link ? '' : v), text);
const jadwal = (r) => (r.jadwal_tipe === 'serentak_tanggal' ? `Serentak tgl ${r.offset_hari}` : r.offset_hari === 0 ? 'Hari H' : `H${r.offset_hari > 0 ? '+' : ''}${r.offset_hari}`);

function Preview({ text, link }) {
    return <div className="wa-preview"><div className="bubble">{render(text, link)}</div></div>;
}

export default function Notifikasi({ filters, rows }) {
    const { cabangOptions } = usePage().props;
    const [preview, setPreview] = useState(null);
    return (
        <>
            <CrudPage
                title="Notifikasi" entity="Template Notifikasi" rows={rows} filters={filters} deleteLabel={(r) => r.nama_notifikasi} modalSize="xl"
                defaults={{ jadwal_tipe: 'relatif_jatuh_tempo', target: 'belum_lunas', status: true, tampilkan_link_bayar: true, isi_pesan: 'Halo {nama},\n\nTagihan Anda {tagihan} jatuh tempo {jatuh_tempo}.\n{link}' }}
                rowActions={(r) => <ActionButton icon={MessageSquare} label="Preview Pesan" color="success" onClick={() => setPreview(r)} />}
                columns={[
                    { key: 'nama_notifikasi', label: 'Nama Notifikasi' }, { key: 'jadwal', label: 'Jadwal', render: jadwal, sortValue: (r) => r.offset_hari },
                    { key: 'jam_kirim', label: 'Jam' }, { key: 'tipe', label: 'Tipe', render: (r) => label(r.tipe) }, { key: 'target', label: 'Target', render: (r) => label(r.target) },
                    { key: 'tampilkan_link_bayar', label: 'Link Bayar', render: (r) => <StatusBadge value={r.tampilkan_link_bayar} /> },
                    { key: 'cabang', label: 'Cabang' }, { key: 'status', label: 'Status', render: (r) => <StatusBadge value={r.status ? 'aktif' : 'tidak_aktif'} /> },
                ]}
                fields={[
                    { name: 'nama_notifikasi', label: 'Nama Notifikasi', required: true, col: 'col-md-4' },
                    { name: 'tipe', label: 'Tipe', type: 'select', required: true, col: 'col-md-4', options: ['reminder_tagihan', 'jatuh_tempo', 'isolir', 'bukti_bayar', 'pelanggan_baru'] },
                    { name: 'id_lokasi', label: 'Cabang', type: 'select', col: 'col-md-4', options: cabangOptions, help: 'Kosongkan = semua cabang' },
                    { name: 'jadwal_tipe', label: 'Jadwal', type: 'radio', col: 'col-md-4', options: [{ value: 'relatif_jatuh_tempo', label: 'Relatif Jatuh Tempo' }, { value: 'serentak_tanggal', label: 'Serentak Tanggal' }] },
                    { name: 'offset_hari', label: 'Offset Hari (H-x negatif, H+x positif)', type: 'number', col: 'col-md-4', show: (v) => v.jadwal_tipe !== 'serentak_tanggal' },
                    { name: 'offset_hari', label: 'Tanggal Kirim (1–31)', type: 'number', col: 'col-md-4', show: (v) => v.jadwal_tipe === 'serentak_tanggal' },
                    { name: 'jam_kirim', label: 'Jam Kirim', type: 'time', col: 'col-md-4' },
                    { name: 'target', label: 'Target', type: 'select', col: 'col-md-4', options: ['belum_lunas', 'semua', 'off'] },
                    { name: 'tampilkan_link_bayar', label: 'Tampilkan link bayar', type: 'switch', col: 'col-md-4' },
                    { name: 'status', label: 'Aktif', type: 'switch', col: 'col-md-4' },
                    { name: 'isi_pesan', label: 'Isi Pesan', type: 'textarea', rows: 7, required: true, col: 'col-md-7', help: 'Placeholder: {nama} {tagihan} {jatuh_tempo} {link}' },
                ]}
                formExtra={(v) => <div className="row"><div className="col-md-7 offset-md-0"><label className="form-label">Preview</label><Preview text={v.isi_pesan} link={v.tampilkan_link_bayar} /></div></div>}
            />
            <Modal isOpen={!!preview} toggle={() => setPreview(null)} centered>
                <ModalHeader toggle={() => setPreview(null)}>{preview?.nama_notifikasi}</ModalHeader>
                <ModalBody><Preview text={preview?.isi_pesan} link={preview?.tampilkan_link_bayar} /></ModalBody>
            </Modal>
        </>
    );
}
