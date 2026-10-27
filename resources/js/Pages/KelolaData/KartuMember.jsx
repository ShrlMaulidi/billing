import { useState } from 'react';
import { Eye, Printer } from 'react-feather';
import { Button, Modal, ModalBody, ModalFooter, ModalHeader } from 'reactstrap';
import CrudPage from '../../Components/CrudPage';
import { ActionButton } from '../../Components/ui';
import { rupiah, tanggal } from '../../lib/format';

// Pola QR tiruan (placeholder) — nanti diganti library QR sungguhan
function FakeQr({ text }) {
    let h = 0;
    for (const c of text) h = (h * 31 + c.charCodeAt(0)) >>> 0;
    const cells = [];
    for (let y = 0; y < 21; y++) for (let x = 0; x < 21; x++) {
        const finder = (x < 7 && y < 7) || (x > 13 && y < 7) || (x < 7 && y > 13);
        const on = finder ? (x % 6 === 0 || y % 6 === 0 || (x % 14 > 1 && x % 14 < 5 && y % 14 > 1 && y % 14 < 5)) : ((h >> ((x * 7 + y * 3) % 31)) ^ (x * y)) & 1;
        if (on) cells.push(<rect key={`${x}-${y}`} x={x} y={y} width="1" height="1" />);
    }
    return <svg viewBox="0 0 21 21" width="100%" height="100%">{cells}</svg>;
}

export function MemberCard({ p }) {
    return (
        <div className="member-card mb-3">
            <div className="d-flex justify-content-between"><strong>MSS NET</strong><small>KARTU MEMBER</small></div>
            <h5 className="text-white mt-3 mb-0">{p.nama}</h5>
            <div style={{ fontSize: 13, opacity: 0.9 }}>{p.id_pelanggan}</div>
            <div style={{ fontSize: 12, opacity: 0.85, marginTop: 10, maxWidth: 220 }}>{p.paket} · {rupiah(p.harga)}<br />Jatuh tempo tiap tgl {p.tgl_jatuh_tempo}<br />{p.tlp}</div>
            <div className="qr"><FakeQr text={p.id_pelanggan} /></div>
        </div>
    );
}

export default function KartuMember({ filters, rows }) {
    const [selected, setSelected] = useState(new Set());
    const [preview, setPreview] = useState(null);
    const openBulk = () => setPreview(rows.filter((r) => selected.has(r.id)));

    return (
        <>
            <CrudPage
                title="Kartu Member" entity="Kartu Member" rows={rows} filters={filters}
                canAdd={false} canEdit={false} canDelete={false}
                headerActions={<Button color="primary" disabled={!selected.size} onClick={openBulk}><Printer size={14} className="me-1" />Cetak Terpilih ({selected.size})</Button>}
                rowActions={(r) => <ActionButton icon={Eye} label="Preview & Cetak" onClick={() => setPreview([r])} />}
                tableProps={{ selectable: true, selected, onSelect: setSelected }}
                columns={[
                    { key: 'id_pelanggan', label: 'ID Pelanggan' }, { key: 'nama', label: 'Nama' }, { key: 'alamat', label: 'Alamat' }, { key: 'tlp', label: 'Tlp' },
                    { key: 'paket', label: 'Paket' }, { key: 'harga', label: 'Harga', align: 'end', render: (r) => rupiah(r.harga) },
                    { key: 'tgl_registrasi', label: 'Tgl Registrasi', render: (r) => tanggal(r.tgl_registrasi) },
                    { key: 'tgl_jatuh_tempo', label: 'Tgl Jatuh Tempo', render: (r) => `Tgl ${r.tgl_jatuh_tempo}` },
                ]}
            />
            <Modal isOpen={!!preview} toggle={() => setPreview(null)} size={preview?.length > 1 ? 'xl' : 'md'} centered scrollable>
                <ModalHeader toggle={() => setPreview(null)}>Preview Kartu Member ({preview?.length})</ModalHeader>
                <ModalBody>
                    <div className="d-flex flex-wrap gap-3 justify-content-center">{preview?.map((p) => <MemberCard key={p.id} p={p} />)}</div>
                </ModalBody>
                <ModalFooter>
                    <Button color="light" onClick={() => setPreview(null)}>Tutup</Button>
                    <Button color="primary" onClick={() => window.print()}><Printer size={14} className="me-1" />Cetak / Simpan PDF</Button>
                </ModalFooter>
            </Modal>
        </>
    );
}
