$ErrorActionPreference = 'Stop'
$root = 'C:\laragon\www\mbkm_fakultas'
function Write-Utf8NoBom($relative, $content) {
    $path = Join-Path $root $relative
    $enc = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($path, $content, $enc)
}

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
        if (count($rawRows) < 2) return [];

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
        $header = trim(preg_replace('/\x{FEFF}/u', '', $header));
        $compact = strtolower(preg_replace('/[^a-z0-9]+/i', '', $header));
        $aliases = [
            'nama' => 'nama_lengkap',
            'namalengkap' => 'nama_lengkap',
            'nim' => 'nim',
            'email' => 'email',
            'kelas' => 'kelas',
            'jeniskelamin' => 'jenis_kelamin',
            'alamat' => 'alamat',
            'alamatlengkap' => 'alamat_lengkap',
            'nohp' => 'no_hp',
            'nohandphone' => 'no_hp',
            'nomorhp' => 'no_hp',
            'nomorhandphone' => 'no_hp',
            'telepon' => 'no_hp',
            'telp' => 'no_hp',
            'tempatlahir' => 'tempat_lahir',
            'tanggallahir' => 'tanggal_lahir',
            'angkatan' => 'angkatan',
            'fakultas' => 'fakultas',
            'programstudi' => 'program_studi',
            'prodi' => 'program_studi',
            'semester' => 'semester',
            'skslulus' => 'sks_lulus',
            'ipk' => 'ipk',
            'statusmahasiswa' => 'status_mahasiswa',
            'nidnnip' => 'nidn_nip',
            'nidn' => 'nidn_nip',
            'nip' => 'nidn_nip',
            'namadosen' => 'nama_dosen',
            'statusdosen' => 'status_dosen',
        ];

        if (isset($aliases[$compact])) return $aliases[$compact];

        return preg_replace('/_+/', '_', strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $header), '_')));
    }

    private function csvResponse(string $filename, array $headers, iterable $rows)
    {
        $callback = function () use ($headers, $rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headers);
            foreach ($rows as $row) fputcsv($handle, $row);
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
use Illuminate\Support\Facades\Validator;
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
        $accountsCreated = 0;
        $withoutAccount = 0;
        $notes = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $nim = trim((string) ($row['nim'] ?? ''));
            $nama = trim((string) ($row['nama_lengkap'] ?? ''));

            if ($nim === '') {
                $notes[] = "Baris {$line}: dilewati karena NIM kosong.";
                continue;
            }
            if ($nama === '') {
                $notes[] = "Baris {$line}: dilewati karena nama mahasiswa kosong.";
                continue;
            }

            $email = $this->validEmailOrNull($row['email'] ?? null);
            if (($row['email'] ?? null) && !$email) {
                $notes[] = "Baris {$line}: email tidak valid, data tetap diimport tanpa akun.";
            }

            $tanggalLahir = $this->parseTanggalLahir($row['tanggal_lahir'] ?? null);
            if (($row['tanggal_lahir'] ?? null) && !$tanggalLahir) {
                $notes[] = "Baris {$line}: tanggal lahir tidak dapat dibaca, disimpan kosong.";
            }

            try {
                $existing = MahasiswaProfile::where('nim', $nim)->first();
                $hadUser = (bool) $existing?->user_id;
                $result = $this->saveMahasiswa([
                    'nim' => $nim,
                    'nama_lengkap' => $nama,
                    'email' => $email,
                    'kelas' => $this->cleanText($row['kelas'] ?? null),
                    'jenis_kelamin' => $this->normalizeJenisKelamin($row['jenis_kelamin'] ?? null),
                    'alamat_lengkap' => $this->cleanText($row['alamat_lengkap'] ?? ($row['alamat'] ?? null)),
                    'no_hp' => $this->cleanText($row['no_hp'] ?? null),
                    'tempat_lahir' => $this->cleanText($row['tempat_lahir'] ?? null),
                    'tanggal_lahir' => $tanggalLahir,
                    'angkatan' => $this->cleanText($row['angkatan'] ?? null),
                    'fakultas' => $this->cleanText($row['fakultas'] ?? null),
                    'program_studi' => $this->cleanText($row['program_studi'] ?? null),
                    'semester' => $this->toIntegerOrNull($row['semester'] ?? null),
                    'sks_lulus' => $this->toIntegerOrNull($row['sks_lulus'] ?? null),
                    'ipk' => $this->toFloatOrNull($row['ipk'] ?? null),
                    'status_mahasiswa' => $this->normalizeStatusMahasiswa($row['status_mahasiswa'] ?? null),
                ], $existing, $notes, $line);

                $existing ? $updated++ : $created++;
                if (!$hadUser && $result->user_id) $accountsCreated++;
                if (!$result->user_id) $withoutAccount++;
            } catch (\Throwable $e) {
                $notes[] = "Baris {$line}: gagal diimport. " . $this->friendlyImportError($e->getMessage());
            }
        }

        $message = "Import selesai. Baru: {$created}, Diperbarui: {$updated}, Akun dibuat: {$accountsCreated}, Tanpa akun: {$withoutAccount}.";
        if ($notes) $message .= ' Catatan: ' . implode(' ', array_slice($notes, 0, 8));

        return back()->with($notes ? 'error' : 'success', $message);
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

    private function saveMahasiswa(array $data, ?MahasiswaProfile $mahasiswa = null, array &$notes = [], ?int $line = null): MahasiswaProfile
    {
        $fakultasId = $data['fakultas_id'] ?? null;
        if (!$fakultasId && filled($data['fakultas'] ?? null)) {
            $fakultasId = Fakultas::firstOrCreate(['nama_fakultas' => trim($data['fakultas'])])->id;
        }
        if (!$fakultasId) {
            $fakultasId = Fakultas::firstOrCreate(['nama_fakultas' => 'Fakultas Sains dan Teknologi'])->id;
        }

        $prodiId = $data['prodi_id'] ?? null;
        if (!$prodiId && filled($data['program_studi'] ?? null)) {
            $prodiId = Prodi::firstOrCreate(['nama_prodi' => trim($data['program_studi'])], ['fakultas_id' => $fakultasId])->id;
        }

        $kelasId = $data['kelas_id'] ?? null;
        if (!$kelasId && filled($data['kelas'] ?? null)) {
            $kelasId = Kelas::firstOrCreate(['nama_kelas' => trim($data['kelas'])], ['status' => 'aktif'])->id;
        }

        $angkatanId = $data['angkatan_id'] ?? null;
        if (!$angkatanId && filled($data['angkatan'] ?? null)) {
            $angkatanId = Angkatan::firstOrCreate(['tahun' => (int) $data['angkatan']], ['status' => 'aktif'])->id;
        }

        $userId = $mahasiswa?->user_id;
        if (filled($data['email'] ?? null)) {
            $user = User::where('email', $data['email'])->first();
            if ($user && $user->role !== 'mahasiswa') {
                $notes[] = ($line ? "Baris {$line}: " : '') . 'email ' . $data['email'] . ' sudah digunakan oleh role lain, akun login tidak dibuat.';
            } else {
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
            $notes[] = ($line ? "Baris {$line}: " : '') . 'NIM ' . $data['nim'] . ' sudah terhubung dengan akun lain, data akademik tetap diperbarui tanpa mengganti akun.';
            $payload['user_id'] = $mahasiswa->user_id;
        }

        $mahasiswa->fill($payload);
        $mahasiswa->syncProfileStatus();
        $mahasiswa->save();

        return $mahasiswa;
    }

    private function cleanText($value): ?string
    {
        if ($value === null) return null;
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function validEmailOrNull($value): ?string
    {
        $value = $this->cleanText($value);
        return $value && Validator::make(['email' => $value], ['email' => 'email'])->passes() ? $value : null;
    }

    private function normalizeJenisKelamin($value): ?string
    {
        $value = strtolower((string) $this->cleanText($value));
        if (in_array($value, ['l', 'laki', 'laki-laki', 'lakilaki', 'pria'], true)) return 'Laki-laki';
        if (in_array($value, ['p', 'perempuan', 'wanita'], true)) return 'Perempuan';
        return $this->cleanText($value);
    }

    private function normalizeStatusMahasiswa($value): string
    {
        $value = strtolower((string) $this->cleanText($value));
        return in_array($value, ['aktif', 'cuti', 'lulus'], true) ? $value : 'aktif';
    }

    private function toIntegerOrNull($value): ?int
    {
        $value = $this->cleanText($value);
        if ($value === null) return null;
        $value = preg_replace('/[^0-9-]/', '', $value);
        return $value === '' ? null : (int) $value;
    }

    private function toFloatOrNull($value): ?float
    {
        $value = $this->cleanText($value);
        if ($value === null) return null;
        $value = str_replace(',', '.', $value);
        $value = preg_replace('/[^0-9.-]/', '', $value);
        return $value === '' ? null : (float) $value;
    }

    private function parseTanggalLahir($value): ?string
    {
        $value = $this->cleanText($value);
        if (!$value) return null;

        if (is_numeric($value)) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (\Throwable $e) {
                return null;
            }
        }

        $months = [
            'januari' => 'January', 'februari' => 'February', 'maret' => 'March', 'april' => 'April',
            'mei' => 'May', 'juni' => 'June', 'juli' => 'July', 'agustus' => 'August',
            'september' => 'September', 'oktober' => 'October', 'november' => 'November', 'desember' => 'December',
        ];
        $normalized = strtolower($value);
        $normalized = str_ireplace(array_keys($months), array_values($months), $normalized);

        try {
            return \Carbon\Carbon::parse($normalized)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function friendlyImportError(string $message): string
    {
        if (str_contains($message, 'Duplicate entry')) return 'Ada data unik yang sudah dipakai.';
        if (str_contains($message, 'cannot be null')) return 'Ada kolom wajib yang kosong.';
        if (str_contains($message, 'SQLSTATE')) return 'Data tidak sesuai format database.';
        return $message;
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
