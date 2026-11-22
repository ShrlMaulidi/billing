import { Modal, ModalBody, ModalHeader } from 'reactstrap';

/**
 * Pilihan metode bayar untuk provider yang mewajibkan metode dipilih di billing (Tripay): dikelompokkan per grup
 * (mis. Virtual Account, E-Wallet, Convenience Store). Daftar & batas nominal dari server.
 */
export default function PilihKanal({ kanal, onPilih, onTutup }) {
    const grup = (kanal ?? []).reduce((acc, k) => ({ ...acc, [k.grup ?? 'Lainnya']: [...(acc[k.grup ?? 'Lainnya'] ?? []), k] }), {});

    return (
        <Modal isOpen={!!kanal} toggle={onTutup} centered scrollable>
            <ModalHeader toggle={onTutup}>Pilih Metode Pembayaran</ModalHeader>
            <ModalBody>
                {kanal?.length === 0 && <p className="mb-0">Belum ada metode pembayaran online yang tersedia untuk tagihan ini. Silakan bayar lewat transfer atau tunai.</p>}
                {Object.entries(grup).map(([nama, daftar]) => (
                    <section key={nama} className="pilih-kanal-grup">
                        <h6>{nama}</h6>
                        <div className="pilih-kanal-daftar">
                            {daftar.map((k) => (
                                <button key={k.kode} type="button" className="pilih-kanal-item" onClick={() => onPilih(k.kode)}>
                                    {k.ikon ? <img src={k.ikon} alt="" loading="lazy" /> : <span className="pilih-kanal-tanpa-ikon" />}
                                    <span>{k.nama}</span>
                                </button>
                            ))}
                        </div>
                    </section>
                ))}
            </ModalBody>
        </Modal>
    );
}
