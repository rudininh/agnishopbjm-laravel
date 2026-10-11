<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable catalog coordinator; marketplace mutation remains owned by the child. */
class StockHubTiktokVariantBatchService
{
    private const RUNS = 'stock_hub_tiktok_variant_batches';
    private const ITEMS = 'stock_hub_tiktok_variant_batch_items';
    private const CLAIMS = 'stock_hub_tiktok_variant_batch_claims';
    private const REQUESTS = 'stock_hub_tiktok_variant_batch_requests';
    private const RUNNING_CHILD = ['preparing', 'scanning', 'uploading', 'submitting', 'verifying'];
    private const UNSAFE_CHILD = ['submitted_unverified', 'under_review'];

    public function __construct(private StockHubTiktokVariantBatchSelection $selection, private StockHubTiktokVariantService $children) {}

    public function start(string $requestKey): array
    {
        $id = DB::transaction(function () use ($requestKey): string {
            DB::table(self::CLAIMS)->insertOrIgnore(['key' => 'catalog']);
            $claim = DB::table(self::CLAIMS)->where('key', 'catalog')->lockForUpdate()->first();
            $repeat = DB::table(self::REQUESTS)->where('request_key', $requestKey)->value('batch_id');
            if ($repeat) { return $repeat; }
            if ($claim->run_id && $this->row($claim->run_id)->status !== 'completed') {
                DB::table(self::REQUESTS)->insert(['request_key' => $requestKey, 'batch_id' => $claim->run_id]);
                return $claim->run_id;
            }
            $products = $this->selection->forProducts();
            $id = (string) Str::uuid();
            DB::table(self::RUNS)->insert(['id' => $id, 'request_key' => $requestKey, 'status' => $products ? 'running' : 'completed',
                'message' => $products ? 'Katalog tersimpan siap diproses.' : 'Tidak ada produk Agni dalam katalog tersimpan.', 'created_at' => now(), 'updated_at' => now()]);
            DB::table(self::REQUESTS)->insert(['request_key' => $requestKey, 'batch_id' => $id]);
            foreach ($products as $position => $product) {
                $coverage = $product['coverage'];
                $status = match ($coverage['state'] ?? 'unknown') {
                    'missing_variants' => 'queued', 'complete' => 'skipped_complete', 'missing_product' => 'missing_product', default => 'unknown',
                };
                DB::table(self::ITEMS)->insert(['batch_id' => $id, 'position' => $position, 'source_product_id' => $product['source_product_id'],
                    'name' => mb_substr($product['name'], 0, 500), 'request_key' => (string) Str::uuid(), 'status' => $status,
                    'missing_count' => isset($coverage['missing_count']) ? max(0, (int) $coverage['missing_count']) : null,
                    'message' => mb_substr($coverage['message'] ?? '', 0, 500), 'processed' => DB::raw($status !== 'queued' ? 'true' : 'false'), 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table(self::CLAIMS)->where('key', 'catalog')->update(['run_id' => $products ? $id : null, 'owner' => null, 'expires_at' => null]);
            return $id;
        });
        return $this->show($id);
    }

    public function active(): ?array
    {
        $id = DB::table(self::CLAIMS)->where('key', 'catalog')->value('run_id');
        return $id ? $this->show($id) : null;
    }

    public function show(string $id): array
    {
        $run = $this->row($id);
        $rows = DB::table(self::ITEMS)->where('batch_id', $id)->orderBy('position')->get();
        // A per-product status check can finish an already processed child later.
        // Overlay only the same saved identity using local reads; never step or replace it.
        $children = DB::table('stock_hub_tiktok_variant_runs')->whereIn('id', $rows->where('processed', true)->pluck('child_run_id')->filter()->all())
            ->get(['id', 'source_product_id', 'status', 'state', 'attempted_at', 'accepted_at'])->keyBy('id');
        $current = $rows->first(fn ($item) => ! $item->processed);
        $summary = ['products_total' => $rows->count(), 'products_processed' => 0, 'variants_verified' => 0,
            'variants_pending' => 0, 'variants_failed' => 0, 'variants_skipped' => 0, 'products_need_attention' => 0];
        $items = [];
        foreach ($rows as $item) {
            $result = $item->result === null ? null : json_decode($item->result, true, 512, JSON_THROW_ON_ERROR);
            $child = $children->get($item->child_run_id);
            if ($child && (string) $child->source_product_id === (string) $item->source_product_id) {
                $state = json_decode($child->state, true, 512, JSON_THROW_ON_ERROR);
                $item->status = $child->attempted_at && ! $child->accepted_at && $child->status !== 'rejected' ? 'submitted_unverified' : $child->status;
                $item->message = mb_substr($state['message'] ?? $item->message, 0, 500);
                $result = $state['result'] ?? null;
            }
            $summary['products_processed'] += (int) $item->processed;
            $attention = $item->processed && ! in_array($item->status, ['success', 'exists', 'skipped_complete'], true);
            $summary['products_need_attention'] += (int) $attention;
            $variants = $result['variants'] ?? [];
            foreach ($variants as $variant) {
                $bucket = match ($variant['status'] ?? '') { 'updated' => 'variants_verified', 'under_review' => 'variants_pending',
                    'failed' => 'variants_failed', 'skipped' => 'variants_skipped', default => null };
                if ($bucket) { $summary[$bucket]++; }
            }
            if (! $variants && $item->missing_count) {
                if (! $item->processed || in_array($item->status, self::UNSAFE_CHILD, true)) { $summary['variants_pending'] += $item->missing_count; }
                elseif ($attention) { $summary['variants_failed'] += $item->missing_count; }
            }
            $items[] = ['source_product_id' => $item->source_product_id, 'name' => $item->name, 'status' => $item->status,
                'child_run_id' => $item->child_run_id, 'missing_count' => $item->missing_count === null ? null : (int) $item->missing_count,
                'message' => $item->message, 'result' => $result];
        }
        $claim = DB::table(self::CLAIMS)->where('key', 'catalog')->where('run_id', $id)->first();
        $owned = $claim && $claim->owner && $claim->expires_at > now()->toDateTimeString();
        $status = $run->status;
        if ($run->pause_requested && $status !== 'completed') { $status = $owned ? 'pausing' : 'paused'; }
        $delay = $current ? max(0, min(5000, (int) $current->retry_at - now()->getTimestampMs())) : 0;
        if ($owned) { $delay = max($delay, 1000); }
        return ['run_id' => $id, 'status' => $status, 'message' => $run->message, 'can_continue' => $status === 'running',
            'package_template' => config('stock_hub_package'),
            'next_step_after_ms' => $delay, 'summary' => $summary, 'items' => $items,
            'current_source_product_id' => $current?->source_product_id];
    }

    public function pause(string $id): array
    {
        DB::transaction(function () use ($id): void {
            $run = DB::table(self::RUNS)->where('id', $id)->lockForUpdate()->first();
            abort_unless($run, 404);
            if ($run->status !== 'completed') {
                DB::table(self::RUNS)->where('id', $id)->update(['pause_requested' => DB::raw('true'), 'message' => 'Jeda diminta. Progres tersimpan.', 'updated_at' => now()]);
            }
        });
        return $this->show($id);
    }

    public function resume(string $id): array
    {
        DB::transaction(function () use ($id): void {
            // Match step's lock order. Do not undo a pause while a child call is outstanding.
            $claim = DB::table(self::CLAIMS)->where('key', 'catalog')->lockForUpdate()->first();
            $run = DB::table(self::RUNS)->where('id', $id)->lockForUpdate()->first();
            abort_unless($run, 404);
            if ($run->status === 'completed' || ($claim?->owner && $claim->expires_at > now()->toDateTimeString())) { return; }
            abort_unless($claim?->run_id === $id, 409, 'Batch lain memiliki katalog.');
            DB::table(self::RUNS)->where('id', $id)->update(['status' => 'running', 'pause_requested' => DB::raw('false'),
                'message' => 'Batch dilanjutkan atas permintaan pengguna.', 'updated_at' => now()]);
        });
        return $this->show($id);
    }

    public function step(string $id): array
    {
        $this->row($id);
        $owner = (string) Str::uuid();
        $claimed = DB::transaction(function () use ($id, $owner): bool {
            $claim = DB::table(self::CLAIMS)->where('key', 'catalog')->lockForUpdate()->first();
            $run = DB::table(self::RUNS)->where('id', $id)->lockForUpdate()->first();
            if ($run->status !== 'running' || $run->pause_requested || $claim?->run_id !== $id
                || ($claim->owner && $claim->expires_at > now()->toDateTimeString())) { return false; }
            DB::table(self::CLAIMS)->where('key', 'catalog')->update(['owner' => $owner, 'expires_at' => now()->addMinutes(10)]);
            return true;
        });
        if (! $claimed) { return $this->show($id); }
        try {
            $item = DB::table(self::ITEMS)->where('batch_id', $id)->whereRaw('processed = false')->orderBy('position')->first();
            if ($item && (int) $item->retry_at > now()->getTimestampMs()) { return $this->show($id); }
            if ($item) {
                $this->selection->assertReady();
                if ($this->row($id)->pause_requested) { return $this->show($id); }
                if (! $item->child_run_id) {
                    // A saved child identity exists before any child start. start() dedupes
                    // that identity even if the process died before saving its response.
                    $savedId = DB::table('stock_hub_tiktok_variant_runs')->where('request_key', $item->request_key)->value('id');
                    $existing = $savedId ? $this->children->show($savedId) : $this->children->source($item->source_product_id);
                    $child = $existing && ($savedId || in_array($existing['status'], [...self::RUNNING_CHILD, ...self::UNSAFE_CHILD], true))
                        ? $existing : $this->children->start($item->source_product_id, $item->request_key);
                } else {
                    $child = $this->children->show($item->child_run_id);
                    if (in_array($child['status'], self::RUNNING_CHILD, true) && ($child['can_continue'] ?? false)) {
                        $child = $this->children->step($item->child_run_id);
                    }
                }
                $this->owned($id, $owner, function () use ($id, $item, $child): void {
                    $running = in_array($child['status'], self::RUNNING_CHILD, true) && ($child['can_continue'] ?? false);
                    $delay = $child['next_step_after_ms'] ?? 0;
                    $delay = is_int($delay) && $delay >= 0 && $delay <= 5000 ? $delay : 0;
                    DB::table(self::ITEMS)->where('id', $item->id)->update(['child_run_id' => $child['run_id'],
                        'status' => $child['status'], 'message' => mb_substr($child['message'] ?? '', 0, 500),
                        'result' => isset($child['result']) ? json_encode($child['result'], JSON_THROW_ON_ERROR) : null,
                        'processed' => DB::raw($running ? 'false' : 'true'), 'retry_at' => $delay ? now()->getTimestampMs() + $delay : null, 'updated_at' => now()]);
                    if (($child['failure_kind'] ?? null) === 'coordination_unavailable') {
                        DB::table(self::RUNS)->where('id', $id)->update(['status' => 'paused_error',
                            'message' => 'Koordinasi marketplace belum siap. Progres tersimpan; lanjutkan secara eksplisit setelah diperiksa.', 'updated_at' => now()]);
                    }
                });
            }
            $this->owned($id, $owner, function () use ($id): void {
                $run = $this->row($id);
                if ($run->status === 'paused_error') { return; }
                if (! DB::table(self::ITEMS)->where('batch_id', $id)->whereRaw('processed = false')->exists()) {
                    DB::table(self::RUNS)->where('id', $id)->update(['status' => 'completed', 'message' => 'Pemeriksaan katalog selesai. Periksa produk yang memerlukan perhatian.', 'updated_at' => now()]);
                    DB::table(self::CLAIMS)->where('key', 'catalog')->update(['run_id' => null, 'owner' => null, 'expires_at' => null]);
                } elseif ($run->pause_requested) {
                    DB::table(self::RUNS)->where('id', $id)->update(['status' => 'paused', 'message' => 'Batch dijeda. Progres tersimpan.', 'updated_at' => now()]);
                }
            });
        } catch (MarketplaceOperationBusy) {
            $this->owned($id, $owner, function () use ($id): void {
                DB::table(self::ITEMS)->where('batch_id', $id)->whereRaw('processed = false')->orderBy('position')->limit(1)
                    ->update(['retry_at' => now()->getTimestampMs() + 5000]);
            });
        } catch (\Throwable) {
            $this->owned($id, $owner, function () use ($id): void {
                DB::table(self::RUNS)->where('id', $id)->update(['status' => 'paused_error',
                    'message' => 'Batch dijeda karena akun, koneksi, atau koordinasi belum siap. Periksa status lalu lanjutkan secara eksplisit.', 'updated_at' => now()]);
            });
        } finally {
            DB::table(self::CLAIMS)->where('key', 'catalog')->where('run_id', $id)->where('owner', $owner)->update(['owner' => null, 'expires_at' => null]);
        }
        return $this->show($id);
    }

    private function owned(string $id, string $owner, callable $action): void
    {
        DB::transaction(function () use ($id, $owner, $action): void {
            $claim = DB::table(self::CLAIMS)->where('key', 'catalog')->lockForUpdate()->first();
            if ($claim?->run_id === $id && $claim->owner === $owner) { $action(); }
        });
    }

    private function row(string $id): object
    {
        $row = DB::table(self::RUNS)->where('id', $id)->first(); abort_unless($row, 404, 'Batch katalog tidak ditemukan.'); return $row;
    }
}
