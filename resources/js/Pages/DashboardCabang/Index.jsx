import { useMemo, useState } from 'react';
import { Card, CardBody } from 'reactstrap';
import FilterBar from '../../Components/FilterBar';
import PageHeader from '../../Components/PageHeader';
import RekapCabangTable, { sumRekap } from '../../Components/RekapCabangTable';
import RingkasanCard from '../../Components/RingkasanCard';
import Select from '../../Components/Select';
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
                        <div className="mb-3">
                            <Select ariaLabel="Ringkasan cabang" value={pilih} onChange={setPilih} emptyLabel="Ringkasan: Semua Cabang"
                                options={rows.map((r) => ({ value: r.id_lokasi, label: r.nama_cabang }))} />
                        </div>
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
