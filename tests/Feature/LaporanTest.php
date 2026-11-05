<?php

namespace Tests\Feature;

use App\Models\Lokasi;
use App\Models\Paket;
use App\Models\Pelanggan;
use App\Models\PelangganInstansi;
use App\Models\Pembayaran;
use App\Models\SetoranMitra;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class LaporanTest extends TestCase
{
    use RefreshDatabase;

    private Lokasi $garut;

    private Lokasi $shafa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-15 10:00:00');
        $this->garut = Lokasi::create(['tipe' => 'reseller', 'kode_lokasi' => 'C001', 'nama_lokasi' => 'MSS Garut', 'nama_mitra' => 'Rifky']);
        $this->shafa = Lokasi::create(['tipe' => 'retail', 'kode_lokasi' => 'C002', 'nama_lokasi' => 'Shafa.net']);
    }

    public function test_corporate_report_classifies_paid_partial_and_unpaid_customers_with_ppn(): void
    {
        $this->instansi(['nama' => 'PT Belum']);
        $this->instansi(['nama' => 'PT Lunas'])->pembayaran()->create(['periode' => '2026-10-01', 'nominal' => 1000000, 'ppn_persen' => 11, 'ppn' => 110000, 'total' => 1110000]);
        $this->instansi(['nama' => 'PT Sebagian'])->pembayaran()->create(['periode' => '2026-10-01', 'nominal' => 500000, 'ppn_persen' => 11, 'ppn' => 55000, 'total' => 555000]);
        $this->actingAs(User::factory()->create());

        $this->get('/laporan/korporat?bulan=10&tahun=2026')->assertOk()->assertInertia(fn ($page) => $page->component('Laporan/Index')
            ->where('judul', 'Laporan Korporat')
            ->where('ringkasan', [
                'omset' => 3330000, 'sebelum_pajak' => 3000000, 'pajak' => 330000, 'masuk' => 1665000, 'sisa' => 1665000,
                'persen_masuk' => 50, 'persen_belum' => 50, 'jumlah' => 3, 'lunas' => 1, 'sebagian' => 1, 'belum_lunas' => 1,
            ])
            ->where('rows.0.nama', 'PT Belum')->where('rows.0.status', 'belum_lunas')->where('rows.0.sisa', 1110000)
            ->where('rows.0.periode_belum', [['periode' => '2026-10-01', 'status' => 'belum_lunas', 'sisa' => 1110000]])
            ->where('rows.1.nama', 'PT Lunas')->where('rows.1.status', 'lunas')->where('rows.1.periode_belum', [])
            ->where('rows.2.nama', 'PT Sebagian')->where('rows.2.status', 'sebagian')->where('rows.2.masuk', 555000)->where('rows.2.sisa', 555000));
        $this->get('/laporan/korporat?status=sebagian')->assertInertia(fn ($page) => $page->has('rows', 1)->where('rows.0.nama', 'PT Sebagian')->where('ringkasan.jumlah', 3));
    }

    public function test_government_payment_without_ppn_settles_the_bill(): void
    {
        // Instansi pemungut PPN membayar tanpa PPN: tagihan periode itu mengikuti tarif pembayarannya
        $this->instansi(['tipe' => 'pemerintah', 'nama' => 'RSUD Garut'])->pembayaran()->create(['periode' => '2026-10-01', 'nominal' => 1000000, 'ppn_persen' => 0, 'ppn' => 0, 'total' => 1000000]);
        $this->actingAs(User::factory()->create());

        $this->get('/laporan/pemerintah')->assertInertia(fn ($page) => $page
            ->where('rows.0.status', 'lunas')->where('rows.0.omset', 1000000)->where('rows.0.pajak', 0)->where('ringkasan.persen_masuk', 100)
            ->where('rows.0.saldo', null));
    }

    public function test_yearly_report_lists_unpaid_periods_from_activation_until_before_stop_date(): void
    {
        $baru = $this->instansi(['nama' => 'PT Baru', 'tanggal_aktif' => '2026-08-10']);
        $baru->pembayaran()->create(['periode' => '2026-08-01', 'nominal' => 1000000, 'ppn_persen' => 11, 'ppn' => 110000, 'total' => 1110000]);
        $baru->pembayaran()->create(['periode' => '2026-10-01', 'nominal' => 100000, 'ppn_persen' => 11, 'ppn' => 11000, 'total' => 111000]);
        $this->instansi(['nama' => 'PT Berhenti', 'status' => 'berhenti', 'tanggal_aktif' => '2025-05-01', 'tanggal_berhenti' => '2026-03-01']);
        $this->instansi(['nama' => 'PT Lama Berhenti', 'status' => 'berhenti', 'tanggal_aktif' => '2025-01-01', 'tanggal_berhenti' => '2025-12-20']);
        $this->actingAs(User::factory()->create());

        $this->get('/laporan/korporat?mode=tahunan&tahun=2026')->assertInertia(fn ($page) => $page
            ->where('periode', array_map(fn ($b) => sprintf('2026-%02d-01', $b), range(1, 10)))->has('rows', 2)
            ->where('rows.0.nama', 'PT Baru')->has('rows.0.periode', 3)->where('rows.0.status', 'sebagian')
            ->where('rows.0.periode_belum', [
                ['periode' => '2026-09-01', 'status' => 'belum_lunas', 'sisa' => 1110000],
                ['periode' => '2026-10-01', 'status' => 'sebagian', 'sisa' => 999000],
            ])
            // Berhenti 1 Maret: Januari & Februari tetap ditagih
            ->where('rows.1.nama', 'PT Berhenti')->where('rows.1.periode_belum.0.periode', '2026-01-01')->where('rows.1.periode_belum.1.periode', '2026-02-01')
            ->has('rows.1.periode_belum', 2));
    }

    public function test_partner_report_bills_the_obligation_to_head_office_and_counts_deposits(): void
    {
        // Cash Rp1.110.000 di mitra, PPN 11%, bagi hasil 40%: kewajiban = PPN 110.000 + bagi hasil 444.000
        $this->garut->pajak()->create([
            'periode' => '2026-01-01', 'ppn_persen' => 11, 'pph_persen' => 0, 'admin_fee_tipe' => 'nominal', 'admin_fee' => 0,
            'metode_bagi_hasil' => 'persentase', 'nilai_bagi_hasil' => 40,
        ]);
        $this->bayar($this->garut, null, '2026-10-01', 1110000, '2026-10-05');
        SetoranMitra::create(['id_lokasi' => $this->garut->id, 'periode' => '2026-10-01', 'nominal' => 150000, 'metode' => 'cash']);
        $this->actingAs(User::factory()->create());

        $this->get('/laporan/mitra')->assertInertia(fn ($page) => $page->has('rows', 1)
            ->where('rows.0.nama', 'MSS Garut')->where('rows.0.keterangan', 'Rifky')
            ->where('rows.0.omset', 554000)->where('rows.0.pajak', 110000)->where('rows.0.sebelum_pajak', 444000)
            ->where('rows.0.masuk', 150000)->where('rows.0.sisa', 404000)->where('rows.0.status', 'sebagian')
            // Saldo minus: uang perusahaan yang masih dipegang mitra
            ->where('rows.0.saldo', -404000)->where('rows.0.periode.0.saldo', -404000));
    }

    public function test_partner_balance_turns_positive_when_head_office_holds_gateway_money(): void
    {
        // Cabang non Admin Full: uang payment gateway masuk ke MSS. Bagi hasil 40% dari 1.000.000 → kewajiban 400.000, sisanya hak mitra
        $this->garut->pajak()->create([
            'periode' => '2026-01-01', 'ppn_persen' => 0, 'pph_persen' => 0, 'admin_fee_tipe' => 'nominal', 'admin_fee' => 0,
            'metode_bagi_hasil' => 'persentase', 'nilai_bagi_hasil' => 40,
        ]);
        Pembayaran::create([
            'id_lokasi' => $this->garut->id, 'periode' => '2026-10-01', 'nama_pelanggan' => 'P', 'nama_paket' => 'P',
            'harga' => 1000000, 'jml_bayar' => 1000000, 'metode' => 'xendit', 'paid_at' => '2026-10-05 09:00:00',
        ]);
        $this->actingAs(User::factory()->create());

        $this->get('/laporan/mitra')->assertInertia(fn ($page) => $page
            ->where('rows.0.status', 'lunas')->where('rows.0.sisa', 0)->where('rows.0.saldo', 600000));
    }

    public function test_shafa_net_report_follows_end_user_billing_rules(): void
    {
        $this->shafa->pajak()->create([
            'periode' => '2026-01-01', 'ppn_persen' => 11, 'pph_persen' => 0.5, 'admin_fee_tipe' => 'nominal', 'admin_fee' => 0,
            'metode_bagi_hasil' => 'persentase', 'nilai_bagi_hasil' => 0,
        ]);
        $paket = Paket::create(['nama_paket' => 'Home 20', 'harga' => 110000, 'jenis' => 'bulanan', 'jns_koneksi' => 'pppoe', 'profile_mikrotik' => 'P']);
        $diskon = $this->pelanggan('Andi', $paket);
        $this->bayar($this->shafa, $diskon, '2026-10-01', 100000);
        $this->pelanggan('Budi', $paket);
        // Pascabayar: tagihan Oktober = pemakaian September
        $pasca = $this->pelanggan('Cici', $paket, ['jns_tagihan' => 'pascabayar']);
        $this->bayar($this->shafa, $pasca, '2026-09-01', 110000);
        $this->pelanggan('Dodi', $paket, ['tgl_registrasi' => '2026-11-01']);
        $this->actingAs(User::factory()->create());

        $this->get('/laporan/shafa-net')->assertInertia(fn ($page) => $page->has('rows', 3)
            // Diskon: lunas dengan nominal yang dibayar; pajak = PPN termasuk harga + PPh 0,5% dari DPP
            ->where('rows.0.nama', 'Andi')->where('rows.0.status', 'lunas')->where('rows.0.omset', 100000)->where('rows.0.pajak', 10360)
            ->where('rows.1.nama', 'Budi')->where('rows.1.status', 'belum_lunas')->where('rows.1.sisa', 110000)
            ->where('rows.2.nama', 'Cici')->where('rows.2.status', 'lunas'));
    }

    public function test_shafa_net_report_compares_locations_before_listing_customers(): void
    {
        $tarogong = Lokasi::create(['tipe' => 'retail', 'kode_lokasi' => 'C004', 'nama_lokasi' => 'Shafa.net Tarogong']);
        $paket = Paket::create(['nama_paket' => 'Home 20', 'harga' => 100000, 'jenis' => 'bulanan', 'jns_koneksi' => 'pppoe', 'profile_mikrotik' => 'P']);
        $this->bayar($this->shafa, $this->pelanggan('Andi', $paket), '2026-10-01', 100000);
        $this->pelanggan('Budi', $paket);
        $this->bayar($tarogong, $this->pelanggan('Cici', $paket, ['id_lokasi' => $tarogong->id]), '2026-10-01', 100000);
        $this->actingAs(User::factory()->create());

        $this->get('/laporan/shafa-net')->assertInertia(fn ($page) => $page->where('lokasi', null)->where('filters.lokasi', null)
            ->has('perLokasi', 2)
            ->where('perLokasi.0.nama_lokasi', 'Shafa.net')->where('perLokasi.0.jumlah', 2)->where('perLokasi.0.omset', 200000)
            ->where('perLokasi.0.masuk', 100000)->where('perLokasi.0.lunas', 1)->where('perLokasi.0.belum_lunas', 1)->where('perLokasi.0.persen_masuk', 50)
            ->where('perLokasi.1.nama_lokasi', 'Shafa.net Tarogong')->where('perLokasi.1.persen_masuk', 100)
            ->where('ringkasan.omset', 300000));
        $this->get("/laporan/shafa-net?lokasi={$tarogong->id}")->assertInertia(fn ($page) => $page
            ->where('lokasi', ['id' => $tarogong->id, 'kode_lokasi' => 'C004', 'nama_lokasi' => 'Shafa.net Tarogong'])->where('filters.lokasi', $tarogong->id)
            ->has('rows', 1)->where('rows.0.nama', 'Cici')->where('ringkasan.omset', 100000)->has('perLokasi', 2));
        // Lokasi bukan Shafa.net diabaikan
        $this->get("/laporan/shafa-net?lokasi={$this->garut->id}")->assertInertia(fn ($page) => $page->where('lokasi', null)->has('rows', 3));
        $this->get('/laporan/korporat')->assertInertia(fn ($page) => $page->where('perLokasi', null));
    }

    public function test_all_reports_combine_types_and_can_filter_one_type(): void
    {
        $this->instansi(['nama' => 'PT Maju']);
        $this->instansi(['tipe' => 'pemerintah', 'nama' => 'Dinas Kominfo']);
        $this->actingAs(User::factory()->create());

        $this->get('/laporan')->assertOk()->assertInertia(fn ($page) => $page->where('judul', 'Semua Laporan')->where('filters.tipe', null)
            ->where('tipeOptions.0', ['value' => 'reseller', 'label' => 'Mitra Reseller'])->has('tipeOptions', 4)
            ->has('perTipe', 4)->where('perTipe.1.tipe', 'pemerintah')->where('perTipe.1.omset', 1110000)
            ->where('ringkasan.omset', 2220000)->has('rows', 2));
        $this->get('/laporan?tipe=korporat')->assertInertia(fn ($page) => $page->where('filters.tipe', 'korporat')
            ->has('perTipe', 1)->has('rows', 1)->where('rows.0.nama', 'PT Maju'));
    }

    public function test_all_reports_load_monthly_trend_after_the_page(): void
    {
        $maju = $this->instansi(['nama' => 'PT Maju', 'tanggal_aktif' => '2026-09-01']);
        $maju->pembayaran()->create(['periode' => '2026-09-01', 'nominal' => 1000000, 'ppn_persen' => 11, 'ppn' => 110000, 'total' => 1110000]);
        $maju->pembayaran()->create(['periode' => '2026-10-01', 'nominal' => 500000, 'ppn_persen' => 11, 'ppn' => 55000, 'total' => 555000]);
        $this->actingAs(User::factory()->create());

        $this->get('/laporan?bulan=10&tahun=2026')->assertInertia(fn ($page) => $page->missing('tren')
            ->loadDeferredProps(fn ($reload) => $reload->has('tren', 12)
                ->where('tren.0', ['periode' => '2025-11-01', 'omset' => 0, 'masuk' => 0])
                ->where('tren.10', ['periode' => '2026-09-01', 'omset' => 1110000, 'masuk' => 1110000])
                ->where('tren.11', ['periode' => '2026-10-01', 'omset' => 1110000, 'masuk' => 555000])));
        // Bulan yang belum berjalan tidak ikut grafik
        $this->get('/laporan?mode=tahunan&tahun=2026')->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($reload) => $reload->has('tren', 10)->where('tren.9.periode', '2026-10-01')));
        $this->get('/laporan?bulan=12&tahun=2026')->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($reload) => $reload->where('tren.11.periode', '2026-10-01')));
        $this->get('/laporan/korporat')->assertInertia(fn ($page) => $page->where('tren', null));
    }

    public function test_branch_user_summary_only_covers_permitted_types_of_own_branch(): void
    {
        $lain = Lokasi::create(['tipe' => 'reseller', 'kode_lokasi' => 'C003', 'nama_lokasi' => 'MSS Cimahi']);
        foreach ([$this->garut, $lain] as $lokasi) {
            $lokasi->pajak()->create(['periode' => '2026-01-01', 'ppn_persen' => 0, 'pph_persen' => 0, 'admin_fee_tipe' => 'nominal', 'admin_fee' => 0, 'metode_bagi_hasil' => 'persentase', 'nilai_bagi_hasil' => 40]);
            $this->bayar($lokasi, null, '2026-10-01', 1000000, '2026-10-05');
        }
        $this->instansi();
        $this->actingAs(User::factory()->peran('Mitra')->create(['id_lokasi' => $this->garut->id]));

        // Role Mitra bawaan hanya punya Laporan Mitra; tipe lain lewat ?tipe= diabaikan
        $this->get('/laporan?tipe=korporat')->assertOk()->assertInertia(fn ($page) => $page
            ->where('tipeOptions', [['value' => 'reseller', 'label' => 'Mitra Reseller']])
            ->has('rows', 1)->where('rows.0.nama', 'MSS Garut')->where('ringkasan.omset', 400000));
    }

    #[TestWith(['/laporan/korporat'])]
    #[TestWith(['/laporan/pemerintah'])]
    public function test_branch_user_cannot_open_corporate_or_government_reports_even_with_permission(string $url): void
    {
        $user = User::factory()->peran('Mitra')->create(['id_lokasi' => $this->garut->id]);
        $user->givePermissionTo(['laporan.korporat.view', 'laporan.pemerintah.view']);

        $this->actingAs($user)->get($url)->assertForbidden();
    }

    public function test_default_mitra_role_opens_partner_report_but_not_shafa_net(): void
    {
        $this->actingAs(User::factory()->peran('Mitra')->create(['id_lokasi' => $this->garut->id]));

        $this->get('/laporan/mitra')->assertOk();
        $this->get('/laporan/shafa-net')->assertForbidden();
    }

    public function test_invalid_filters_fall_back_to_the_current_month(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/laporan/korporat?mode=lainnya&bulan=13&tahun=1990&status=lainnya')->assertInertia(fn ($page) => $page
            ->where('filters.mode', 'bulanan')->where('filters.bulan', 10)->where('filters.tahun', 2026)->where('filters.status', null)
            ->where('periode', ['2026-10-01']));
        $this->get('/laporan/korporat?mode=tahunan&tahun=2027')->assertInertia(fn ($page) => $page->where('periode', [])->has('rows', 0));
    }

    private function bayar(Lokasi $lokasi, ?Pelanggan $pelanggan, string $periode, int $nominal, ?string $tanggal = null): void
    {
        Pembayaran::create([
            'pelanggan_id' => $pelanggan?->id, 'id_lokasi' => $lokasi->id, 'periode' => $periode, 'nama_pelanggan' => $pelanggan->nama ?? 'P', 'nama_paket' => 'P',
            'harga' => $nominal, 'jml_bayar' => $nominal, 'metode' => 'cash', 'paid_at' => ($tanggal ?? $periode).' 09:00:00',
        ]);
    }

    private function pelanggan(string $nama, Paket $paket, array $override = []): Pelanggan
    {
        return Pelanggan::create([
            'nama' => $nama, 'username_pppoe' => strtolower($nama), 'id_lokasi' => $this->shafa->id, 'id_paket' => $paket->id,
            'jns_tagihan' => 'prabayar', 'tgl_registrasi' => '2026-01-01', 'status' => 'aktif', ...$override,
        ]);
    }

    private function instansi(array $override = []): PelangganInstansi
    {
        return PelangganInstansi::create([
            'tipe' => 'korporat', 'nama' => 'PT Maju', 'alamat' => 'Garut', 'kapasitas' => '100 Mbps', 'harga' => 1000000,
            'tanggal_aktif' => '2026-01-01', 'jns_tagihan' => 'prabayar', 'status' => 'berlangganan', ...$override,
        ]);
    }
}
