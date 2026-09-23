<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ServiceLogPhotoResource;
use App\Models\ServiceLog;
use App\Models\ServiceLogPhoto;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Foto servis (Pro-only) — pola StationPhotoController:
 * multipart `photos[]` (maks 5, image, ±5 MB), owner + entitlement Pro → 403.
 * URL publik memakai pola PUBLIC_STORAGE_URL yang sama dengan foto SPKLU.
 */
class ServiceLogPhotoController extends Controller
{
    public const MAX_PHOTOS_PER_UPLOAD = 5;
    public const MAX_FILE_SIZE_KB = 5120; // ±5 MB

    public function store(Request $request, ServiceLog $serviceLog): JsonResponse
    {
        $user = Auth::user();
        if ($serviceLog->user_id !== $user->id) {
            return $this->forbidden('Unauthorized access to service log.');
        }
        if (! self::isPro($user)) {
            return $this->forbidden('Foto servis tersedia untuk pengguna Pro.');
        }

        $request->validate([
            'photos' => 'required|array|min:1|max:' . self::MAX_PHOTOS_PER_UPLOAD,
            'photos.*' => 'image|max:' . self::MAX_FILE_SIZE_KB,
        ]);

        $dir = "service-logs/{$serviceLog->id}";
        $created = [];
        foreach ($request->file('photos') as $file) {
            $path = $file->store($dir, 'public');
            $created[] = ServiceLogPhoto::create([
                'service_log_id' => $serviceLog->id,
                'path' => $path,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => count($created) . ' foto berhasil diunggah',
            'data' => ServiceLogPhotoResource::collection(collect($created)),
        ], 201);
    }

    public function destroy(ServiceLogPhoto $photo): JsonResponse
    {
        $user = Auth::user();
        $photo->loadMissing('serviceLog');
        if (! $photo->serviceLog || $photo->serviceLog->user_id !== $user->id) {
            return $this->forbidden('Unauthorized access to service log photo.');
        }

        if ($photo->path && Storage::disk('public')->exists($photo->path)) {
            Storage::disk('public')->delete($photo->path);
        }
        $photo->forceDelete();

        return response()->json([
            'success' => true,
            'message' => 'Photo deleted successfully',
        ]);
    }

    /** Entitlement Pro aktif — sumber flag server yang sama dengan `isAdFreeActive`. */
    public static function isPro(User $user): bool
    {
        return $user->userSubscriptions()
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 403);
    }
}
