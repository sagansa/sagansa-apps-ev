<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

/**
 * Autentikasi email+password (versi app lama yang masih beredar di store).
 *
 * Verifikasi email memakai pipeline BAWAAN Laravel (kontrak MustVerifyEmail):
 * register/resend → sendEmailVerificationNotification() → notifikasi standard
 * VerifyEmail (queueable) → link bertanda tangan ke route web 'verification.verify'
 * (EmailLinkVerificationController). Kontrak respons API dipertahankan identik
 * dengan implementasi OTP lama agar app versi lama tetap kompatibel.
 */
class AuthController extends Controller
{
    /**
     * Interval minimum antar pengiriman ulang email verifikasi (detik).
     */
    private const VERIFY_RESEND_SECONDS = 60;

    /**
     * Register a new user
     *
     * User belum bisa login sebelum email diverifikasi (link di email).
     * Email terdaftar tapi BELUM terverifikasi → kredensial diperbarui dan
     * link dikirim ulang (menyembuhkan user yang macet karena email gagal
     * terkirim pada percobaan pertama; dulunya terjebak error unique:users).
     */
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        // withTrashed: email yang soft-deleted juga tetap dianggap terdaftar,
        // setara perilaku rule unique:users (unique index DB).
        $existing = User::withTrashed()->where('email', $request->email)->first();

        if ($existing !== null) {
            if ($existing->hasVerifiedEmail()) {
                // Replika bentuk error validasi Laravel agar app lama tetap
                // menampilkan pesan "email sudah dipakai" seperti sebelumnya.
                return response()->json([
                    'message' => 'The email has already been taken.',
                    'errors' => ['email' => ['The email has already been taken.']],
                ], 422);
            }

            $existing->update([
                'name' => $request->name,
                'password' => Hash::make($request->password),
            ]);
            $user = $existing->refresh();
        } else {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
            ]);

            // Ensure default user role exists then assign
            $defaultRole = Role::firstOrCreate(
                ['name' => 'user', 'guard_name' => 'web']
            );
            $user->syncRoles([$defaultRole->name]);
        }

        $sendError = $this->deliverVerificationLink($user);
        if ($sendError !== null) {
            return $sendError;
        }

        return response()->json([
            'success' => true,
            'message' => 'User registered successfully. Please verify your email.',
            'data' => [
                'user' => $user,
            ],
        ], 201);
    }

    /**
     * Login an existing user
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (! Auth::attempt($request->only('email', 'password'))) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid login credentials',
            ], 401);
        }

        $user = Auth::user();

        // Check if email is verified
        if (! $user->hasVerifiedEmail()) {
            return response()->json([
                'success' => false,
                'message' => 'Please verify your email address',
                'data' => [
                    'needs_verification' => true,
                    'email' => $user->email,
                ],
            ], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => $user,
                'token' => $token,
                'token_type' => 'Bearer',
            ],
        ]);
    }

    /**
     * Kirim ulang email verifikasi (link sekali-tap bawaan Laravel).
     */
    public function verifyEmail(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'success' => true,
                'message' => 'Email already verified',
            ]);
        }

        $rateLimitKey = "verify:sent:{$user->email}";
        if (Cache::has($rateLimitKey)) {
            return response()->json([
                'success' => false,
                'message' => 'Mohon tunggu 1 menit sebelum mengirim ulang kode.',
            ], 429);
        }

        $sendError = $this->deliverVerificationLink($user);
        if ($sendError !== null) {
            return $sendError;
        }

        // Rate key hanya terpasang SETELAH pengiriman sukses — percobaan yang
        // gagal karena SMTP tidak mengunci user dari mencoba lagi.
        Cache::put($rateLimitKey, true, now()->addSeconds(self::VERIFY_RESEND_SECONDS));

        return response()->json([
            'success' => true,
            'message' => 'Link verifikasi terkirim ke email Anda.',
        ]);
    }

    /**
     * @deprecated Konfirmasi via kode OTP dihentikan — verifikasi memakai link
     * sekali-tap dari email bawaan Laravel. Endpoint tetap ada supaya app lama
     * menerima JSON terstruktur, bukan 404.
     */
    public function confirmVerification(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Verifikasi melalui kode OTP sudah dihentikan. Silakan buka tautan verifikasi dari email Anda.',
        ], 410);
    }

    /**
     * @deprecated No-op: reset password via email tidak dipakai — auth mobile
     * ditangani Google/Gmail Sign-In (keputusan owner, 2026-11-19). Endpoint
     * tetap dibuka supaya versi app lama menerima kontrak respons yang sama,
     * bukan error — bukan mengirim apa pun.
     *
     * Balasan sengaja dibuat generic 200 (anti user-enumeration): email
     * terdaftar maupun tidak memperoleh respons identik, supaya endpoint ini
     * tidak bisa dipakai membedakan email mana yang ada di sistem.
     *
     * TIDAK lagi memanggil Password::sendResetLink karena notifikasi bawaan
     * Laravel membangun link via route `password.reset` yang sudah dihapus
     * saat self-service auth web ditutup → request dulu 500, bukan kirim
     * email. Kalau nanti reset password via email dibutuhkan lagi, route
     * tersebut harus dihidupkan kembali lebih dulu.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Jika email terdaftar, link reset password telah dikirim.',
        ]);
    }

    /**
     * @deprecated OTP reset sudah lama dimatikan, dan jalur penggantinya
     * (link reset web via forgotPassword → Password::sendResetLink) kini
     * juga tidak dipakai lagi — reset password via email dihentikan karena
     * auth mobile memakai Google/Gmail Sign-In. Endpoint dipertahankan
     * sementara utk kompat versi app lama; password baru TIDAK akan berubah
     * karena tidak ada OTP yang tersimpan lagi.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'otp' => ['required', 'string', 'size:6'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $cachedOtp = Cache::get("reset:{$request->email}");

        if (! $cachedOtp || ! hash_equals((string) $cachedOtp, $request->otp)) {
            return response()->json([
                'success' => false,
                'message' => 'Kode reset salah atau sudah kedaluwarsa.',
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        $user->update([
            'password' => Hash::make($request->password),
        ]);

        Cache::forget("reset:{$request->email}");
        Cache::forget("reset:sent:{$request->email}");

        return response()->json([
            'success' => true,
            'message' => 'Password berhasil direset. Silakan masuk.',
        ]);
    }

    /**
     * Refresh the authentication token
     */
    public function refreshToken(Request $request)
    {
        // This method would typically be handled by Laravel Sanctum
        // by creating a new token and invalidating the old one
        $user = $request->user();

        // Revoke all tokens
        $user->tokens()->delete();

        // Create a new token
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Token refreshed successfully',
            'data' => [
                'token' => $token,
                'token_type' => 'Bearer',
            ],
        ]);
    }

    /**
     * Logout the user
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Kirim notifikasi verifikasi bawaan Laravel (VerifyEmail, queueable).
     * Kegagalan transport email tidak dibiarkan jadi exception 500 yang tak
     * terkendali — direkam di log dan dilaporkan sebagai 503 tanpa mengunci
     * rate-limit, sehingga user bisa langsung mencoba ulang.
     *
     * @return JsonResponse|null null bila pengiriman sukses.
     */
    private function deliverVerificationLink(User $user): ?JsonResponse
    {
        try {
            $user->sendEmailVerificationNotification();
        } catch (\Throwable $e) {
            Log::error('Gagal mengirim email verifikasi', [
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengirim email verifikasi. Silakan coba beberapa saat lagi.',
            ], 503);
        }

        return null;
    }
}
