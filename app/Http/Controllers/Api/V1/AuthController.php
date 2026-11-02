<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\UserResource;
use App\Models\User;
use App\Support\PercobaanLogin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Login aplikasi mobile staf (semua akun yang bisa login web). Token Sanctum berkemampuan "staf", satu per perangkat,
 * berlaku MASA_TOKEN_HARI hari. Pembatasan percobaan gagal sama dengan login web (PercobaanLogin).
 */
class AuthController extends Controller
{
    public const MASA_TOKEN_HARI = 30;

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ], $this->pesanValidasi(), ['username' => 'Username atau email', 'password' => 'Kata sandi', 'device_name' => 'Nama perangkat']);

        $percobaan = new PercobaanLogin($data['username'], $request->ip());
        $percobaan->pastikanBelumTerkunci();

        // Kolom login menerima username maupun email
        $kolom = filter_var($data['username'], FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        $user = User::firstWhere($kolom, $data['username']);
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            $percobaan->gagal();
            Log::warning('Login API gagal', ['username' => $data['username'], 'ip' => $request->ip(), 'perangkat' => $data['device_name']]);
            throw ValidationException::withMessages(['username' => 'Username/email atau kata sandi salah.']);
        }
        if (! $user->bolehMasuk()) {
            Log::warning('Login API ditolak: akun nonaktif / tanpa hak akses', ['username' => $data['username'], 'status' => $user->status, 'ip' => $request->ip()]);
            throw ValidationException::withMessages(['username' => $user->alasanTidakBolehMasuk()]);
        }

        $percobaan->berhasil();
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        $token = $user->createToken($data['device_name'], ['staf'], now()->addDays(self::MASA_TOKEN_HARI));

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'user' => new UserResource($user->load(['roles', 'lokasi'])),
        ]);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load(['roles', 'lokasi']));
    }

    /** Cabut token perangkat ini saja; perangkat lain tetap login. */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Berhasil keluar']);
    }
}
