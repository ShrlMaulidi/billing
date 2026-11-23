import { useState } from 'react';
import { Eye, Printer } from 'react-feather';
import { Button, Modal, ModalBody, ModalFooter, ModalHeader } from 'reactstrap';
import CrudPage from '../../Components/CrudPage';
import { MemberCard } from '../../Components/KartuMember';
import { ActionButton } from '../../Components/ui';
import { rupiah, tanggal } from '../../lib/format';

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
