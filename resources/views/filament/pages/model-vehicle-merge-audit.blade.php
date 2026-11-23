<x-filament-panels::page>
    <div class="space-y-4">
        @if ($previewReport !== null)
            <div class="fi-section rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <h3 class="text-base font-bold">
                    Preview Merge — {{ $pairs[$previewIndex]['a']['model']->name }} ↔ {{ $pairs[$previewIndex]['b']['model']->name }}
                </h3>
                <dl class="mt-3 grid grid-cols-2 gap-2 text-sm md:grid-cols-4">
                    @foreach ($previewReport as $key => $value)
                        <div>
                            <dt class="text-xs text-gray-500">{{ str_replace('_', ' ', $key) }}</dt>
                            <dd class="font-semibold">{{ is_bool($value) ? ($value ? 'ya' : 'tidak') : $value }}</dd>
                        </div>
                    @endforeach
                </dl>
                <div class="mt-3 flex flex-wrap items-end gap-3">
                    <label class="text-sm">
                        <span class="block text-xs text-gray-500">Nama baru keeper (opsional)</span>
                        <input type="text" wire:model.live="renameTo"
                            class="mt-1 rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-white/10 dark:bg-white/5"
                            placeholder="{{ $pairs[$previewIndex]['keeper_id'] === $pairs[$previewIndex]['a']['model']->id ? $pairs[$previewIndex]['a']['model']->name : $pairs[$previewIndex]['b']['model']->name }}">
                    </label>
                    <button type="button" wire:click="mergePair({{ $previewIndex }})" wire:confirm="Gabungkan pasangan ini? Tindakan ini transaksional dan menghapus record duplikat."
                        class="rounded-lg bg-danger-600 px-4 py-2 text-sm font-semibold text-white">
                        Merge Sekarang
                    </button>
                    <button type="button" wire:click="closePreview"
                        class="rounded-lg border border-gray-300 px-4 py-2 text-sm dark:border-white/10">
                        Tutup
                    </button>
                </div>
            </div>
        @endif

        @forelse ($pairs as $i => $pair)
            <div class="fi-section rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $pair['brand'] }}</span>
                    <span class="text-xs text-gray-400">saran keeper: #{{ $pair['keeper_id'] }}</span>
                </div>
                <div class="mt-3 grid gap-3 md:grid-cols-2">
                    @foreach (['a', 'b'] as $side)
                        @php $m = $pair[$side]['model']; @endphp
                        <label class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 {{ ($keeperChoice[$i] ?? null) === $m->id ? 'border-primary-500 bg-primary-50/50 dark:bg-primary-500/10' : 'border-gray-200 dark:border-white/10' }}">
                            <input type="radio" wire:model.live="keeperChoice.{{ $i }}" value="{{ $m->id }}" class="mt-1">
                            <span>
                                <span class="block font-semibold">{{ $m->name }} <span class="text-xs text-gray-400">#{{ $m->id }}</span></span>
                                <span class="mt-1 block text-xs text-gray-500">
                                    {{ $pair[$side]['vehicles'] }} user · {{ $pair[$side]['types'] }} varian · {{ $pair[$side]['obd'] }} link OBD
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>
                <div class="mt-3 flex gap-2">
                    <button type="button" wire:click="previewMerge({{ $i }})"
                        class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold dark:border-white/10">
                        Preview
                    </button>
                </div>
            </div>
        @empty
            <div class="rounded-xl border border-gray-200 p-8 text-center text-gray-500 dark:border-white/10">
                Tidak ada kandidat duplikat. 🎉
            </div>
        @endforelse
    </div>
</x-filament-panels::page>
