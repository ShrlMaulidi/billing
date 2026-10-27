import { useMemo, useState } from 'react';
import { Card, CardBody, Input } from 'reactstrap';
import FilterBar from '../../Components/FilterBar';
import PageHeader from '../../Components/PageHeader';
import RekapCabangTable, { sumRekap } from '../../Components/RekapCabangTable';
import RingkasanCard from '../../Components/RingkasanCard';
import { BULAN } from '../../lib/format';

export default function DashboardCabang({ filters, rows }) {
    const [pilih, setPilih] = useState('');
    const query = `?bulan=${filters.bulan}&tahun=${filters.tahun}`;
    const ringkasan = useMemo(() => (pilih ? rows.find((r) => String(r.id_lokasi) === pilih) : sumRekap(rows)), [pilih, rows]);

    return (
        <>
            <PageHeader title="Dashboard Cabang" />
            <div className="container-fluid">
                <div className="row">
                    <div className="col-xxl-4 col-xl-5">
                        <Input type="select" className="mb-3" value={pilih} onChange={(e) => setPilih(e.target.value)}>
                            <option value="">Ringkasan: Semua Cabang</option>
                            {rows.map((r) => <option key={r.id_lokasi} value={r.id_lokasi}>{r.nama_cabang}</option>)}
                        </Input>
                        {ringkasan && (
                            <RingkasanCard row={ringkasan} subtitle={`${ringkasan.nama_cabang} · ${BULAN[filters.bulan - 1]} ${filters.tahun}`}
                                moreHref={pilih ? `/dashboard-cabang/${pilih}${query}` : `/laporan${query}`} />
                        )}
                    </div>
                    <div className="col-xxl-8 col-xl-7">
                        <Card>
                            <CardBody>
                                <RekapCabangTable rows={rows} detailQuery={query} toolbar={<FilterBar filters={filters} fields={['bulan', 'tahun']} />} />
                            </CardBody>
                        </Card>
                    </div>
                </div>
            </div>
        </>
    );
}
