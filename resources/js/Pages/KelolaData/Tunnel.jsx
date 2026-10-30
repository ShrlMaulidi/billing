import { useState } from 'react';
import { Code, Copy, Info } from 'react-feather';
import { Button, Modal, ModalBody, ModalFooter, ModalHeader } from 'reactstrap';
import CrudPage from '../../Components/CrudPage';
import { ActionButton, StatusBadge } from '../../Components/ui';
import { waktu } from '../../lib/format';
import { api, deleteFrom, notify, saveTo } from '../../lib/ui';

export default function Tunnel({ filters, rows, saran, jaringan }) {
    const [script, setScript] = useState(null); // { nama, text }
    const [loading, setLoading] = useState(null);
    const lihatScript = async (r) => {
        setLoading(r.id);
        try {
            setScript({ nama: r.nama_tunnel, text: (await api('GET', `/mikrotik/tunnel/${r.id}/script`)).script });
        } catch (err) {
            notify(err.message, 'danger', 7000);
        } finally {
            setLoading(null);
        }
    };
    const salin = () => navigator.clipboard?.writeText(script.text).then(() => notify('Script disalin'), () => notify('Gagal menyalin, salin manual', 'danger'));

    return (
        <>
            <CrudPage
                title="Tunnel" rows={rows} filters={filters} filterFields={[]} deleteLabel={(r) => r.nama_tunnel}
                defaults={{ status: 'aktif', server: saran.server ?? '', ip_tunnel: saran.ip_tunnel ?? '' }}
                onSave={saveTo('/mikrotik/tunnel')} onDelete={deleteFrom('/mikrotik/tunnel')}
                before={
                    <div className="alert alert-light-primary d-flex gap-2">
                        <Info size={16} className="flex-shrink-0 mt-1" />
                        <span>
                            Untuk router turunan tanpa IP publik. Router dial <strong>L2TP</strong> ke server tunnel (VPS billing) dan mendapat IP tetap di jaringan <code>{jaringan.subnet}</code> (server <code>{jaringan.gateway}</code>).
                            Langkah: tambah tunnel → klik <Code size={13} /> dan tempel script di terminal router turunan → tunggu status <strong>Online</strong> → tambah router dengan mode <strong>Tunnel</strong>.
                        </span>
                    </div>
                }
                rowActions={(r) => <ActionButton icon={Code} label="Script Mikrotik" color="info" loading={loading === r.id} onClick={() => lihatScript(r)} />}
                columns={[
                    { key: 'nama_tunnel', label: 'Nama Tunnel', render: (r) => <>{r.nama_tunnel}<br /><small className="f-light">{r.protokol} · {r.server}</small></> },
                    { key: 'username', label: 'User' },
                    { key: 'ip_tunnel', label: 'IP Router di Tunnel' },
                    {
                        key: 'sesi', label: 'Koneksi', render: (r) => (r.sesi ? (
                            <>
                                <StatusBadge value="online" text="Online" />
                                <br /><small className="f-light">sejak {waktu(r.sesi.acctstarttime)}{r.sesi.callingstationid && ` · dari ${r.sesi.callingstationid}`}</small>
                            </>
                        ) : <StatusBadge value="offline" text="Offline" />),
                    },
                    { key: 'routers_count', label: 'Dipakai Router', render: (r) => (r.routers.length ? r.routers.map((x) => x.nama_router).join(', ') : '-') },
                    { key: 'status', label: 'Status', render: (r) => <StatusBadge value={r.status} /> },
                ]}
                fields={[
                    { name: 'nama_tunnel', label: 'Nama Tunnel', required: true, placeholder: 'tunnel-garut' },
                    { name: 'server', label: 'Server Tunnel', required: true, help: 'Alamat VPS billing yang di-dial router turunan' },
                    { name: 'ip_tunnel', label: 'IP Router di Tunnel', required: true, placeholder: '10.10.10.2', help: `IP tetap router turunan di ${jaringan.subnet}; billing terhubung ke IP ini` },
                    { name: 'username', label: 'Username', required: true, help: 'Huruf, angka, titik, minus, atau garis bawah' },
                    { name: 'password', label: 'Password', type: 'password', help: 'Saat edit, kosongkan bila tidak diubah. Disimpan terenkripsi.' },
                    { name: 'status', label: 'Status', type: 'radio', options: [{ value: 'aktif', label: 'Aktif' }, { value: 'tidak_aktif', label: 'Tidak Aktif (login ditolak)' }] },
                ]}
            />
            <Modal isOpen={!!script} toggle={() => setScript(null)} size="lg" centered>
                <ModalHeader toggle={() => setScript(null)}>Script Mikrotik · {script?.nama}</ModalHeader>
                <ModalBody>
                    <p className="f-light mb-2">Tempel di <strong>New Terminal</strong> Winbox router turunan:</p>
                    <pre className="bg-light p-3 rounded mb-0" style={{ whiteSpace: 'pre-wrap', wordBreak: 'break-all', fontSize: 12 }}>{script?.text}</pre>
                </ModalBody>
                <ModalFooter>
                    <Button color="light" onClick={() => setScript(null)}>Tutup</Button>
                    <Button color="primary" onClick={salin}><Copy size={14} className="me-1" />Salin Script</Button>
                </ModalFooter>
            </Modal>
        </>
    );
}
