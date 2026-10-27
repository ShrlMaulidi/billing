<?php

namespace App\Http\Controllers;

use App\Support\MockData;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SistemController extends Controller
{
    public function laporan(Request $request)
    {
        $f = $this->filters($request) + ['mode' => $request->input('mode', 'bulanan')];

        return Inertia::render('Laporan/Index', [
            'filters' => $f,
            'rows' => $f['mode'] === 'tahunan' ? MockData::rekapTahunan($f['tahun']) : MockData::rekapCabang($this->periode($f)),
            'tahunList' => collect(MockData::periods())->map(fn ($p) => (int) substr($p, 0, 4))->unique()->values(),
        ]);
    }

    public function paymentGateway(Request $request)
    {
        $f = $this->filters($request);

        return Inertia::render('PaymentGateway/Index', [
            'filters' => $f,
            'rows' => MockData::paymentGateway()->when($f['cabang'], fn ($c) => $c->where('id_lokasi', $f['cabang']))->values(),
        ]);
    }

    public function pgChannel(int $id)
    {
        return Inertia::render('PaymentGateway/Channel', [
            'pg' => MockData::paymentGateway()->firstWhere('id', $id) ?? abort(404),
            'rows' => MockData::pgChannel($id),
        ]);
    }

    public function logs(Request $request)
    {
        $q = $request->only(['dari', 'sampai', 'user', 'modul', 'aksi']);
        $logs = MockData::activityLog();
        $rows = $logs
            ->when($q['dari'] ?? null, fn ($c, $v) => $c->filter(fn ($r) => substr($r['created_at'], 0, 10) >= $v))
            ->when($q['sampai'] ?? null, fn ($c, $v) => $c->filter(fn ($r) => substr($r['created_at'], 0, 10) <= $v))
            ->when($q['user'] ?? null, fn ($c, $v) => $c->where('user', $v))
            ->when($q['modul'] ?? null, fn ($c, $v) => $c->where('modul', $v))
            ->when($q['aksi'] ?? null, fn ($c, $v) => $c->where('aksi', $v));

        return Inertia::render('Logs/Index', [
            'filters' => $q,
            'rows' => $rows->values(),
            'options' => [
                'user' => $logs->pluck('user')->unique()->sort()->values(),
                'modul' => $logs->pluck('modul')->unique()->sort()->values(),
            ],
        ]);
    }

    public function users()
    {
        return Inertia::render('User/Manajemen', [
            'rows' => MockData::users()->values(),
            'roleOptions' => MockData::roles()->map(fn ($r) => ['value' => $r['id'], 'label' => $r['nama']]),
        ]);
    }

    public function hakAkses(Request $request)
    {
        $role = (int) $request->input('role', 2);

        return Inertia::render('User/HakAkses', [
            'roles' => MockData::roles(),
            'selected' => $role,
            'menus' => MockData::permissionMenus(),
            'permissions' => MockData::rolePermissions($role),
        ]);
    }

    public function profil()
    {
        return Inertia::render('User/Profil', ['user' => MockData::users()->first()]);
    }
}
