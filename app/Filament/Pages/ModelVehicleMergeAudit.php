<?php

namespace App\Filament\Pages;

use App\Models\ModelVehicle;
use App\Services\ModelVehicleMergeService;
use Filament\Pages\Page;
use Filament\Notifications\Notification;

/**
 * Halaman "Audit Duplikat Model" — daftar pasangan model katalog kandidat
 * duplikat hasil auto-create import GAIKINDO. Alur: review pasangan →
 * preview dry-run → merge (transaksional, hanya pasangan yang disetujui).
 */
class ModelVehicleMergeAudit extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-m-arrows-right-left';

    protected static string | \UnitEnum | null $navigationGroup = 'Referensi Kendaraan';

    protected static ?string $navigationLabel = 'Audit Duplikat Model';

    protected static ?string $title = 'Audit Duplikat Model (Merge)';

    protected static ?int $navigationSort = 8;

    protected string $view = 'filament.pages.model-vehicle-merge-audit';

    /** @var array<int, array<string, mixed>> hasil auditCandidates() */
    public array $pairs = [];

    /** @var array<int, int> index pasangan → id model keeper yang dipilih admin */
    public array $keeperChoice = [];

    public ?int $previewIndex = null;

    /** @var array<string, mixed>|null ringkasan dry-run pasangan yang di-preview */
    public ?array $previewReport = null;

    public string $renameTo = '';

    public function mount(): void
    {
        $this->loadCandidates();
    }

    public function loadCandidates(): void
    {
        $this->pairs = app(ModelVehicleMergeService::class)->auditCandidates();
        $this->keeperChoice = [];
        foreach ($this->pairs as $i => $pair) {
            $this->keeperChoice[$i] = $pair['keeper_id'];
        }
        $this->closePreview();
    }

    /** Pasangan ke-i sebagai [keeper, duplicate] sesuai pilihan admin. */
    protected function resolvePair(int $index): array
    {
        $pair = $this->pairs[$index] ?? null;
        if ($pair === null) {
            throw new \InvalidArgumentException('Pasangan tidak ditemukan.');
        }

        $otherId = $pair['a']['model']->id === (int) $this->keeperChoice[$index]
            ? $pair['b']['model']->id
            : $pair['a']['model']->id;

        return [
            ModelVehicle::findOrFail((int) $this->keeperChoice[$index]),
            ModelVehicle::findOrFail($otherId),
        ];
    }

    public function previewMerge(int $index): void
    {
        [$keeper, $duplicate] = $this->resolvePair($index);

        $report = app(ModelVehicleMergeService::class)->dryRun($keeper, $duplicate);
        $this->previewIndex = $index;
        $this->previewReport = $report->toSummary();
    }

    public function closePreview(): void
    {
        $this->previewIndex = null;
        $this->previewReport = null;
        $this->renameTo = '';
    }

    public function mergePair(int $index): void
    {
        [$keeper, $duplicate] = $this->resolvePair($index);
        $dupName = $duplicate->name; // simpan sebelum record dihapus

        $report = app(ModelVehicleMergeService::class)->merge(
            $keeper,
            $duplicate,
            $this->renameTo !== '' ? $this->renameTo : null,
        );

        Notification::make()
            ->title('Merge selesai')
            ->body(sprintf(
                '%s ← %s. Kendaraan pindah: %d. Detail lengkap di log.',
                $report->keeper->name,
                $dupName,
                $report->vehiclesMoved,
            ))
            ->success()
            ->send();

        $this->loadCandidates();
    }
}
