<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Foto servis — {id, url}. Identitas uploader tidak diekspos. */
class ServiceLogPhotoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => method_exists($this->resource, 'url') ? $this->resource->url() : ('/storage/' . ltrim($this->path, '/')),
        ];
    }
}
