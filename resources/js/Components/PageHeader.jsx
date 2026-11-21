import { Head, Link } from '@inertiajs/react';

// Judul halaman + breadcrumb gaya Cuba
// home: tujuan ikon rumah di breadcrumb (dashboard pelanggan memakai /akun-saya)
export default function PageHeader({ title, crumbs = [], actions, home = '/dashboard' }) {
    return (
        <>
            <Head title={title} />
            <div className="container-fluid">
                <div className="page-title">
                    <div className="row align-items-center g-2">
                        <div className="col-sm-6">
                            <h3>{title}</h3>
                        </div>
                        <div className="col-sm-6">
                            <ol className="breadcrumb">
                                <li className="breadcrumb-item">
                                    <Link href={home} prefetch><svg className="stroke-icon"><use href="/cuba/svg/sprite.svg#stroke-home" /></svg></Link>
                                </li>
                                {crumbs.map((c) => (
                                    <li className="breadcrumb-item" key={c.label ?? c}>{c.href ? <Link href={c.href} prefetch>{c.label}</Link> : c.label ?? c}</li>
                                ))}
                                <li className="breadcrumb-item active">{title}</li>
                            </ol>
                        </div>
                    </div>
                    {actions && <div className="d-flex flex-wrap gap-2 mt-3">{actions}</div>}
                </div>
            </div>
        </>
    );
}
