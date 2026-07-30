$ErrorActionPreference = 'Stop'
$root = 'C:\laragon\www\mbkm_fakultas'
function Write-Utf8NoBom($relative, $content) {
    $path = Join-Path $root $relative
    $dir = Split-Path $path -Parent
    if (!(Test-Path $dir)) { New-Item -ItemType Directory -Force -Path $dir | Out-Null }
    $enc = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($path, $content, $enc)
}

Write-Utf8NoBom 'database\migrations\2026_06_10_000001_phase1_master_data_foundation.php' @'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('kelas')) {
            Schema::create('kelas', function (Blueprint $table) {
                $table->id();
                $table->string('nama_kelas')->unique();
                $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('angkatans')) {
            Schema::create('angkatans', function (Blueprint $table) {
                $table->id();
                $table->integer('tahun')->unique();
                $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
                $table->timestamps();
            });
        }

        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $kelas) {
            DB::table('kelas')->updateOrInsert(['nama_kelas' => $kelas], ['status' => 'aktif', 'updated_at' => now(), 'created_at' => now()]);
        }
        foreach (range(2021, 2027) as $tahun) {
            DB::table('angkatans')->updateOrInsert(['tahun' => $tahun], ['status' => 'aktif', 'updated_at' => now(), 'created_at' => now()]);
        }

        Schema::table('mahasiswa_profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('mahasiswa_profiles', 'kelas_id')) {
                $table->foreignId('kelas_id')->nullable()->after('kelas')->constrained('kelas')->nullOnDelete();
            }
            if (!Schema::hasColumn('mahasiswa_profiles', 'angkatan_id')) {
                $table->foreignId('angkatan_id')->nullable()->after('angkatan')->constrained('angkatans')->nullOnDelete();
            }
            if (!Schema::hasColumn('mahasiswa_profiles', 'profile_status')) {
                $table->enum('profile_status', ['belum_lengkap', 'lengkap'])->default('belum_lengkap')->after('status_mahasiswa');
            }
        });

        Schema::table('dosens', function (Blueprint $table) {
            if (!Schema::hasColumn('dosens', 'profile_status')) {
                $table->enum('profile_status', ['belum_lengkap', 'lengkap'])->default('belum_lengkap')->after('status_dosen');
            }
        });

        Schema::table('pembimbing_lapangans', function (Blueprint $table) {
            if (!Schema::hasColumn('pembimbing_lapangans', 'user_id')) {
                $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('pembimbing_lapangans', 'mitra_id')) {
                $table->foreignId('mitra_id')->nullable()->after('user_id')->constrained('mitras')->nullOnDelete();
            }
            if (!Schema::hasColumn('pembimbing_lapangans', 'status')) {
                $table->enum('status', ['aktif', 'nonaktif'])->default('aktif')->after('instansi');
            }
            if (!Schema::hasColumn('pembimbing_lapangans', 'profile_status')) {
                $table->enum('profile_status', ['belum_lengkap', 'lengkap'])->default('belum_lengkap')->after('status');
            }
            if (!Schema::hasColumn('pembimbing_lapangans', 'catatan')) {
                $table->text('catatan')->nullable()->after('profile_status');
            }
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY role ENUM('superadmin','admin','mahasiswa','dosen','mitra','pembimbing_lapangan') NOT NULL DEFAULT 'mahasiswa'");
            DB::statement('ALTER TABLE mahasiswa_profiles MODIFY user_id BIGINT UNSIGNED NULL');
            DB::statement('ALTER TABLE dosens MODIFY user_id BIGINT UNSIGNED NULL');
            DB::statement('ALTER TABLE pembimbing_lapangans MODIFY pengajuan_id BIGINT UNSIGNED NULL');
        }

        DB::table('mahasiswa_profiles')->whereNotNull('kelas')->whereNull('kelas_id')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $kelasId = DB::table('kelas')->where('nama_kelas', $row->kelas)->value('id');
                if ($kelasId) DB::table('mahasiswa_profiles')->where('id', $row->id)->update(['kelas_id' => $kelasId]);
            }
        });

        DB::table('mahasiswa_profiles')->whereNotNull('angkatan')->whereNull('angkatan_id')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $angkatanId = DB::table('angkatans')->where('tahun', $row->angkatan)->value('id');
                if ($angkatanId) DB::table('mahasiswa_profiles')->where('id', $row->id)->update(['angkatan_id' => $angkatanId]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('pembimbing_lapangans', function (Blueprint $table) {
            foreach (['catatan', 'profile_status', 'status', 'mitra_id', 'user_id'] as $column) {
                if (Schema::hasColumn('pembimbing_lapangans', $column)) $table->dropColumn($column);
            }
        });
        Schema::table('dosens', function (Blueprint $table) {
            if (Schema::hasColumn('dosens', 'profile_status')) $table->dropColumn('profile_status');
        });
        Schema::table('mahasiswa_profiles', function (Blueprint $table) {
            foreach (['profile_status', 'angkatan_id', 'kelas_id'] as $column) {
                if (Schema::hasColumn('mahasiswa_profiles', $column)) $table->dropColumn($column);
            }
        });
        Schema::dropIfExists('angkatans');
        Schema::dropIfExists('kelas');
    }
};
'@

Write-Utf8NoBom 'app\Models\Kelas.php' @'
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Kelas extends Model
{
    protected $table = 'kelas';
    protected $fillable = ['nama_kelas', 'status'];

    public function mahasiswaProfiles()
    {
        return $this->hasMany(MahasiswaProfile::class, 'kelas_id');
    }
}
'@

Write-Utf8NoBom 'app\Models\Angkatan.php' @'
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Angkatan extends Model
{
    protected $fillable = ['tahun', 'status'];

    public function mahasiswaProfiles()
    {
        return $this->hasMany(MahasiswaProfile::class, 'angkatan_id');
    }
}
'@

Write-Utf8NoBom 'app\Models\MahasiswaProfile.php' @'
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MahasiswaProfile extends Model
{
    protected $fillable = [
        'user_id', 'nim', 'nama_lengkap', 'kelas', 'kelas_id', 'jenis_kelamin', 'tempat_lahir', 'tanggal_lahir',
        'no_hp', 'email', 'alamat_lengkap', 'kota', 'provinsi', 'kode_pos',
        'prodi_id', 'fakultas_id', 'angkatan', 'angkatan_id', 'ipk', 'semester', 'sks_lulus',
        'pernah_cuti', 'status_mahasiswa', 'profile_status'
    ];

    public function user() { return $this->belongsTo(User::class); }
    public function prodi() { return $this->belongsTo(Prodi::class); }
    public function fakultas() { return $this->belongsTo(Fakultas::class); }
    public function kelasMaster() { return $this->belongsTo(Kelas::class, 'kelas_id'); }
    public function angkatanMaster() { return $this->belongsTo(Angkatan::class, 'angkatan_id'); }
    public function pengajuans() { return $this->hasMany(PengajuanMagang::class, 'mahasiswa_id'); }
    public function absensis() { return $this->hasMany(AbsensiMagang::class, 'mahasiswa_id'); }

    public function profileComplete(): bool
    {
        return filled($this->nim)
            && filled($this->nama_lengkap)
            && filled($this->jenis_kelamin)
            && filled($this->tanggal_lahir)
            && filled($this->prodi_id)
            && filled($this->fakultas_id)
            && (filled($this->angkatan_id) || filled($this->angkatan))
            && filled($this->semester)
            && filled($this->sks_lulus)
            && filled($this->ipk)
            && filled($this->status_mahasiswa);
    }

    public function syncProfileStatus(): void
    {
        $this->profile_status = $this->profileComplete() ? 'lengkap' : 'belum_lengkap';
    }

    public function activePengajuanExists($periodeId = null): bool
    {
        $query = $this->pengajuans()
            ->where('jenis_pengajuan', 'surat_pengantar')
            ->whereIn('status_pengajuan', ['pending', 'disetujui', 'berjalan'])
            ->when($periodeId, fn($q) => $q->where('periode_id', $periodeId));

        return $query->exists();
    }
}
'@

Write-Utf8NoBom 'app\Models\Dosen.php' @'
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Dosen extends Model
{
    protected $fillable = ['user_id', 'nidn', 'nama_dosen', 'prodi_id', 'no_hp', 'email_dosen', 'status_dosen', 'profile_status'];

    public function user() { return $this->belongsTo(User::class); }
    public function prodi() { return $this->belongsTo(Prodi::class); }
    public function bimbingans() { return $this->hasMany(Bimbingan::class); }

    public function profileComplete(): bool
    {
        return filled($this->nidn)
            && filled($this->nama_dosen)
            && filled($this->prodi_id)
            && filled($this->no_hp)
            && filled($this->email_dosen)
            && filled($this->status_dosen);
    }

    public function syncProfileStatus(): void
    {
        $this->profile_status = $this->profileComplete() ? 'lengkap' : 'belum_lengkap';
    }
}
'@

Write-Utf8NoBom 'app\Models\PembimbingLapangan.php' @'
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PembimbingLapangan extends Model
{
    protected $fillable = [
        'user_id', 'mitra_id', 'pengajuan_id', 'nama', 'jabatan', 'email', 'no_hp',
        'instansi', 'status', 'profile_status', 'catatan'
    ];

    public function user() { return $this->belongsTo(User::class); }
    public function mitra() { return $this->belongsTo(Mitra::class); }
    public function pengajuan() { return $this->belongsTo(PengajuanMagang::class); }

    public function profileComplete(): bool
    {
        return filled($this->nama)
            && filled($this->email)
            && filled($this->no_hp)
            && filled($this->mitra_id)
            && filled($this->status);
    }

    public function syncProfileStatus(): void
    {
        $this->profile_status = $this->profileComplete() ? 'lengkap' : 'belum_lengkap';
    }
}
'@

Write-Utf8NoBom 'app\Models\User.php' @'
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role', 'status'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed'];
    }

    public function mahasiswaProfile() { return $this->hasOne(MahasiswaProfile::class); }
    public function dosen() { return $this->hasOne(Dosen::class); }
    public function mitraUser() { return $this->hasOne(MitraUser::class); }
    public function pembimbingLapangan() { return $this->hasOne(PembimbingLapangan::class); }
    public function notifikasis() { return $this->hasMany(Notifikasi::class); }

    public function isAdmin() { return $this->role === 'admin'; }
    public function isMahasiswa() { return $this->role === 'mahasiswa'; }
    public function isDosen() { return $this->role === 'dosen'; }
    public function isMitra() { return $this->role === 'mitra'; }
    public function isPembimbingLapangan() { return $this->role === 'pembimbing_lapangan'; }
    public function isSuperadmin() { return $this->role === 'superadmin'; }
}
'@

Write-Utf8NoBom 'app\Http\Controllers\Admin\MasterDataController.php' @'
<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Angkatan;
use App\Models\Fakultas;
use App\Models\Kelas;
use App\Models\MahasiswaProfile;
use App\Models\Prodi;
use App\Models\Dosen;
use App\Models\Mitra;
use App\Models\PembimbingLapangan;
use App\Models\Periode;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class MasterDataController extends Controller
{
    private array $types = [
        'fakultas' => ['title' => 'Fakultas', 'model' => Fakultas::class, 'field' => 'nama_fakultas'],
        'program-studi' => ['title' => 'Program Studi', 'model' => Prodi::class, 'field' => 'nama_prodi'],
        'kelas' => ['title' => 'Kelas', 'model' => Kelas::class, 'field' => 'nama_kelas'],
        'angkatan' => ['title' => 'Angkatan', 'model' => Angkatan::class, 'field' => 'tahun'],
    ];

    public function index()
    {
        $cards = [
            ['label' => 'Fakultas', 'count' => Fakultas::count(), 'route' => route('admin.master.reference.index', 'fakultas')],
            ['label' => 'Program Studi', 'count' => Prodi::count(), 'route' => route('admin.master.reference.index', 'program-studi')],
            ['label' => 'Kelas', 'count' => Kelas::count(), 'route' => route('admin.master.reference.index', 'kelas')],
            ['label' => 'Angkatan', 'count' => Angkatan::count(), 'route' => route('admin.master.reference.index', 'angkatan')],
            ['label' => 'Periode Magang', 'count' => Periode::count(), 'route' => route('admin.periode.index')],
            ['label' => 'Data Mahasiswa', 'count' => MahasiswaProfile::count(), 'route' => route('admin.master.mahasiswa.index')],
            ['label' => 'Data Dosen', 'count' => Dosen::count(), 'route' => route('admin.master.dosen.index')],
            ['label' => 'Data Mitra/Instansi', 'count' => Mitra::count(), 'route' => route('admin.mitra.index')],
            ['label' => 'Pembimbing Lapangan', 'count' => PembimbingLapangan::count(), 'route' => route('admin.master.pembimbing.index')],
        ];

        return view('admin.master.index', compact('cards'));
    }

    public function reference(string $type)
    {
        $config = $this->config($type);
        $model = $config['model'];
        $field = $config['field'];
        $items = $model::orderBy($field)->paginate(20);
        $fakultas = Fakultas::orderBy('nama_fakultas')->get();

        return view('admin.master.reference', compact('type', 'config', 'field', 'items', 'fakultas'));
    }

    public function storeReference(Request $request, string $type)
    {
        $config = $this->config($type);
        $field = $config['field'];
        $rules = [$field => 'required|max:255'];
        if ($type === 'program-studi') $rules['fakultas_id'] = 'nullable|exists:fakultas,id';
        if (in_array($type, ['kelas', 'angkatan'], true)) $rules['status'] = 'required|in:aktif,nonaktif';
        $data = $request->validate($rules);

        $config['model']::updateOrCreate([$field => $data[$field]], $data);

        return back()->with('success', $config['title'] . ' berhasil disimpan.');
    }

    public function updateReference(Request $request, string $type, int $id)
    {
        $config = $this->config($type);
        $field = $config['field'];
        $rules = [$field => 'required|max:255'];
        if ($type === 'program-studi') $rules['fakultas_id'] = 'nullable|exists:fakultas,id';
        if (in_array($type, ['kelas', 'angkatan'], true)) $rules['status'] = 'required|in:aktif,nonaktif';
        $data = $request->validate($rules);

        $item = $config['model']::findOrFail($id);
        $item->update($data);

        return back()->with('success', $config['title'] . ' berhasil diperbarui.');
    }

    public function destroyReference(string $type, int $id)
    {
        $config = $this->config($type);
        $item = $config['model']::findOrFail($id);

        try {
            $item->delete();
            return back()->with('success', $config['title'] . ' berhasil dihapus.');
        } catch (QueryException $e) {
            if (isset($item->status)) {
                $item->update(['status' => 'nonaktif']);
                return back()->with('error', $config['title'] . ' sudah dipakai data lain, sehingga dinonaktifkan.');
            }
            return back()->with('error', $config['title'] . ' tidak dapat dihapus karena sudah dipakai data lain.');
        }
    }

    private function config(string $type): array
    {
        abort_unless(isset($this->types[$type]), 404);
        return $this->types[$type];
    }
}
'@

Write-Utf8NoBom 'app\Http\Controllers\Admin\Concerns\ImportsTabularData.php' @'
<?php
namespace App\Http\Controllers\Admin\Concerns;

use PhpOffice\PhpSpreadsheet\IOFactory;

trait ImportsTabularData
{
    private function readRows(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $rawRows = $sheet->toArray(null, true, true, true);
        if (count($rawRows) < 2) {
            return [];
        }

        $headers = array_shift($rawRows);
        $headers = array_map(fn($value) => $this->normalizeHeader((string) $value), $headers);

        $rows = [];
        foreach ($rawRows as $rawRow) {
            $row = [];
            $hasValue = false;
            foreach ($headers as $key => $header) {
                if (!$header) continue;
                $value = $rawRow[$key] ?? null;
                if ($value !== null && trim((string) $value) !== '') $hasValue = true;
                $row[$header] = is_string($value) ? trim($value) : $value;
            }
            if ($hasValue) $rows[] = $row;
        }

        return $rows;
    }

    private function normalizeHeader(string $header): string
    {
        $header = strtolower(trim($header));
        $header = str_replace([' ', '-', '/', '.'], '_', $header);
        return preg_replace('/[^a-z0-9_]/', '', $header);
    }

    private function csvResponse(string $filename, array $headers, iterable $rows)
    {
        $callback = function () use ($headers, $rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headers);
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        };

        return response()->streamDownload($callback, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
'@

Write-Utf8NoBom 'app\Http\Controllers\Admin\MasterMahasiswaController.php' @'
<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ImportsTabularData;
use App\Http\Controllers\Controller;
use App\Models\Angkatan;
use App\Models\Fakultas;
use App\Models\Kelas;
use App\Models\MahasiswaProfile;
use App\Models\Prodi;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class MasterMahasiswaController extends Controller
{
    use ImportsTabularData;

    public function index(Request $request)
    {
        $mahasiswas = MahasiswaProfile::with(['user', 'fakultas', 'prodi', 'kelasMaster', 'angkatanMaster'])
            ->when($request->search, fn($q, $s) => $q->where(function ($sub) use ($s) {
                $sub->where('nim', 'like', "%{$s}%")->orWhere('nama_lengkap', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%");
            }))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.master.mahasiswa.index', compact('mahasiswas'));
    }

    public function create()
    {
        return view('admin.master.mahasiswa.form', $this->formData());
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $this->saveMahasiswa($data);
        return redirect()->route('admin.master.mahasiswa.index')->with('success', 'Data mahasiswa berhasil disimpan.');
    }

    public function show(MahasiswaProfile $mahasiswa)
    {
        $mahasiswa->load(['user', 'fakultas', 'prodi', 'kelasMaster', 'angkatanMaster']);
        return view('admin.master.mahasiswa.show', compact('mahasiswa'));
    }

    public function edit(MahasiswaProfile $mahasiswa)
    {
        return view('admin.master.mahasiswa.form', array_merge($this->formData(), compact('mahasiswa')));
    }

    public function update(Request $request, MahasiswaProfile $mahasiswa)
    {
        $data = $this->validated($request, $mahasiswa);
        $this->saveMahasiswa($data, $mahasiswa);
        return redirect()->route('admin.master.mahasiswa.index')->with('success', 'Data mahasiswa berhasil diperbarui.');
    }

    public function destroy(MahasiswaProfile $mahasiswa)
    {
        if ($mahasiswa->pengajuans()->exists() || $mahasiswa->absensis()->exists()) {
            $mahasiswa->update(['status_mahasiswa' => 'cuti']);
            return back()->with('error', 'Data mahasiswa sudah punya riwayat. Data tidak dihapus dan status mahasiswa diubah menjadi cuti.');
        }
        $mahasiswa->delete();
        return back()->with('success', 'Data mahasiswa berhasil dihapus.');
    }

    public function template()
    {
        return $this->csvResponse('template_import_mahasiswa.csv', $this->headers(), []);
    }

    public function export()
    {
        $rows = MahasiswaProfile::with(['fakultas', 'prodi', 'kelasMaster', 'angkatanMaster'])->orderBy('nim')->get()->map(fn($m) => [
            $m->nim, $m->nama_lengkap, $m->email, $m->kelasMaster?->nama_kelas ?: $m->kelas,
            $m->jenis_kelamin, $m->alamat_lengkap, $m->no_hp, $m->tempat_lahir, $m->tanggal_lahir,
            $m->angkatanMaster?->tahun ?: $m->angkatan, $m->fakultas?->nama_fakultas,
            $m->prodi?->nama_prodi, $m->semester, $m->sks_lulus, $m->ipk, $m->status_mahasiswa,
        ]);
        return $this->csvResponse('data_mahasiswa.csv', $this->headers(), $rows);
    }

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt,xlsx,xls|max:10240']);
        $rows = $this->readRows($request->file('file')->getRealPath());
        $created = 0;
        $updated = 0;
        $skipped = [];

        foreach ($rows as $index => $row) {
            if (blank($row['nim'] ?? null)) {
                $skipped[] = 'Baris ' . ($index + 2) . ': NIM kosong.';
                continue;
            }
            try {
                $existing = MahasiswaProfile::where('nim', $row['nim'])->first();
                $this->saveMahasiswa([
                    'nim' => $row['nim'] ?? null,
                    'nama_lengkap' => $row['nama_lengkap'] ?? null,
                    'email' => $row['email'] ?? null,
                    'kelas' => $row['kelas'] ?? null,
                    'jenis_kelamin' => $row['jenis_kelamin'] ?? null,
                    'alamat_lengkap' => $row['alamat'] ?? null,
                    'no_hp' => $row['no_hp'] ?? null,
                    'tempat_lahir' => $row['tempat_lahir'] ?? null,
                    'tanggal_lahir' => $row['tanggal_lahir'] ?? null,
                    'angkatan' => $row['angkatan'] ?? null,
                    'fakultas' => $row['fakultas'] ?? null,
                    'program_studi' => $row['program_studi'] ?? null,
                    'semester' => $row['semester'] ?? null,
                    'sks_lulus' => $row['sks_lulus'] ?? null,
                    'ipk' => $row['ipk'] ?? null,
                    'status_mahasiswa' => $row['status_mahasiswa'] ?? 'aktif',
                ], $existing);
                $existing ? $updated++ : $created++;
            } catch (\Throwable $e) {
                $skipped[] = 'Baris ' . ($index + 2) . ': ' . $e->getMessage();
            }
        }

        $message = "Import selesai. Baru: {$created}, diperbarui: {$updated}.";
        if ($skipped) $message .= ' Catatan: ' . implode(' ', array_slice($skipped, 0, 5));
        return back()->with($skipped ? 'error' : 'success', $message);
    }

    private function validated(Request $request, ?MahasiswaProfile $mahasiswa = null): array
    {
        return $request->validate([
            'nim' => ['required', 'string', 'max:50', Rule::unique('mahasiswa_profiles', 'nim')->ignore($mahasiswa?->id)],
            'nama_lengkap' => 'required|string|max:255',
            'email' => ['nullable', 'email', 'max:150'],
            'kelas_id' => 'nullable|exists:kelas,id',
            'jenis_kelamin' => 'nullable|in:Laki-laki,Perempuan',
            'alamat_lengkap' => 'nullable|string',
            'no_hp' => 'nullable|string|max:50',
            'tempat_lahir' => 'nullable|string|max:100',
            'tanggal_lahir' => 'nullable|date',
            'angkatan_id' => 'nullable|exists:angkatans,id',
            'fakultas_id' => 'nullable|exists:fakultas,id',
            'prodi_id' => 'nullable|exists:prodis,id',
            'semester' => 'nullable|integer|min:1',
            'sks_lulus' => 'nullable|integer|min:0',
            'ipk' => 'nullable|numeric|min:0|max:4',
            'status_mahasiswa' => 'nullable|in:aktif,cuti,lulus',
        ]);
    }

    private function saveMahasiswa(array $data, ?MahasiswaProfile $mahasiswa = null): MahasiswaProfile
    {
        $fakultasId = $data['fakultas_id'] ?? null;
        if (!$fakultasId && filled($data['fakultas'] ?? null)) {
            $fakultasId = Fakultas::firstOrCreate(['nama_fakultas' => $data['fakultas']])->id;
        }
        if (!$fakultasId) {
            $fakultasId = Fakultas::firstOrCreate(['nama_fakultas' => 'Fakultas Sains dan Teknologi'])->id;
        }

        $prodiId = $data['prodi_id'] ?? null;
        if (!$prodiId && filled($data['program_studi'] ?? null)) {
            $prodiId = Prodi::firstOrCreate(['nama_prodi' => $data['program_studi']], ['fakultas_id' => $fakultasId])->id;
        }

        $kelasId = $data['kelas_id'] ?? null;
        if (!$kelasId && filled($data['kelas'] ?? null)) {
            $kelasId = Kelas::firstOrCreate(['nama_kelas' => $data['kelas']], ['status' => 'aktif'])->id;
        }

        $angkatanId = $data['angkatan_id'] ?? null;
        if (!$angkatanId && filled($data['angkatan'] ?? null)) {
            $angkatanId = Angkatan::firstOrCreate(['tahun' => (int) $data['angkatan']], ['status' => 'aktif'])->id;
        }

        $userId = $mahasiswa?->user_id;
        if (filled($data['email'] ?? null)) {
            $user = User::where('email', $data['email'])->first();
            if ($user && $user->role !== 'mahasiswa') {
                throw new \RuntimeException('Email ' . $data['email'] . ' sudah digunakan oleh role lain.');
            }
            if (!$user) {
                $user = User::create([
                    'name' => $data['nama_lengkap'],
                    'email' => $data['email'],
                    'password' => Hash::make('12345678'),
                    'role' => 'mahasiswa',
                    'status' => 'aktif',
                ]);
            }
            $userId = $user->id;
        }

        $payload = [
            'user_id' => $userId,
            'nim' => $data['nim'],
            'nama_lengkap' => $data['nama_lengkap'],
            'email' => $data['email'] ?? null,
            'kelas_id' => $kelasId,
            'kelas' => $kelasId ? Kelas::find($kelasId)?->nama_kelas : ($data['kelas'] ?? null),
            'jenis_kelamin' => $data['jenis_kelamin'] ?? null,
            'alamat_lengkap' => $data['alamat_lengkap'] ?? null,
            'no_hp' => $data['no_hp'] ?? null,
            'tempat_lahir' => $data['tempat_lahir'] ?? null,
            'tanggal_lahir' => $data['tanggal_lahir'] ?? null,
            'angkatan_id' => $angkatanId,
            'angkatan' => $angkatanId ? Angkatan::find($angkatanId)?->tahun : ($data['angkatan'] ?? null),
            'fakultas_id' => $fakultasId,
            'prodi_id' => $prodiId,
            'semester' => $data['semester'] ?? null,
            'sks_lulus' => $data['sks_lulus'] ?? null,
            'ipk' => $data['ipk'] ?? null,
            'status_mahasiswa' => $data['status_mahasiswa'] ?? 'aktif',
        ];

        $mahasiswa = $mahasiswa ?: MahasiswaProfile::firstOrNew(['nim' => $data['nim']]);
        if ($mahasiswa->exists && $mahasiswa->user_id && $userId && (int) $mahasiswa->user_id !== (int) $userId) {
            throw new \RuntimeException('NIM ' . $data['nim'] . ' sudah terhubung dengan akun lain.');
        }
        $mahasiswa->fill($payload);
        $mahasiswa->syncProfileStatus();
        $mahasiswa->save();

        return $mahasiswa;
    }

    private function formData(): array
    {
        return [
            'fakultas' => Fakultas::orderBy('nama_fakultas')->get(),
            'prodis' => Prodi::orderBy('nama_prodi')->get(),
            'kelasOptions' => Kelas::where('status', 'aktif')->orderBy('nama_kelas')->get(),
            'angkatanOptions' => Angkatan::where('status', 'aktif')->orderByDesc('tahun')->get(),
        ];
    }

    private function headers(): array
    {
        return ['nim','nama_lengkap','email','kelas','jenis_kelamin','alamat','no_hp','tempat_lahir','tanggal_lahir','angkatan','fakultas','program_studi','semester','sks_lulus','ipk','status_mahasiswa'];
    }
}
'@

Write-Utf8NoBom 'app\Http\Controllers\Admin\MasterDosenController.php' @'
<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ImportsTabularData;
use App\Http\Controllers\Controller;
use App\Models\Dosen;
use App\Models\Prodi;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class MasterDosenController extends Controller
{
    use ImportsTabularData;

    public function index(Request $request)
    {
        $dosens = Dosen::with(['user', 'prodi'])
            ->when($request->search, fn($q, $s) => $q->where(function ($sub) use ($s) {
                $sub->where('nidn', 'like', "%{$s}%")->orWhere('nama_dosen', 'like', "%{$s}%")->orWhere('email_dosen', 'like', "%{$s}%");
            }))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.master.dosen.index', compact('dosens'));
    }

    public function create()
    {
        return view('admin.master.dosen.form', $this->formData());
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $this->saveDosen($data);
        return redirect()->route('admin.master.dosen.index')->with('success', 'Data dosen berhasil disimpan.');
    }

    public function show(Dosen $dosen)
    {
        $dosen->load(['user', 'prodi']);
        return view('admin.master.dosen.show', compact('dosen'));
    }

    public function edit(Dosen $dosen)
    {
        return view('admin.master.dosen.form', array_merge($this->formData(), compact('dosen')));
    }

    public function update(Request $request, Dosen $dosen)
    {
        $data = $this->validated($request, $dosen);
        $this->saveDosen($data, $dosen);
        return redirect()->route('admin.master.dosen.index')->with('success', 'Data dosen berhasil diperbarui.');
    }

    public function destroy(Dosen $dosen)
    {
        if ($dosen->bimbingans()->exists()) {
            $dosen->update(['status_dosen' => 'nonaktif']);
            return back()->with('error', 'Dosen sudah punya riwayat bimbingan. Data tidak dihapus dan status dosen dinonaktifkan.');
        }
        $dosen->delete();
        return back()->with('success', 'Data dosen berhasil dihapus.');
    }

    public function template()
    {
        return $this->csvResponse('template_import_dosen.csv', $this->headers(), []);
    }

    public function export()
    {
        $rows = Dosen::with('prodi')->orderBy('nama_dosen')->get()->map(fn($d) => [
            $d->nidn, $d->nama_dosen, $d->email_dosen, $d->prodi?->nama_prodi, $d->no_hp, $d->status_dosen,
        ]);
        return $this->csvResponse('data_dosen.csv', $this->headers(), $rows);
    }

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt,xlsx,xls|max:10240']);
        $rows = $this->readRows($request->file('file')->getRealPath());
        $created = 0;
        $updated = 0;
        $skipped = [];

        foreach ($rows as $index => $row) {
            if (blank($row['nidn_nip'] ?? null)) {
                $skipped[] = 'Baris ' . ($index + 2) . ': NIDN/NIP kosong.';
                continue;
            }
            try {
                $existing = Dosen::where('nidn', $row['nidn_nip'])->first();
                $this->saveDosen([
                    'nidn' => $row['nidn_nip'],
                    'nama_dosen' => $row['nama_dosen'] ?? null,
                    'email_dosen' => $row['email'] ?? null,
                    'program_studi' => $row['program_studi'] ?? null,
                    'no_hp' => $row['no_hp'] ?? null,
                    'status_dosen' => $row['status_dosen'] ?? 'aktif',
                ], $existing);
                $existing ? $updated++ : $created++;
            } catch (\Throwable $e) {
                $skipped[] = 'Baris ' . ($index + 2) . ': ' . $e->getMessage();
            }
        }

        $message = "Import selesai. Baru: {$created}, diperbarui: {$updated}.";
        if ($skipped) $message .= ' Catatan: ' . implode(' ', array_slice($skipped, 0, 5));
        return back()->with($skipped ? 'error' : 'success', $message);
    }

    private function validated(Request $request, ?Dosen $dosen = null): array
    {
        return $request->validate([
            'nidn' => ['required', 'string', 'max:80', Rule::unique('dosens', 'nidn')->ignore($dosen?->id)],
            'nama_dosen' => 'required|string|max:255',
            'email_dosen' => 'nullable|email|max:150',
            'prodi_id' => 'nullable|exists:prodis,id',
            'no_hp' => 'nullable|string|max:50',
            'status_dosen' => 'nullable|in:aktif,nonaktif',
        ]);
    }

    private function saveDosen(array $data, ?Dosen $dosen = null): Dosen
    {
        $prodiId = $data['prodi_id'] ?? null;
        if (!$prodiId && filled($data['program_studi'] ?? null)) {
            $prodiId = Prodi::firstOrCreate(['nama_prodi' => $data['program_studi']])->id;
        }

        $userId = $dosen?->user_id;
        if (filled($data['email_dosen'] ?? null)) {
            $user = User::where('email', $data['email_dosen'])->first();
            if ($user && $user->role !== 'dosen') {
                throw new \RuntimeException('Email ' . $data['email_dosen'] . ' sudah digunakan oleh role lain.');
            }
            if (!$user) {
                $user = User::create([
                    'name' => $data['nama_dosen'],
                    'email' => $data['email_dosen'],
                    'password' => Hash::make('12345678'),
                    'role' => 'dosen',
                    'status' => 'aktif',
                ]);
            }
            $userId = $user->id;
        }

        $dosen = $dosen ?: Dosen::firstOrNew(['nidn' => $data['nidn']]);
        if ($dosen->exists && $dosen->user_id && $userId && (int) $dosen->user_id !== (int) $userId) {
            throw new \RuntimeException('NIDN/NIP ' . $data['nidn'] . ' sudah terhubung dengan akun lain.');
        }
        $dosen->fill([
            'user_id' => $userId,
            'nidn' => $data['nidn'],
            'nama_dosen' => $data['nama_dosen'],
            'prodi_id' => $prodiId,
            'no_hp' => $data['no_hp'] ?? null,
            'email_dosen' => $data['email_dosen'] ?? null,
            'status_dosen' => $data['status_dosen'] ?? 'aktif',
        ]);
        $dosen->syncProfileStatus();
        $dosen->save();

        return $dosen;
    }

    private function formData(): array
    {
        return ['prodis' => Prodi::orderBy('nama_prodi')->get()];
    }

    private function headers(): array
    {
        return ['nidn_nip','nama_dosen','email','program_studi','no_hp','status_dosen'];
    }
}
'@

Write-Utf8NoBom 'app\Http\Controllers\Admin\MasterPembimbingLapanganController.php' @'
<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Mitra;
use App\Models\PembimbingLapangan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class MasterPembimbingLapanganController extends Controller
{
    public function index(Request $request)
    {
        $pembimbings = PembimbingLapangan::with(['user', 'mitra'])
            ->when($request->search, fn($q, $s) => $q->where(function ($sub) use ($s) {
                $sub->where('nama', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")->orWhere('no_hp', 'like', "%{$s}%");
            }))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.master.pembimbing.index', compact('pembimbings'));
    }

    public function create()
    {
        return view('admin.master.pembimbing.form', $this->formData());
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $this->savePembimbing($data);
        return redirect()->route('admin.master.pembimbing.index')->with('success', 'Data pembimbing lapangan berhasil disimpan.');
    }

    public function show(PembimbingLapangan $pembimbing)
    {
        $pembimbing->load(['user', 'mitra']);
        return view('admin.master.pembimbing.show', compact('pembimbing'));
    }

    public function edit(PembimbingLapangan $pembimbing)
    {
        return view('admin.master.pembimbing.form', array_merge($this->formData(), compact('pembimbing')));
    }

    public function update(Request $request, PembimbingLapangan $pembimbing)
    {
        $data = $this->validated($request, $pembimbing);
        $this->savePembimbing($data, $pembimbing);
        return redirect()->route('admin.master.pembimbing.index')->with('success', 'Data pembimbing lapangan berhasil diperbarui.');
    }

    public function destroy(PembimbingLapangan $pembimbing)
    {
        if ($pembimbing->pengajuan_id) {
            $pembimbing->update(['status' => 'nonaktif']);
            return back()->with('error', 'Pembimbing sudah terhubung riwayat pengajuan. Data tidak dihapus dan status dinonaktifkan.');
        }
        $pembimbing->delete();
        return back()->with('success', 'Data pembimbing lapangan berhasil dihapus.');
    }

    private function validated(Request $request, ?PembimbingLapangan $pembimbing = null): array
    {
        return $request->validate([
            'nama' => 'required|string|max:150',
            'email' => ['required', 'email', 'max:150', Rule::unique('pembimbing_lapangans', 'email')->ignore($pembimbing?->id)],
            'no_hp' => 'nullable|string|max:100',
            'jabatan' => 'nullable|string|max:150',
            'mitra_id' => 'required|exists:mitras,id',
            'status' => 'required|in:aktif,nonaktif',
            'catatan' => 'nullable|string',
            'buat_akun' => 'nullable|boolean',
            'password' => 'nullable|min:6',
        ]);
    }

    private function savePembimbing(array $data, ?PembimbingLapangan $pembimbing = null): PembimbingLapangan
    {
        $mitra = Mitra::findOrFail($data['mitra_id']);
        $userId = $pembimbing?->user_id;
        $shouldCreateAccount = ($data['buat_akun'] ?? false) || filled($data['password'] ?? null);

        $user = User::where('email', $data['email'])->first();
        if ($user && !in_array($user->role, ['pembimbing_lapangan', 'mitra'], true)) {
            throw new \RuntimeException('Email pembimbing sudah digunakan oleh role lain.');
        }
        if (!$user && $shouldCreateAccount) {
            $user = User::create([
                'name' => $data['nama'],
                'email' => $data['email'],
                'password' => Hash::make($data['password'] ?: '12345678'),
                'role' => 'pembimbing_lapangan',
                'status' => $data['status'],
            ]);
        }
        if ($user) {
            $user->update([
                'name' => $data['nama'],
                'status' => $data['status'],
            ]);
            if (filled($data['password'] ?? null)) {
                $user->update(['password' => Hash::make($data['password'])]);
            }
            $userId = $user->id;
        }

        $pembimbing = $pembimbing ?: PembimbingLapangan::firstOrNew(['email' => $data['email']]);
        $pembimbing->fill([
            'user_id' => $userId,
            'mitra_id' => $mitra->id,
            'nama' => $data['nama'],
            'jabatan' => $data['jabatan'] ?? null,
            'email' => $data['email'],
            'no_hp' => $data['no_hp'] ?? null,
            'instansi' => $mitra->nama_instansi,
            'status' => $data['status'],
            'catatan' => $data['catatan'] ?? null,
        ]);
        $pembimbing->syncProfileStatus();
        $pembimbing->save();

        return $pembimbing;
    }

    private function formData(): array
    {
        return ['mitras' => Mitra::orderBy('nama_instansi')->get()];
    }
}
'@

Write-Utf8NoBom 'app\Http\Controllers\Admin\UserController.php' @'
<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Dosen;
use App\Models\MahasiswaProfile;
use App\Models\Mitra;
use App\Models\MitraUser;
use App\Models\PembimbingLapangan;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    private array $roles = ['superadmin', 'admin', 'mahasiswa', 'dosen', 'mitra', 'pembimbing_lapangan'];

    public function index()
    {
        $users = User::query()
            ->select(['id', 'name', 'email', 'role', 'status', 'created_at'])
            ->when(request('role'), fn($q, $role) => $q->where('role', $role))
            ->latest()
            ->paginate(15);
        return view('admin.users.index', compact('users'));
    }

    public function create()
    {
        $mitras = Mitra::orderBy('nama_instansi')->get();
        return view('admin.users.create', ['roles' => $this->roles, 'mitras' => $mitras]);
    }

    public function store(Request $request)
    {
        $data = $this->validateUser($request);

        if (($data['role'] ?? null) === 'mahasiswa' && MahasiswaProfile::where('nim', $request->nim)->whereNotNull('user_id')->exists()) {
            return back()->withErrors(['nim' => 'NIM sudah terhubung dengan akun lain.'])->withInput();
        }
        if (($data['role'] ?? null) === 'dosen' && Dosen::where('nidn', $request->nidn)->whereNotNull('user_id')->exists()) {
            return back()->withErrors(['nidn' => 'NIDN/NIP sudah terhubung dengan akun lain.'])->withInput();
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
            'status' => 'aktif',
        ]);

        $this->linkRoleMaster($request, $user);

        return redirect()->route('admin.users.index')->with('success', 'Akun user berhasil dibuat dan dihubungkan ke master data jika diperlukan.');
    }

    public function edit(User $user)
    {
        $user->load(['mahasiswaProfile', 'dosen', 'mitraUser.mitra', 'pembimbingLapangan.mitra']);
        $mitras = Mitra::orderBy('nama_instansi')->get();
        return view('admin.users.edit', ['user' => $user, 'roles' => $this->roles, 'mitras' => $mitras]);
    }

    public function show(User $user)
    {
        $user->load(['mahasiswaProfile.fakultas', 'mahasiswaProfile.prodi', 'mahasiswaProfile.kelasMaster', 'mahasiswaProfile.angkatanMaster', 'dosen.prodi', 'mitraUser.mitra', 'pembimbingLapangan.mitra']);
        return view('admin.users.show', compact('user'));
    }

    public function update(Request $request, User $user)
    {
        $data = $this->validateUser($request, $user);

        $user->update([
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'status' => $data['status'],
        ]);
        if ($request->filled('password')) {
            $user->update(['password' => Hash::make($request->password)]);
        }

        $this->linkRoleMaster($request, $user);

        return redirect()->route('admin.users.show', $user)->with('success', 'Akun user berhasil diperbarui.');
    }

    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return redirect()->route('admin.users.index')->with('error', 'Akun yang sedang digunakan tidak bisa dinonaktifkan atau dihapus dari halaman ini.');
        }

        if (request('action') === 'delete') {
            if ($this->hasHistoricalData($user)) {
                return redirect()->route('admin.users.index')
                    ->with('error', 'User tidak bisa dihapus karena sudah memiliki data terkait. Gunakan Nonaktifkan agar data historis tetap aman.');
            }

            try {
                $user->mahasiswaProfile?->update(['user_id' => null]);
                $user->dosen?->update(['user_id' => null]);
                $user->pembimbingLapangan?->update(['user_id' => null]);
                $user->mitraUser?->delete();
                $user->notifikasis()->delete();
                $user->delete();
                return redirect()->route('admin.users.index')->with('success', 'User berhasil dihapus.');
            } catch (QueryException $e) {
                return redirect()->route('admin.users.index')->with('error', 'User tidak dapat dihapus karena masih dipakai data lain. Gunakan Nonaktifkan.');
            }
        }

        $user->update(['status' => 'nonaktif']);
        $user->dosen?->update(['status_dosen' => 'nonaktif']);
        $user->pembimbingLapangan?->update(['status' => 'nonaktif']);

        return redirect()->route('admin.users.show', $user)->with('success', 'User berhasil dinonaktifkan tanpa menghapus data historis.');
    }

    private function validateUser(Request $request, ?User $user = null): array
    {
        $roleRules = implode(',', $this->roles);
        $rules = [
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'min:6'],
            'role' => "required|in:{$roleRules}",
            'status' => $user ? 'required|in:aktif,nonaktif' : 'nullable',
            'nim' => 'required_if:role,mahasiswa|nullable|string|max:50',
            'nama_lengkap' => 'required_if:role,mahasiswa|nullable|string|max:255',
            'nidn' => 'required_if:role,dosen|nullable|string|max:80',
            'nama_dosen' => 'required_if:role,dosen|nullable|string|max:255',
            'nama_pembimbing' => 'required_if:role,pembimbing_lapangan|nullable|string|max:150',
            'no_hp_pembimbing' => 'nullable|string|max:100',
            'jabatan_pembimbing' => 'nullable|string|max:150',
            'mitra_id' => 'required_if:role,pembimbing_lapangan|nullable|exists:mitras,id',
            'jabatan_mitra' => 'nullable|string|max:150',
        ];

        return $request->validate($rules);
    }

    private function linkRoleMaster(Request $request, User $user): void
    {
        if ($user->role === 'mahasiswa') {
            $profile = MahasiswaProfile::firstOrNew(['nim' => $request->nim]);
            if ($profile->exists && $profile->user_id && (int) $profile->user_id !== (int) $user->id) {
                throw new \RuntimeException('NIM sudah terhubung dengan akun lain.');
            }
            $profile->fill([
                'user_id' => $user->id,
                'nim' => $request->nim,
                'nama_lengkap' => $request->nama_lengkap ?: $user->name,
                'email' => $user->email,
                'status_mahasiswa' => $profile->status_mahasiswa ?: 'aktif',
            ]);
            $profile->syncProfileStatus();
            $profile->save();
            return;
        }

        if ($user->role === 'dosen') {
            $dosen = Dosen::firstOrNew(['nidn' => $request->nidn]);
            if ($dosen->exists && $dosen->user_id && (int) $dosen->user_id !== (int) $user->id) {
                throw new \RuntimeException('NIDN/NIP sudah terhubung dengan akun lain.');
            }
            $dosen->fill([
                'user_id' => $user->id,
                'nidn' => $request->nidn,
                'nama_dosen' => $request->nama_dosen ?: $user->name,
                'email_dosen' => $user->email,
                'status_dosen' => $dosen->status_dosen ?: 'aktif',
            ]);
            $dosen->syncProfileStatus();
            $dosen->save();
            return;
        }

        if ($user->role === 'pembimbing_lapangan') {
            $mitra = Mitra::find($request->mitra_id);
            $pembimbing = PembimbingLapangan::firstOrNew(['email' => $user->email]);
            $pembimbing->fill([
                'user_id' => $user->id,
                'mitra_id' => $request->mitra_id,
                'nama' => $request->nama_pembimbing ?: $user->name,
                'email' => $user->email,
                'no_hp' => $request->no_hp_pembimbing,
                'jabatan' => $request->jabatan_pembimbing,
                'instansi' => $mitra?->nama_instansi,
                'status' => $user->status,
            ]);
            $pembimbing->syncProfileStatus();
            $pembimbing->save();
            return;
        }

        if ($user->role === 'mitra' && $request->filled('mitra_id')) {
            MitraUser::updateOrCreate(
                ['user_id' => $user->id],
                ['mitra_id' => $request->mitra_id, 'jabatan' => $request->jabatan_mitra]
            );
        }
    }

    private function hasHistoricalData(User $user): bool
    {
        $user->loadMissing(['mahasiswaProfile', 'dosen', 'mitraUser.mitra', 'pembimbingLapangan']);
        if ($user->mahasiswaProfile && $user->mahasiswaProfile->pengajuans()->exists()) return true;
        if ($user->dosen && $user->dosen->bimbingans()->exists()) return true;
        if ($user->mitraUser?->mitra && $user->mitraUser->mitra->pengajuans()->exists()) return true;
        if ($user->pembimbingLapangan?->pengajuan_id) return true;
        return false;
    }
}
'@

Write-Utf8NoBom 'resources\views\admin\users\create.blade.php' @'
@extends('layouts.app')
@section('title', 'Tambah User')
@section('page-title', 'Tambah User')

@section('content')
@php($selectedRole = old('role', request('role')))
@include('partials.alerts')
<div class="card p-4">
    <form action="{{ route('admin.users.store') }}" method="POST">
        @csrf
        <div class="row g-3">
            <div class="col-12">
                <h6 class="fw-bold mb-0">Form Dasar Akun Login</h6>
                <div class="text-muted small">Manajemen User hanya membuat akun dasar. Detail lengkap dikelola di menu Master Data.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Role <span class="text-danger">*</span></label>
                <select name="role" class="form-select" required>
                    <option value="">-- Pilih Role --</option>
                    @foreach(['mahasiswa' => 'Mahasiswa', 'dosen' => 'Dosen', 'pembimbing_lapangan' => 'Pembimbing Lapangan', 'admin' => 'Admin', 'superadmin' => 'Superadmin'] as $value => $label)
                        <option value="{{ $value }}" @selected($selectedRole === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-6 role-section" data-role-section="mahasiswa">
                <label class="form-label fw-semibold">NIM <span class="text-danger">*</span></label>
                <input type="text" name="nim" class="form-control" value="{{ old('nim') }}">
                @error('nim')<div class="text-danger small">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6 role-section" data-role-section="mahasiswa">
                <label class="form-label fw-semibold">Nama Lengkap <span class="text-danger">*</span></label>
                <input type="text" name="nama_lengkap" class="form-control" value="{{ old('nama_lengkap') }}">
                @error('nama_lengkap')<div class="text-danger small">{{ $message }}</div>@enderror
            </div>

            <div class="col-md-6 role-section" data-role-section="dosen">
                <label class="form-label fw-semibold">NIDN/NIP <span class="text-danger">*</span></label>
                <input type="text" name="nidn" class="form-control" value="{{ old('nidn') }}">
                @error('nidn')<div class="text-danger small">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6 role-section" data-role-section="dosen">
                <label class="form-label fw-semibold">Nama Dosen <span class="text-danger">*</span></label>
                <input type="text" name="nama_dosen" class="form-control" value="{{ old('nama_dosen') }}">
                @error('nama_dosen')<div class="text-danger small">{{ $message }}</div>@enderror
            </div>

            <div class="col-md-6 role-section" data-role-section="pembimbing_lapangan">
                <label class="form-label fw-semibold">Nama Pembimbing Lapangan <span class="text-danger">*</span></label>
                <input type="text" name="nama_pembimbing" class="form-control" value="{{ old('nama_pembimbing') }}">
            </div>
            <div class="col-md-6 role-section" data-role-section="pembimbing_lapangan">
                <label class="form-label fw-semibold">Mitra/Instansi <span class="text-danger">*</span></label>
                <select name="mitra_id" class="form-select">
                    <option value="">-- Pilih Mitra/Instansi --</option>
                    @foreach($mitras as $mitra)
                        <option value="{{ $mitra->id }}" @selected(old('mitra_id') == $mitra->id)>{{ $mitra->nama_instansi }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-6 role-section" data-role-section="pembimbing_lapangan">
                <label class="form-label fw-semibold">No HP</label>
                <input type="text" name="no_hp_pembimbing" class="form-control" value="{{ old('no_hp_pembimbing') }}">
            </div>
            <div class="col-md-6 role-section" data-role-section="pembimbing_lapangan">
                <label class="form-label fw-semibold">Jabatan</label>
                <input type="text" name="jabatan_pembimbing" class="form-control" value="{{ old('jabatan_pembimbing') }}">
            </div>

            <div class="col-md-6">
                <label class="form-label fw-semibold">Nama Akun <span class="text-danger">*</span></label>
                <input type="text" name="name" class="form-control" value="{{ old('name') }}" required>
                @error('name')<div class="text-danger small">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Email <span class="text-danger">*</span></label>
                <input type="email" name="email" class="form-control" value="{{ old('email') }}" required>
                @error('email')<div class="text-danger small">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Password <span class="text-danger">*</span></label>
                <input type="password" name="password" class="form-control" required>
                @error('password')<div class="text-danger small">{{ $message }}</div>@enderror
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary px-4">Simpan</button>
                <a href="{{ route('admin.users.index') }}" class="btn btn-secondary px-4">Kembali</a>
            </div>
        </div>
    </form>
</div>
@include('admin.users._role-script')
@endsection
'@

Write-Utf8NoBom 'resources\views\admin\users\edit.blade.php' @'
@extends('layouts.app')
@section('title', 'Edit User')
@section('page-title', 'Edit User')

@section('content')
@php
    $selectedRole = old('role', $user->role);
    $m = $user->mahasiswaProfile;
    $d = $user->dosen;
    $p = $user->pembimbingLapangan;
@endphp
@include('partials.alerts')
<div class="card p-4">
    <form action="{{ route('admin.users.update', $user) }}" method="POST">
        @csrf
        @method('PUT')
        <div class="row g-3">
            <div class="col-12">
                <h6 class="fw-bold mb-0">Form Dasar Akun Login</h6>
                <div class="text-muted small">Detail lengkap tetap dikelola di menu Master Data.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Role <span class="text-danger">*</span></label>
                <select name="role" class="form-select" required>
                    @foreach(['mahasiswa' => 'Mahasiswa', 'dosen' => 'Dosen', 'pembimbing_lapangan' => 'Pembimbing Lapangan', 'mitra' => 'Mitra Legacy', 'admin' => 'Admin', 'superadmin' => 'Superadmin'] as $value => $label)
                        <option value="{{ $value }}" @selected($selectedRole === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Status Akun <span class="text-danger">*</span></label>
                <select name="status" class="form-select" required>
                    <option value="aktif" @selected(old('status', $user->status) === 'aktif')>Aktif</option>
                    <option value="nonaktif" @selected(old('status', $user->status) === 'nonaktif')>Nonaktif</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Password Baru</label>
                <input type="password" name="password" class="form-control" placeholder="Kosongkan jika tidak diubah">
            </div>

            <div class="col-md-6 role-section" data-role-section="mahasiswa">
                <label class="form-label fw-semibold">NIM <span class="text-danger">*</span></label>
                <input type="text" name="nim" class="form-control" value="{{ old('nim', $m->nim ?? '') }}">
            </div>
            <div class="col-md-6 role-section" data-role-section="mahasiswa">
                <label class="form-label fw-semibold">Nama Lengkap <span class="text-danger">*</span></label>
                <input type="text" name="nama_lengkap" class="form-control" value="{{ old('nama_lengkap', $m->nama_lengkap ?? $user->name) }}">
            </div>

            <div class="col-md-6 role-section" data-role-section="dosen">
                <label class="form-label fw-semibold">NIDN/NIP <span class="text-danger">*</span></label>
                <input type="text" name="nidn" class="form-control" value="{{ old('nidn', $d->nidn ?? '') }}">
            </div>
            <div class="col-md-6 role-section" data-role-section="dosen">
                <label class="form-label fw-semibold">Nama Dosen <span class="text-danger">*</span></label>
                <input type="text" name="nama_dosen" class="form-control" value="{{ old('nama_dosen', $d->nama_dosen ?? $user->name) }}">
            </div>

            <div class="col-md-6 role-section" data-role-section="pembimbing_lapangan">
                <label class="form-label fw-semibold">Nama Pembimbing Lapangan <span class="text-danger">*</span></label>
                <input type="text" name="nama_pembimbing" class="form-control" value="{{ old('nama_pembimbing', $p->nama ?? $user->name) }}">
            </div>
            <div class="col-md-6 role-section" data-role-section="pembimbing_lapangan">
                <label class="form-label fw-semibold">Mitra/Instansi <span class="text-danger">*</span></label>
                <select name="mitra_id" class="form-select">
                    <option value="">-- Pilih Mitra/Instansi --</option>
                    @foreach($mitras as $mitra)
                        <option value="{{ $mitra->id }}" @selected(old('mitra_id', $p->mitra_id ?? '') == $mitra->id)>{{ $mitra->nama_instansi }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-6 role-section" data-role-section="pembimbing_lapangan">
                <label class="form-label fw-semibold">No HP</label>
                <input type="text" name="no_hp_pembimbing" class="form-control" value="{{ old('no_hp_pembimbing', $p->no_hp ?? '') }}">
            </div>
            <div class="col-md-6 role-section" data-role-section="pembimbing_lapangan">
                <label class="form-label fw-semibold">Jabatan</label>
                <input type="text" name="jabatan_pembimbing" class="form-control" value="{{ old('jabatan_pembimbing', $p->jabatan ?? '') }}">
            </div>

            <div class="col-md-6">
                <label class="form-label fw-semibold">Nama Akun <span class="text-danger">*</span></label>
                <input type="text" name="name" class="form-control" value="{{ old('name', $user->name) }}" required>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Email <span class="text-danger">*</span></label>
                <input type="email" name="email" class="form-control" value="{{ old('email', $user->email) }}" required>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary px-4">Update</button>
                <a href="{{ route('admin.users.show', $user) }}" class="btn btn-secondary px-4">Kembali</a>
            </div>
        </div>
    </form>
</div>
@include('admin.users._role-script')
@endsection
'@

Write-Utf8NoBom 'resources\views\admin\users\_role-script.blade.php' @'
@push('scripts')
<script>
const roleSelect = document.querySelector('select[name="role"]');
const sections = document.querySelectorAll('[data-role-section]');
function syncRoleSections() {
    const role = roleSelect.value;
    sections.forEach((section) => {
        const allowed = section.dataset.roleSection.split(' ').includes(role);
        section.classList.toggle('d-none', !allowed);
        section.querySelectorAll('input, select, textarea').forEach((field) => field.disabled = !allowed);
    });
}
roleSelect?.addEventListener('change', syncRoleSections);
syncRoleSections();
</script>
@endpush
'@

Write-Utf8NoBom 'resources\views\admin\users\show.blade.php' @'
@extends('layouts.app')
@section('title', 'Detail User')
@section('page-title', 'Detail User')

@section('content')
@php
    $roleLabel = str_replace('_', ' ', $user->role);
    $items = [
        'Nama Akun' => $user->name,
        'Email Akun' => $user->email,
        'Role' => ucwords($roleLabel),
        'Status Akun' => $user->status,
    ];
    $masterUrl = null;
    if ($user->role === 'mahasiswa' && $user->mahasiswaProfile) {
        $m = $user->mahasiswaProfile;
        $masterUrl = route('admin.master.mahasiswa.show', $m);
        $items += [
            'NIM' => $m->nim,
            'Nama Lengkap' => $m->nama_lengkap,
            'Profile Master' => $m->profile_status,
        ];
    }
    if ($user->role === 'dosen' && $user->dosen) {
        $d = $user->dosen;
        $masterUrl = route('admin.master.dosen.show', $d);
        $items += [
            'NIDN/NIP' => $d->nidn,
            'Nama Dosen' => $d->nama_dosen,
            'Profile Master' => $d->profile_status,
        ];
    }
    if ($user->role === 'pembimbing_lapangan' && $user->pembimbingLapangan) {
        $p = $user->pembimbingLapangan;
        $masterUrl = route('admin.master.pembimbing.show', $p);
        $items += [
            'Nama Pembimbing' => $p->nama,
            'Mitra/Instansi' => $p->mitra?->nama_instansi,
            'No HP' => $p->no_hp,
            'Jabatan' => $p->jabatan,
            'Profile Master' => $p->profile_status,
        ];
    }
    if ($user->role === 'mitra' && $user->mitraUser?->mitra) {
        $items += [
            'Mitra Legacy' => $user->mitraUser->mitra->nama_instansi,
            'Jabatan User Mitra' => $user->mitraUser->jabatan,
        ];
    }
@endphp
@include('partials.alerts')
<div class="card p-4">
    <div class="d-flex justify-content-between mb-3">
        <h6 class="fw-bold mb-0">Detail Akun User</h6>
        <div class="d-flex gap-2">
            @if($masterUrl)
                <a href="{{ $masterUrl }}" class="btn btn-sm btn-outline-primary">Lihat Master Data</a>
            @endif
            <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-sm btn-outline-warning">Edit Akun</a>
            <form action="{{ route('admin.users.destroy', $user) }}" method="POST">
                @csrf @method('DELETE')
                <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Nonaktifkan user ini? Data historis tidak akan dihapus.')">Nonaktifkan</button>
            </form>
        </div>
    </div>
    <div class="row">
        @foreach($items as $label => $value)
            <div class="col-md-6 mb-3">
                <div class="text-muted small">{{ $label }}</div>
                <div class="fw-semibold">{{ filled($value) ? $value : '-' }}</div>
            </div>
        @endforeach
    </div>
    <a href="{{ route('admin.users.index') }}" class="btn btn-secondary px-4">Kembali</a>
</div>
@endsection
'@

Write-Utf8NoBom 'resources\views\partials\alerts.blade.php' @'
@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="alert alert-danger">{{ session('error') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger">
        <div class="fw-bold mb-2">Data belum bisa disimpan. Periksa pesan berikut:</div>
        <ul class="mb-0">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
'@

Write-Utf8NoBom 'resources\views\admin\master\index.blade.php' @'
@extends('layouts.app')
@section('title', 'Master Data')
@section('page-title', 'Master Data')

@section('content')
@include('partials.alerts')
<div class="row g-3">
    @foreach($cards as $card)
        <div class="col-md-4">
            <a href="{{ $card['route'] }}" class="text-decoration-none text-dark">
                <div class="card p-4 h-100">
                    <div class="text-muted small">{{ $card['label'] }}</div>
                    <div class="d-flex justify-content-between align-items-center mt-2">
                        <div class="fs-3 fw-bold">{{ $card['count'] }}</div>
                        <i class="bi bi-database fs-3 text-primary"></i>
                    </div>
                </div>
            </a>
        </div>
    @endforeach
</div>
@endsection
'@

Write-Utf8NoBom 'resources\views\admin\master\reference.blade.php' @'
@extends('layouts.app')
@section('title', $config['title'])
@section('page-title', $config['title'])

@section('content')
@include('partials.alerts')
<div class="card p-4 mb-3">
    <form action="{{ route('admin.master.reference.store', $type) }}" method="POST" class="row g-3 align-items-end">
        @csrf
        <div class="col-md-4">
            <label class="form-label fw-semibold">{{ $config['title'] }}</label>
            <input type="{{ $type === 'angkatan' ? 'number' : 'text' }}" name="{{ $field }}" class="form-control" required>
        </div>
        @if($type === 'program-studi')
            <div class="col-md-4">
                <label class="form-label fw-semibold">Fakultas</label>
                <select name="fakultas_id" class="form-select">
                    <option value="">-- Pilih Fakultas --</option>
                    @foreach($fakultas as $f)
                        <option value="{{ $f->id }}">{{ $f->nama_fakultas }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        @if(in_array($type, ['kelas', 'angkatan'], true))
            <div class="col-md-3">
                <label class="form-label fw-semibold">Status</label>
                <select name="status" class="form-select">
                    <option value="aktif">Aktif</option>
                    <option value="nonaktif">Nonaktif</option>
                </select>
            </div>
        @endif
        <div class="col-md-2">
            <button class="btn btn-primary w-100">Tambah</button>
        </div>
    </form>
</div>
<div class="card p-4">
    <div class="d-flex justify-content-between mb-3">
        <h6 class="fw-bold mb-0">Daftar {{ $config['title'] }}</h6>
        <a href="{{ route('admin.master.index') }}" class="btn btn-sm btn-secondary">Kembali</a>
    </div>
    <table class="table table-hover">
        <thead class="table-light">
            <tr>
                <th>Nama/Data</th>
                @if($type === 'program-studi')<th>Fakultas</th>@endif
                @if(in_array($type, ['kelas', 'angkatan'], true))<th>Status</th>@endif
                <th width="320">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $item)
                <tr>
                    <form action="{{ route('admin.master.reference.update', [$type, $item->id]) }}" method="POST">
                        @csrf @method('PUT')
                        <td><input type="{{ $type === 'angkatan' ? 'number' : 'text' }}" name="{{ $field }}" class="form-control form-control-sm" value="{{ $item->{$field} }}" required></td>
                        @if($type === 'program-studi')
                            <td>
                                <select name="fakultas_id" class="form-select form-select-sm">
                                    <option value="">-</option>
                                    @foreach($fakultas as $f)
                                        <option value="{{ $f->id }}" @selected($item->fakultas_id == $f->id)>{{ $f->nama_fakultas }}</option>
                                    @endforeach
                                </select>
                            </td>
                        @endif
                        @if(in_array($type, ['kelas', 'angkatan'], true))
                            <td>
                                <select name="status" class="form-select form-select-sm">
                                    <option value="aktif" @selected($item->status === 'aktif')>Aktif</option>
                                    <option value="nonaktif" @selected($item->status === 'nonaktif')>Nonaktif</option>
                                </select>
                            </td>
                        @endif
                        <td>
                            <button class="btn btn-sm btn-outline-primary">Simpan</button>
                    </form>
                            <form action="{{ route('admin.master.reference.destroy', [$type, $item->id]) }}" method="POST" class="d-inline">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Hapus/nonaktifkan data ini?')">Hapus</button>
                            </form>
                        </td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-center text-muted">Belum ada data.</td></tr>
            @endforelse
        </tbody>
    </table>
    {{ $items->links() }}
</div>
@endsection
'@

Write-Utf8NoBom 'resources\views\admin\master\mahasiswa\index.blade.php' @'
@extends('layouts.app')
@section('title', 'Data Mahasiswa')
@section('page-title', 'Data Mahasiswa')
@section('content')
@include('partials.alerts')
<div class="card p-4 mb-3">
    <div class="d-flex justify-content-between flex-wrap gap-2">
        <form method="GET" class="d-flex gap-2">
            <input type="text" name="search" class="form-control" placeholder="Cari NIM/nama/email..." value="{{ request('search') }}">
            <button class="btn btn-outline-primary">Cari</button>
        </form>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.master.mahasiswa.create') }}" class="btn btn-primary">Tambah Manual</a>
            <a href="{{ route('admin.master.mahasiswa.template') }}" class="btn btn-outline-secondary">Template CSV</a>
            <a href="{{ route('admin.master.mahasiswa.export') }}" class="btn btn-outline-success">Export CSV</a>
        </div>
    </div>
    <form action="{{ route('admin.master.mahasiswa.import') }}" method="POST" enctype="multipart/form-data" class="row g-2 mt-3">
        @csrf
        <div class="col-md-8"><input type="file" name="file" class="form-control" accept=".csv,.txt,.xlsx,.xls" required></div>
        <div class="col-md-4"><button class="btn btn-outline-primary w-100">Import CSV/Excel</button></div>
    </form>
</div>
<div class="card p-4">
    <table class="table table-hover">
        <thead class="table-light"><tr><th>NIM</th><th>Nama</th><th>Email</th><th>Program Studi</th><th>Kelas</th><th>Angkatan</th><th>Status Profile</th><th>Aksi</th></tr></thead>
        <tbody>
            @forelse($mahasiswas as $m)
                <tr>
                    <td>{{ $m->nim }}</td><td>{{ $m->nama_lengkap }}</td><td>{{ $m->email ?: $m->user?->email ?: '-' }}</td>
                    <td>{{ $m->prodi?->nama_prodi ?: '-' }}</td><td>{{ $m->kelasMaster?->nama_kelas ?: $m->kelas ?: '-' }}</td><td>{{ $m->angkatanMaster?->tahun ?: $m->angkatan ?: '-' }}</td>
                    <td><span class="badge bg-{{ $m->profile_status === 'lengkap' ? 'success' : 'warning' }}">{{ str_replace('_',' ', $m->profile_status) }}</span></td>
                    <td><a href="{{ route('admin.master.mahasiswa.show', $m) }}" class="btn btn-sm btn-outline-primary">Detail</a></td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted">Belum ada data mahasiswa.</td></tr>
            @endforelse
        </tbody>
    </table>
    {{ $mahasiswas->links() }}
</div>
@endsection
'@

Write-Utf8NoBom 'resources\views\admin\master\mahasiswa\form.blade.php' @'
@extends('layouts.app')
@section('title', isset($mahasiswa) ? 'Edit Mahasiswa' : 'Tambah Mahasiswa')
@section('page-title', isset($mahasiswa) ? 'Edit Mahasiswa' : 'Tambah Mahasiswa')
@section('content')
@include('partials.alerts')
@php($m = $mahasiswa ?? null)
<div class="card p-4">
    <form action="{{ isset($m) ? route('admin.master.mahasiswa.update', $m) : route('admin.master.mahasiswa.store') }}" method="POST">
        @csrf
        @isset($m) @method('PUT') @endisset
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label fw-semibold">NIM *</label><input name="nim" class="form-control" value="{{ old('nim', $m->nim ?? '') }}" required></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Nama Lengkap *</label><input name="nama_lengkap" class="form-control" value="{{ old('nama_lengkap', $m->nama_lengkap ?? '') }}" required></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Email</label><input type="email" name="email" class="form-control" value="{{ old('email', $m->email ?? $m?->user?->email) }}"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Kelas</label><select name="kelas_id" class="form-select"><option value="">-</option>@foreach($kelasOptions as $k)<option value="{{ $k->id }}" @selected(old('kelas_id', $m->kelas_id ?? '') == $k->id)>{{ $k->nama_kelas }}</option>@endforeach</select></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Jenis Kelamin</label><select name="jenis_kelamin" class="form-select"><option value="">-</option><option value="Laki-laki" @selected(old('jenis_kelamin', $m->jenis_kelamin ?? '') === 'Laki-laki')>Laki-laki</option><option value="Perempuan" @selected(old('jenis_kelamin', $m->jenis_kelamin ?? '') === 'Perempuan')>Perempuan</option></select></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Nomor HP</label><input name="no_hp" class="form-control" value="{{ old('no_hp', $m->no_hp ?? '') }}"></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Alamat</label><input name="alamat_lengkap" class="form-control" value="{{ old('alamat_lengkap', $m->alamat_lengkap ?? '') }}"></div>
            <div class="col-md-3"><label class="form-label fw-semibold">Tempat Lahir</label><input name="tempat_lahir" class="form-control" value="{{ old('tempat_lahir', $m->tempat_lahir ?? '') }}"></div>
            <div class="col-md-3"><label class="form-label fw-semibold">Tanggal Lahir</label><input type="date" name="tanggal_lahir" class="form-control" value="{{ old('tanggal_lahir', $m->tanggal_lahir ?? '') }}"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Angkatan</label><select name="angkatan_id" class="form-select"><option value="">-</option>@foreach($angkatanOptions as $a)<option value="{{ $a->id }}" @selected(old('angkatan_id', $m->angkatan_id ?? '') == $a->id)>{{ $a->tahun }}</option>@endforeach</select></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Fakultas</label><select name="fakultas_id" class="form-select"><option value="">-</option>@foreach($fakultas as $f)<option value="{{ $f->id }}" @selected(old('fakultas_id', $m->fakultas_id ?? '') == $f->id)>{{ $f->nama_fakultas }}</option>@endforeach</select></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Program Studi</label><select name="prodi_id" class="form-select"><option value="">-</option>@foreach($prodis as $p)<option value="{{ $p->id }}" @selected(old('prodi_id', $m->prodi_id ?? '') == $p->id)>{{ $p->nama_prodi }}</option>@endforeach</select></div>
            <div class="col-md-3"><label class="form-label fw-semibold">Semester</label><input type="number" name="semester" class="form-control" value="{{ old('semester', $m->semester ?? '') }}"></div>
            <div class="col-md-3"><label class="form-label fw-semibold">SKS Lulus</label><input type="number" name="sks_lulus" class="form-control" value="{{ old('sks_lulus', $m->sks_lulus ?? '') }}"></div>
            <div class="col-md-3"><label class="form-label fw-semibold">IPK</label><input type="number" step="0.01" name="ipk" class="form-control" value="{{ old('ipk', $m->ipk ?? '') }}"></div>
            <div class="col-md-3"><label class="form-label fw-semibold">Status Mahasiswa</label><select name="status_mahasiswa" class="form-select"><option value="aktif" @selected(old('status_mahasiswa', $m->status_mahasiswa ?? 'aktif') === 'aktif')>Aktif</option><option value="cuti" @selected(old('status_mahasiswa', $m->status_mahasiswa ?? '') === 'cuti')>Cuti</option><option value="lulus" @selected(old('status_mahasiswa', $m->status_mahasiswa ?? '') === 'lulus')>Lulus</option></select></div>
            <div class="col-12"><button class="btn btn-primary">Simpan</button><a href="{{ route('admin.master.mahasiswa.index') }}" class="btn btn-secondary">Kembali</a></div>
        </div>
    </form>
</div>
@endsection
'@

Write-Utf8NoBom 'resources\views\admin\master\mahasiswa\show.blade.php' @'
@extends('layouts.app')
@section('title', 'Detail Mahasiswa')
@section('page-title', 'Detail Mahasiswa')
@section('content')
<div class="card p-4">
    <div class="d-flex justify-content-between mb-3"><h6 class="fw-bold">Detail Mahasiswa</h6><a href="{{ route('admin.master.mahasiswa.edit', $mahasiswa) }}" class="btn btn-sm btn-outline-warning">Edit</a></div>
    @php($items = ['NIM'=>$mahasiswa->nim,'Nama Lengkap'=>$mahasiswa->nama_lengkap,'Email'=>$mahasiswa->email ?: $mahasiswa->user?->email,'Akun Login'=>$mahasiswa->user?->email,'Kelas'=>$mahasiswa->kelasMaster?->nama_kelas ?: $mahasiswa->kelas,'Jenis Kelamin'=>$mahasiswa->jenis_kelamin,'Alamat'=>$mahasiswa->alamat_lengkap,'Nomor HP'=>$mahasiswa->no_hp,'Tempat Lahir'=>$mahasiswa->tempat_lahir,'Tanggal Lahir'=>$mahasiswa->tanggal_lahir,'Angkatan'=>$mahasiswa->angkatanMaster?->tahun ?: $mahasiswa->angkatan,'Fakultas'=>$mahasiswa->fakultas?->nama_fakultas,'Program Studi'=>$mahasiswa->prodi?->nama_prodi,'Semester'=>$mahasiswa->semester,'SKS Lulus'=>$mahasiswa->sks_lulus,'IPK'=>$mahasiswa->ipk,'Status Mahasiswa'=>$mahasiswa->status_mahasiswa,'Status Profile'=>str_replace('_',' ', $mahasiswa->profile_status)])
    <div class="row">@foreach($items as $label=>$value)<div class="col-md-6 mb-3"><div class="text-muted small">{{ $label }}</div><div class="fw-semibold">{{ filled($value) ? $value : '-' }}</div></div>@endforeach</div>
    <a href="{{ route('admin.master.mahasiswa.index') }}" class="btn btn-secondary">Kembali</a>
</div>
@endsection
'@

Write-Utf8NoBom 'resources\views\admin\master\dosen\index.blade.php' @'
@extends('layouts.app')
@section('title', 'Data Dosen')
@section('page-title', 'Data Dosen')
@section('content')
@include('partials.alerts')
<div class="card p-4 mb-3">
    <div class="d-flex justify-content-between flex-wrap gap-2">
        <form method="GET" class="d-flex gap-2"><input type="text" name="search" class="form-control" placeholder="Cari NIDN/nama/email..." value="{{ request('search') }}"><button class="btn btn-outline-primary">Cari</button></form>
        <div class="d-flex gap-2"><a href="{{ route('admin.master.dosen.create') }}" class="btn btn-primary">Tambah Manual</a><a href="{{ route('admin.master.dosen.template') }}" class="btn btn-outline-secondary">Template CSV</a><a href="{{ route('admin.master.dosen.export') }}" class="btn btn-outline-success">Export CSV</a></div>
    </div>
    <form action="{{ route('admin.master.dosen.import') }}" method="POST" enctype="multipart/form-data" class="row g-2 mt-3">@csrf<div class="col-md-8"><input type="file" name="file" class="form-control" accept=".csv,.txt,.xlsx,.xls" required></div><div class="col-md-4"><button class="btn btn-outline-primary w-100">Import CSV/Excel</button></div></form>
</div>
<div class="card p-4"><table class="table table-hover"><thead class="table-light"><tr><th>NIDN/NIP</th><th>Nama</th><th>Email</th><th>Program Studi</th><th>No HP</th><th>Status</th><th>Aksi</th></tr></thead><tbody>@forelse($dosens as $d)<tr><td>{{ $d->nidn }}</td><td>{{ $d->nama_dosen }}</td><td>{{ $d->email_dosen ?: $d->user?->email ?: '-' }}</td><td>{{ $d->prodi?->nama_prodi ?: '-' }}</td><td>{{ $d->no_hp ?: '-' }}</td><td><span class="badge bg-{{ $d->status_dosen === 'aktif' ? 'success' : 'secondary' }}">{{ $d->status_dosen }}</span></td><td><a href="{{ route('admin.master.dosen.show', $d) }}" class="btn btn-sm btn-outline-primary">Detail</a></td></tr>@empty<tr><td colspan="7" class="text-center text-muted">Belum ada data dosen.</td></tr>@endforelse</tbody></table>{{ $dosens->links() }}</div>
@endsection
'@

Write-Utf8NoBom 'resources\views\admin\master\dosen\form.blade.php' @'
@extends('layouts.app')
@section('title', isset($dosen) ? 'Edit Dosen' : 'Tambah Dosen')
@section('page-title', isset($dosen) ? 'Edit Dosen' : 'Tambah Dosen')
@section('content')
@include('partials.alerts')
@php($d = $dosen ?? null)
<div class="card p-4">
<form action="{{ isset($d) ? route('admin.master.dosen.update', $d) : route('admin.master.dosen.store') }}" method="POST">
@csrf @isset($d) @method('PUT') @endisset
<div class="row g-3">
<div class="col-md-6"><label class="form-label fw-semibold">NIDN/NIP *</label><input name="nidn" class="form-control" value="{{ old('nidn', $d->nidn ?? '') }}" required></div>
<div class="col-md-6"><label class="form-label fw-semibold">Nama Dosen *</label><input name="nama_dosen" class="form-control" value="{{ old('nama_dosen', $d->nama_dosen ?? '') }}" required></div>
<div class="col-md-6"><label class="form-label fw-semibold">Email Dosen</label><input type="email" name="email_dosen" class="form-control" value="{{ old('email_dosen', $d->email_dosen ?? $d?->user?->email) }}"></div>
<div class="col-md-6"><label class="form-label fw-semibold">Program Studi</label><select name="prodi_id" class="form-select"><option value="">-</option>@foreach($prodis as $p)<option value="{{ $p->id }}" @selected(old('prodi_id', $d->prodi_id ?? '') == $p->id)>{{ $p->nama_prodi }}</option>@endforeach</select></div>
<div class="col-md-6"><label class="form-label fw-semibold">Nomor HP</label><input name="no_hp" class="form-control" value="{{ old('no_hp', $d->no_hp ?? '') }}"></div>
<div class="col-md-6"><label class="form-label fw-semibold">Status Dosen</label><select name="status_dosen" class="form-select"><option value="aktif" @selected(old('status_dosen', $d->status_dosen ?? 'aktif') === 'aktif')>Aktif</option><option value="nonaktif" @selected(old('status_dosen', $d->status_dosen ?? '') === 'nonaktif')>Nonaktif</option></select></div>
<div class="col-12"><button class="btn btn-primary">Simpan</button><a href="{{ route('admin.master.dosen.index') }}" class="btn btn-secondary">Kembali</a></div>
</div></form></div>
@endsection
'@

Write-Utf8NoBom 'resources\views\admin\master\dosen\show.blade.php' @'
@extends('layouts.app')
@section('title', 'Detail Dosen')
@section('page-title', 'Detail Dosen')
@section('content')
<div class="card p-4">
<div class="d-flex justify-content-between mb-3"><h6 class="fw-bold">Detail Dosen</h6><a href="{{ route('admin.master.dosen.edit', $dosen) }}" class="btn btn-sm btn-outline-warning">Edit</a></div>
@php($items=['NIDN/NIP'=>$dosen->nidn,'Nama Dosen'=>$dosen->nama_dosen,'Email Dosen'=>$dosen->email_dosen ?: $dosen->user?->email,'Akun Login'=>$dosen->user?->email,'Program Studi'=>$dosen->prodi?->nama_prodi,'Nomor HP'=>$dosen->no_hp,'Status Dosen'=>$dosen->status_dosen,'Status Profile'=>str_replace('_',' ', $dosen->profile_status)])
<div class="row">@foreach($items as $label=>$value)<div class="col-md-6 mb-3"><div class="text-muted small">{{ $label }}</div><div class="fw-semibold">{{ filled($value) ? $value : '-' }}</div></div>@endforeach</div>
<a href="{{ route('admin.master.dosen.index') }}" class="btn btn-secondary">Kembali</a>
</div>
@endsection
'@

Write-Utf8NoBom 'resources\views\admin\master\pembimbing\index.blade.php' @'
@extends('layouts.app')
@section('title', 'Data Pembimbing Lapangan')
@section('page-title', 'Data Pembimbing Lapangan')
@section('content')
@include('partials.alerts')
<div class="card p-4 mb-3"><div class="d-flex justify-content-between flex-wrap gap-2"><form method="GET" class="d-flex gap-2"><input type="text" name="search" class="form-control" placeholder="Cari nama/email/HP..." value="{{ request('search') }}"><button class="btn btn-outline-primary">Cari</button></form><a href="{{ route('admin.master.pembimbing.create') }}" class="btn btn-primary">Tambah Manual</a></div></div>
<div class="card p-4"><table class="table table-hover"><thead class="table-light"><tr><th>Nama</th><th>Email</th><th>No HP</th><th>Jabatan</th><th>Mitra/Instansi</th><th>Status</th><th>Aksi</th></tr></thead><tbody>@forelse($pembimbings as $p)<tr><td>{{ $p->nama }}</td><td>{{ $p->email }}</td><td>{{ $p->no_hp ?: '-' }}</td><td>{{ $p->jabatan ?: '-' }}</td><td>{{ $p->mitra?->nama_instansi ?: $p->instansi ?: '-' }}</td><td><span class="badge bg-{{ $p->status === 'aktif' ? 'success' : 'secondary' }}">{{ $p->status }}</span></td><td><a href="{{ route('admin.master.pembimbing.show', $p) }}" class="btn btn-sm btn-outline-primary">Detail</a></td></tr>@empty<tr><td colspan="7" class="text-center text-muted">Belum ada data pembimbing lapangan.</td></tr>@endforelse</tbody></table>{{ $pembimbings->links() }}</div>
@endsection
'@

Write-Utf8NoBom 'resources\views\admin\master\pembimbing\form.blade.php' @'
@extends('layouts.app')
@section('title', isset($pembimbing) ? 'Edit Pembimbing Lapangan' : 'Tambah Pembimbing Lapangan')
@section('page-title', isset($pembimbing) ? 'Edit Pembimbing Lapangan' : 'Tambah Pembimbing Lapangan')
@section('content')
@include('partials.alerts')
@php($p = $pembimbing ?? null)
<div class="card p-4">
<form action="{{ isset($p) ? route('admin.master.pembimbing.update', $p) : route('admin.master.pembimbing.store') }}" method="POST">
@csrf @isset($p) @method('PUT') @endisset
<div class="row g-3">
<div class="col-md-6"><label class="form-label fw-semibold">Nama Pembimbing *</label><input name="nama" class="form-control" value="{{ old('nama', $p->nama ?? '') }}" required></div>
<div class="col-md-6"><label class="form-label fw-semibold">Email *</label><input type="email" name="email" class="form-control" value="{{ old('email', $p->email ?? '') }}" required></div>
<div class="col-md-6"><label class="form-label fw-semibold">No HP</label><input name="no_hp" class="form-control" value="{{ old('no_hp', $p->no_hp ?? '') }}"></div>
<div class="col-md-6"><label class="form-label fw-semibold">Jabatan</label><input name="jabatan" class="form-control" value="{{ old('jabatan', $p->jabatan ?? '') }}"></div>
<div class="col-md-6"><label class="form-label fw-semibold">Mitra/Instansi *</label><select name="mitra_id" class="form-select" required><option value="">-</option>@foreach($mitras as $m)<option value="{{ $m->id }}" @selected(old('mitra_id', $p->mitra_id ?? '') == $m->id)>{{ $m->nama_instansi }}</option>@endforeach</select></div>
<div class="col-md-6"><label class="form-label fw-semibold">Status</label><select name="status" class="form-select"><option value="aktif" @selected(old('status', $p->status ?? 'aktif') === 'aktif')>Aktif</option><option value="nonaktif" @selected(old('status', $p->status ?? '') === 'nonaktif')>Nonaktif</option></select></div>
<div class="col-md-6"><div class="form-check mt-4"><input type="hidden" name="buat_akun" value="0"><input class="form-check-input" type="checkbox" name="buat_akun" value="1" id="buat_akun" @checked(old('buat_akun', $p?->user_id ? 1 : 0))><label class="form-check-label" for="buat_akun">Buat/hubungkan akun login pembimbing</label></div></div>
<div class="col-md-6"><label class="form-label fw-semibold">Password Akun</label><input type="password" name="password" class="form-control" placeholder="Kosongkan untuk default/tidak diubah"></div>
<div class="col-12"><label class="form-label fw-semibold">Catatan</label><textarea name="catatan" class="form-control" rows="2">{{ old('catatan', $p->catatan ?? '') }}</textarea></div>
<div class="col-12"><button class="btn btn-primary">Simpan</button><a href="{{ route('admin.master.pembimbing.index') }}" class="btn btn-secondary">Kembali</a></div>
</div></form></div>
@endsection
'@

Write-Utf8NoBom 'resources\views\admin\master\pembimbing\show.blade.php' @'
@extends('layouts.app')
@section('title', 'Detail Pembimbing Lapangan')
@section('page-title', 'Detail Pembimbing Lapangan')
@section('content')
<div class="card p-4">
<div class="d-flex justify-content-between mb-3"><h6 class="fw-bold">Detail Pembimbing Lapangan</h6><a href="{{ route('admin.master.pembimbing.edit', $pembimbing) }}" class="btn btn-sm btn-outline-warning">Edit</a></div>
@php($items=['Nama Pembimbing'=>$pembimbing->nama,'Email'=>$pembimbing->email,'Akun Login'=>$pembimbing->user?->email,'No HP'=>$pembimbing->no_hp,'Jabatan'=>$pembimbing->jabatan,'Mitra/Instansi'=>$pembimbing->mitra?->nama_instansi ?: $pembimbing->instansi,'Status'=>$pembimbing->status,'Status Profile'=>str_replace('_',' ', $pembimbing->profile_status),'Catatan'=>$pembimbing->catatan])
<div class="row">@foreach($items as $label=>$value)<div class="col-md-6 mb-3"><div class="text-muted small">{{ $label }}</div><div class="fw-semibold">{{ filled($value) ? $value : '-' }}</div></div>@endforeach</div>
<a href="{{ route('admin.master.pembimbing.index') }}" class="btn btn-secondary">Kembali</a>
</div>
@endsection
'@

Write-Utf8NoBom 'app\Http\Controllers\ProfileController.php' @'
<?php
namespace App\Http\Controllers;

use App\Models\Angkatan;
use App\Models\Fakultas;
use App\Models\Kelas;
use App\Models\Mitra;
use App\Models\Prodi;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function show()
    {
        $user = auth()->user()->load([
            'mahasiswaProfile.fakultas',
            'mahasiswaProfile.prodi',
            'mahasiswaProfile.kelasMaster',
            'mahasiswaProfile.angkatanMaster',
            'dosen.prodi',
            'mitraUser.mitra',
            'pembimbingLapangan.mitra',
        ]);

        return view('profile.show', compact('user'));
    }

    public function edit()
    {
        $user = auth()->user()->load([
            'mahasiswaProfile.fakultas',
            'mahasiswaProfile.prodi',
            'mahasiswaProfile.kelasMaster',
            'mahasiswaProfile.angkatanMaster',
            'dosen.prodi',
            'mitraUser.mitra',
            'pembimbingLapangan.mitra',
        ]);
        $fakultas = Fakultas::orderBy('nama_fakultas')->get();
        $prodis = Prodi::with('fakultas')->orderBy('nama_prodi')->get();
        $kelasOptions = Kelas::where('status', 'aktif')->orderBy('nama_kelas')->get();
        $angkatanOptions = Angkatan::where('status', 'aktif')->orderByDesc('tahun')->get();
        $mitras = Mitra::orderBy('nama_instansi')->get();

        return view('profile.edit', compact('user', 'fakultas', 'prodis', 'kelasOptions', 'angkatanOptions', 'mitras'));
    }

    public function update(Request $request)
    {
        $user = auth()->user();
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'no_hp' => 'nullable|string|max:50',
            'email_pribadi' => 'nullable|email|max:150',
            'alamat_lengkap' => 'nullable|string',
            'tempat_lahir' => 'nullable|string|max:100',
            'jenis_kelamin' => 'nullable|in:Laki-laki,Perempuan',
            'tanggal_lahir' => 'nullable|date',
            'kelas_id' => 'nullable|exists:kelas,id',
            'angkatan_id' => 'nullable|exists:angkatans,id',
            'fakultas_id' => 'nullable|exists:fakultas,id',
            'prodi_id' => 'nullable|exists:prodis,id',
            'semester' => 'nullable|integer|min:1',
            'sks_lulus' => 'nullable|integer|min:0',
            'ipk' => 'nullable|numeric|min:0|max:4',
            'status_mahasiswa' => 'nullable|in:aktif,cuti,lulus',
            'pernah_cuti' => 'nullable|boolean',
            'nidn' => 'nullable|string|max:80',
            'nama_dosen' => 'nullable|string|max:255',
            'status_dosen' => 'nullable|in:aktif,nonaktif',
            'nama_pembimbing' => 'nullable|string|max:150',
            'jabatan' => 'nullable|string|max:150',
            'mitra_id' => 'nullable|exists:mitras,id',
        ]);

        $user->update(['name' => $data['name'], 'email' => $data['email']]);

        if ($user->role === 'mahasiswa' && $user->mahasiswaProfile) {
            $kelas = isset($data['kelas_id']) ? Kelas::find($data['kelas_id']) : null;
            $angkatan = isset($data['angkatan_id']) ? Angkatan::find($data['angkatan_id']) : null;
            $profile = $user->mahasiswaProfile;
            $profile->fill([
                'nama_lengkap' => $data['name'],
                'no_hp' => $data['no_hp'] ?? $profile->no_hp,
                'email' => $data['email_pribadi'] ?? $profile->email,
                'alamat_lengkap' => $data['alamat_lengkap'] ?? $profile->alamat_lengkap,
                'tempat_lahir' => $data['tempat_lahir'] ?? $profile->tempat_lahir,
                'jenis_kelamin' => $data['jenis_kelamin'] ?? $profile->jenis_kelamin,
                'tanggal_lahir' => $data['tanggal_lahir'] ?? $profile->tanggal_lahir,
                'kelas_id' => $data['kelas_id'] ?? $profile->kelas_id,
                'kelas' => $kelas?->nama_kelas ?: $profile->kelas,
                'angkatan_id' => $data['angkatan_id'] ?? $profile->angkatan_id,
                'angkatan' => $angkatan?->tahun ?: $profile->angkatan,
                'fakultas_id' => $data['fakultas_id'] ?? $profile->fakultas_id,
                'prodi_id' => $data['prodi_id'] ?? $profile->prodi_id,
                'semester' => $data['semester'] ?? $profile->semester,
                'sks_lulus' => $data['sks_lulus'] ?? $profile->sks_lulus,
                'ipk' => $data['ipk'] ?? $profile->ipk,
                'status_mahasiswa' => $data['status_mahasiswa'] ?? $profile->status_mahasiswa,
                'pernah_cuti' => $request->boolean('pernah_cuti'),
            ]);
            $profile->syncProfileStatus();
            $profile->save();
        }

        if ($user->role === 'dosen' && $user->dosen) {
            $dosen = $user->dosen;
            $dosen->fill([
                'nidn' => $data['nidn'] ?? $dosen->nidn,
                'nama_dosen' => $data['nama_dosen'] ?? $data['name'],
                'prodi_id' => $data['prodi_id'] ?? $dosen->prodi_id,
                'no_hp' => $data['no_hp'] ?? $dosen->no_hp,
                'email_dosen' => $data['email_pribadi'] ?? $dosen->email_dosen,
                'status_dosen' => $data['status_dosen'] ?? $dosen->status_dosen,
            ]);
            $dosen->syncProfileStatus();
            $dosen->save();
        }

        if ($user->role === 'pembimbing_lapangan' && $user->pembimbingLapangan) {
            $mitra = isset($data['mitra_id']) ? Mitra::find($data['mitra_id']) : null;
            $pembimbing = $user->pembimbingLapangan;
            $pembimbing->fill([
                'nama' => $data['nama_pembimbing'] ?? $data['name'],
                'email' => $data['email_pribadi'] ?? $user->email,
                'no_hp' => $data['no_hp'] ?? $pembimbing->no_hp,
                'jabatan' => $data['jabatan'] ?? $pembimbing->jabatan,
                'mitra_id' => $data['mitra_id'] ?? $pembimbing->mitra_id,
                'instansi' => $mitra?->nama_instansi ?: $pembimbing->instansi,
            ]);
            $pembimbing->syncProfileStatus();
            $pembimbing->save();
        }

        if ($user->role === 'mitra' && $user->mitraUser?->mitra) {
            $user->mitraUser->mitra->update([
                'email' => $data['email_pribadi'] ?? $user->mitraUser->mitra->email,
                'no_telp' => $data['no_hp'] ?? $user->mitraUser->mitra->no_telp,
            ]);
        }

        return redirect()->route('profile.show')->with('success', 'Profile berhasil diperbarui.');
    }
}
'@

$routesPath = Join-Path $root 'routes\web.php'
$routes = Get-Content $routesPath -Raw
if ($routes -notmatch 'MasterDataController') {
    $routes = $routes -replace 'use App\\Http\\Controllers\\Admin\\UserController;', "use App\Http\Controllers\Admin\UserController;`r`nuse App\Http\Controllers\Admin\MasterDataController;`r`nuse App\Http\Controllers\Admin\MasterMahasiswaController;`r`nuse App\Http\Controllers\Admin\MasterDosenController;`r`nuse App\Http\Controllers\Admin\MasterPembimbingLapanganController;"
}
if ($routes -notmatch "master-data") {
    $masterRoutes = @'
    Route::get('/master-data', [MasterDataController::class, 'index'])->name('master.index');
    Route::get('/master-data/referensi/{type}', [MasterDataController::class, 'reference'])->name('master.reference.index');
    Route::post('/master-data/referensi/{type}', [MasterDataController::class, 'storeReference'])->name('master.reference.store');
    Route::put('/master-data/referensi/{type}/{id}', [MasterDataController::class, 'updateReference'])->name('master.reference.update');
    Route::delete('/master-data/referensi/{type}/{id}', [MasterDataController::class, 'destroyReference'])->name('master.reference.destroy');
    Route::get('/master-data/mahasiswa/template', [MasterMahasiswaController::class, 'template'])->name('master.mahasiswa.template');
    Route::get('/master-data/mahasiswa/export', [MasterMahasiswaController::class, 'export'])->name('master.mahasiswa.export');
    Route::post('/master-data/mahasiswa/import', [MasterMahasiswaController::class, 'import'])->name('master.mahasiswa.import');
    Route::resource('/master-data/mahasiswa', MasterMahasiswaController::class)->names('master.mahasiswa');
    Route::get('/master-data/dosen/template', [MasterDosenController::class, 'template'])->name('master.dosen.template');
    Route::get('/master-data/dosen/export', [MasterDosenController::class, 'export'])->name('master.dosen.export');
    Route::post('/master-data/dosen/import', [MasterDosenController::class, 'import'])->name('master.dosen.import');
    Route::resource('/master-data/dosen', MasterDosenController::class)->names('master.dosen');
    Route::resource('/master-data/pembimbing-lapangan', MasterPembimbingLapanganController::class)->parameters(['pembimbing-lapangan' => 'pembimbing'])->names('master.pembimbing');
'@
    $routes = $routes -replace "Route::resource\('users', UserController::class\);", "Route::resource('users', UserController::class);`r`n$masterRoutes"
}
$enc = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText($routesPath, $routes, $enc)

$sidebarPath = Join-Path $root 'resources\views\layouts\sidebar.blade.php'
$sidebar = Get-Content $sidebarPath -Raw
if ($sidebar -notmatch 'admin.master.index') {
    $insert = @'

    <a href="{{ route('admin.master.index') }}"
       class="nav-link {{ request()->is('admin/master-data*') ? 'active' : '' }}">
        <i class="bi bi-database me-2"></i>
        Master Data
    </a>
'@
    $marker = "        Manajemen User`r`n    </a>"
    $sidebar = $sidebar.Replace($marker, $marker + $insert)
    [System.IO.File]::WriteAllText($sidebarPath, $sidebar, $enc)
}

$pengajuanPath = Join-Path $root 'app\Http\Controllers\Mahasiswa\PengajuanController.php'
$pengajuan = Get-Content $pengajuanPath -Raw
$pengajuan = $pengajuan -replace "Profil mahasiswa belum lengkap\.", "Profile Anda belum lengkap. Silakan lengkapi profile terlebih dahulu sebelum mengajukan magang."
[System.IO.File]::WriteAllText($pengajuanPath, $pengajuan, $enc)

Write-Utf8NoBom 'resources\views\profile\show.blade.php' @'
@extends('layouts.app')
@section('title', 'Profile')
@section('page-title', 'Profile')

@php
    $items = [
        'Nama Akun' => $user->name,
        'Email Akun' => $user->email,
        'Role' => ucwords(str_replace('_', ' ', $user->role)),
        'Status Akun' => $user->status,
    ];

    if ($user->role === 'mahasiswa' && $user->mahasiswaProfile) {
        $m = $user->mahasiswaProfile;
        $items = array_merge($items, [
            'NIM' => $m->nim,
            'Nama Lengkap' => $m->nama_lengkap,
            'Kelas' => $m->kelasMaster?->nama_kelas ?: $m->kelas,
            'Jenis Kelamin' => $m->jenis_kelamin,
            'Alamat' => $m->alamat_lengkap,
            'Nomor HP' => $m->no_hp,
            'Tempat Lahir' => $m->tempat_lahir,
            'Tanggal Lahir' => $m->tanggal_lahir,
            'Fakultas' => $m->fakultas?->nama_fakultas ?: 'Fakultas Sains dan Teknologi',
            'Program Studi' => $m->prodi?->nama_prodi,
            'Angkatan' => $m->angkatanMaster?->tahun ?: $m->angkatan,
            'Semester' => $m->semester,
            'SKS Lulus' => $m->sks_lulus,
            'Pernah Cuti' => $m->pernah_cuti ? 'Ya' : 'Tidak',
            'IPK' => $m->ipk,
            'Status Mahasiswa' => $m->status_mahasiswa,
            'Status Profile' => str_replace('_', ' ', $m->profile_status),
        ]);
    }

    if ($user->role === 'dosen' && $user->dosen) {
        $d = $user->dosen;
        $items = array_merge($items, [
            'Nama Dosen' => $d->nama_dosen,
            'NIDN/NIP' => $d->nidn,
            'Program Studi' => $d->prodi?->nama_prodi,
            'Nomor HP' => $d->no_hp,
            'Email Dosen' => $d->email_dosen,
            'Status Dosen' => $d->status_dosen,
            'Status Profile' => str_replace('_', ' ', $d->profile_status),
        ]);
    }

    if ($user->role === 'pembimbing_lapangan' && $user->pembimbingLapangan) {
        $p = $user->pembimbingLapangan;
        $items = array_merge($items, [
            'Nama Pembimbing' => $p->nama,
            'Email Pembimbing' => $p->email,
            'Nomor HP' => $p->no_hp,
            'Jabatan' => $p->jabatan,
            'Mitra/Instansi' => $p->mitra?->nama_instansi ?: $p->instansi,
            'Status Pembimbing' => $p->status,
            'Status Profile' => str_replace('_', ' ', $p->profile_status),
        ]);
    }

    if ($user->role === 'mitra' && $user->mitraUser?->mitra) {
        $mitra = $user->mitraUser->mitra;
        $items = array_merge($items, [
            'Nama Instansi' => $mitra->nama_instansi,
            'Jenis Instansi' => $mitra->jenis_instansi,
            'Bidang Instansi' => $mitra->bidang_industri,
            'Alamat' => $mitra->alamat,
            'Kota' => $mitra->kota,
            'Email Instansi' => $mitra->email,
            'Nomor Telepon' => $mitra->no_telp,
            'Status Mitra' => $mitra->status_mitra_detail ?? $mitra->status_mitra,
        ]);
    }
@endphp

@section('content')
@include('partials.alerts')
<div class="card p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-bold mb-0">Profile Saya</h6>
        <a href="{{ route('profile.edit') }}" class="btn btn-primary btn-sm">Edit Profile</a>
    </div>
    @if($user->role === 'mahasiswa' && (!$user->mahasiswaProfile || !$user->mahasiswaProfile->profileComplete()))
        <div class="alert alert-warning">Profile Anda belum lengkap. Silakan lengkapi profile terlebih dahulu sebelum mengajukan magang.</div>
    @endif
    <div class="row">
        @foreach($items as $label => $value)
        <div class="col-md-6 mb-3">
            <div class="text-muted small">{{ $label }}</div>
            <div class="fw-semibold">{{ filled($value) ? $value : '-' }}</div>
        </div>
        @endforeach
    </div>
</div>
@endsection
'@

Write-Utf8NoBom 'resources\views\profile\edit.blade.php' @'
@extends('layouts.app')
@section('title', 'Edit Profile')
@section('page-title', 'Edit Profile')

@section('content')
@include('partials.alerts')
<div class="card p-4">
    <form action="{{ route('profile.update') }}" method="POST">
        @csrf
        @method('PUT')
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label fw-semibold">Nama Akun</label><input type="text" name="name" class="form-control" value="{{ old('name', $user->name) }}" required></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Email Akun</label><input type="email" name="email" class="form-control" value="{{ old('email', $user->email) }}" required></div>

            @if($user->role === 'mahasiswa' && $user->mahasiswaProfile)
                @php($m = $user->mahasiswaProfile)
                <div class="col-md-4"><label class="form-label fw-semibold">Kelas</label><select name="kelas_id" class="form-select"><option value="">-</option>@foreach($kelasOptions as $k)<option value="{{ $k->id }}" @selected(old('kelas_id', $m->kelas_id) == $k->id)>{{ $k->nama_kelas }}</option>@endforeach</select></div>
                <div class="col-md-4"><label class="form-label fw-semibold">Jenis Kelamin</label><select name="jenis_kelamin" class="form-select"><option value="">-</option><option value="Laki-laki" @selected(old('jenis_kelamin', $m->jenis_kelamin) === 'Laki-laki')>Laki-laki</option><option value="Perempuan" @selected(old('jenis_kelamin', $m->jenis_kelamin) === 'Perempuan')>Perempuan</option></select></div>
                <div class="col-md-4"><label class="form-label fw-semibold">Nomor HP</label><input type="text" name="no_hp" class="form-control" value="{{ old('no_hp', $m->no_hp) }}"></div>
                <div class="col-md-6"><label class="form-label fw-semibold">Email Pribadi</label><input type="email" name="email_pribadi" class="form-control" value="{{ old('email_pribadi', $m->email) }}"></div>
                <div class="col-md-6"><label class="form-label fw-semibold">Alamat Lengkap</label><input name="alamat_lengkap" class="form-control" value="{{ old('alamat_lengkap', $m->alamat_lengkap) }}"></div>
                <div class="col-md-4"><label class="form-label fw-semibold">Tempat Lahir</label><input name="tempat_lahir" class="form-control" value="{{ old('tempat_lahir', $m->tempat_lahir) }}"></div>
                <div class="col-md-4"><label class="form-label fw-semibold">Tanggal Lahir</label><input type="date" name="tanggal_lahir" class="form-control" value="{{ old('tanggal_lahir', $m->tanggal_lahir) }}"></div>
                <div class="col-md-4"><label class="form-label fw-semibold">Angkatan</label><select name="angkatan_id" class="form-select"><option value="">-</option>@foreach($angkatanOptions as $a)<option value="{{ $a->id }}" @selected(old('angkatan_id', $m->angkatan_id) == $a->id)>{{ $a->tahun }}</option>@endforeach</select></div>
                <div class="col-md-6"><label class="form-label fw-semibold">Fakultas</label><select name="fakultas_id" class="form-select"><option value="">-</option>@foreach($fakultas as $f)<option value="{{ $f->id }}" @selected(old('fakultas_id', $m->fakultas_id) == $f->id)>{{ $f->nama_fakultas }}</option>@endforeach</select></div>
                <div class="col-md-6"><label class="form-label fw-semibold">Program Studi</label><select name="prodi_id" class="form-select"><option value="">-</option>@foreach($prodis as $p)<option value="{{ $p->id }}" @selected(old('prodi_id', $m->prodi_id) == $p->id)>{{ $p->nama_prodi }}</option>@endforeach</select></div>
                <div class="col-md-3"><label class="form-label fw-semibold">Semester</label><input type="number" name="semester" class="form-control" value="{{ old('semester', $m->semester) }}"></div>
                <div class="col-md-3"><label class="form-label fw-semibold">SKS Lulus</label><input type="number" name="sks_lulus" class="form-control" value="{{ old('sks_lulus', $m->sks_lulus) }}"></div>
                <div class="col-md-3"><label class="form-label fw-semibold">IPK</label><input type="number" step="0.01" name="ipk" class="form-control" value="{{ old('ipk', $m->ipk) }}"></div>
                <div class="col-md-3"><label class="form-label fw-semibold">Status Mahasiswa</label><select name="status_mahasiswa" class="form-select"><option value="aktif" @selected(old('status_mahasiswa', $m->status_mahasiswa) === 'aktif')>Aktif</option><option value="cuti" @selected(old('status_mahasiswa', $m->status_mahasiswa) === 'cuti')>Cuti</option><option value="lulus" @selected(old('status_mahasiswa', $m->status_mahasiswa) === 'lulus')>Lulus</option></select></div>
                <div class="col-md-4"><div class="form-check mt-4"><input type="hidden" name="pernah_cuti" value="0"><input class="form-check-input" type="checkbox" name="pernah_cuti" value="1" id="pernah_cuti" @checked(old('pernah_cuti', $m->pernah_cuti))><label class="form-check-label fw-semibold" for="pernah_cuti">Pernah Cuti</label></div></div>
            @elseif($user->role === 'dosen' && $user->dosen)
                @php($d = $user->dosen)
                <div class="col-md-6"><label class="form-label fw-semibold">Nama Dosen</label><input name="nama_dosen" class="form-control" value="{{ old('nama_dosen', $d->nama_dosen) }}"></div>
                <div class="col-md-6"><label class="form-label fw-semibold">NIDN/NIP</label><input name="nidn" class="form-control" value="{{ old('nidn', $d->nidn) }}"></div>
                <div class="col-md-4"><label class="form-label fw-semibold">Program Studi</label><select name="prodi_id" class="form-select"><option value="">-</option>@foreach($prodis as $p)<option value="{{ $p->id }}" @selected(old('prodi_id', $d->prodi_id) == $p->id)>{{ $p->nama_prodi }}</option>@endforeach</select></div>
                <div class="col-md-4"><label class="form-label fw-semibold">Nomor HP</label><input type="text" name="no_hp" class="form-control" value="{{ old('no_hp', $d->no_hp) }}"></div>
                <div class="col-md-4"><label class="form-label fw-semibold">Email Dosen</label><input type="email" name="email_pribadi" class="form-control" value="{{ old('email_pribadi', $d->email_dosen) }}"></div>
            @elseif($user->role === 'pembimbing_lapangan' && $user->pembimbingLapangan)
                @php($p = $user->pembimbingLapangan)
                <div class="col-md-6"><label class="form-label fw-semibold">Nama Pembimbing</label><input name="nama_pembimbing" class="form-control" value="{{ old('nama_pembimbing', $p->nama) }}"></div>
                <div class="col-md-6"><label class="form-label fw-semibold">Email Pembimbing</label><input type="email" name="email_pribadi" class="form-control" value="{{ old('email_pribadi', $p->email) }}"></div>
                <div class="col-md-4"><label class="form-label fw-semibold">No HP</label><input name="no_hp" class="form-control" value="{{ old('no_hp', $p->no_hp) }}"></div>
                <div class="col-md-4"><label class="form-label fw-semibold">Jabatan</label><input name="jabatan" class="form-control" value="{{ old('jabatan', $p->jabatan) }}"></div>
                <div class="col-md-4"><label class="form-label fw-semibold">Mitra/Instansi</label><select name="mitra_id" class="form-select"><option value="">-</option>@foreach($mitras as $m)<option value="{{ $m->id }}" @selected(old('mitra_id', $p->mitra_id) == $m->id)>{{ $m->nama_instansi }}</option>@endforeach</select></div>
            @elseif($user->role === 'mitra' && $user->mitraUser?->mitra)
                <div class="col-md-6"><label class="form-label fw-semibold">Email Instansi</label><input type="email" name="email_pribadi" class="form-control" value="{{ old('email_pribadi', $user->mitraUser->mitra->email) }}"></div>
                <div class="col-md-6"><label class="form-label fw-semibold">Nomor Telepon</label><input type="text" name="no_hp" class="form-control" value="{{ old('no_hp', $user->mitraUser->mitra->no_telp) }}"></div>
            @endif
            <div class="col-12"><button type="submit" class="btn btn-primary px-4">Simpan</button><a href="{{ route('profile.show') }}" class="btn btn-secondary px-4">Kembali</a></div>
        </div>
    </form>
</div>
@endsection
'@

$routes = Get-Content $routesPath -Raw
$routes = $routes -replace "'mitra'               => redirect\(\)->route\('mitra.dashboard'\),", "'mitra'               => redirect()->route('mitra.dashboard'),`r`n        'pembimbing_lapangan'=> redirect()->route('profile.show'),"
[System.IO.File]::WriteAllText($routesPath, $routes, $enc)
