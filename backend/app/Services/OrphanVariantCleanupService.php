<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class OrphanVariantCleanupService
{
    public function __construct(private OrphanVariantGateway $gateway, private OrphanVariantClassifier $classifier) {}

    public function start(string $accountKey): array
    {
        abort_unless(in_array($accountKey, OrphanVariantClassifier::TARGETS, true), 422, 'Akun tujuan tidak didukung.');
        $id = (string) Str::uuid();
        DB::table('orphan_variant_cleanup_runs')->insert(['id' => $id, 'account_key' => $accountKey, 'status' => 'scanning',
            'state' => json_encode(['groups' => [], 'pending' => [], 'cursor' => null, 'pages' => [], 'catalog_complete' => false, 'selection' => []]),
            'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        return $this->show($id, $accountKey);
    }

    public function show(string $runId, string $accountKey): array
    {
        [$row, $state] = $this->load($runId, $accountKey);
        $items = [];
        foreach ($state['groups'] as $group) $items = array_merge($items, $group['items']);
        return ['run_id' => $runId, 'account_key' => $accountKey, 'status' => $row->status,
            'revision' => $state['revision'] ?? '', 'items' => $items,
            'summary' => array_count_values(array_column($items, 'status')), 'products_scanned' => count($state['groups']),
            'products_pending' => count($state['pending']), 'message' => $state['message'] ?? '', 'expires_at' => $row->expires_at];
    }

    public function scan(string $runId, string $accountKey): array
    {
        return $this->locked($runId, function () use ($runId, $accountKey) {
            [$row, $state] = $this->load($runId, $accountKey);
            if ($row->status !== 'scanning') return $this->show($runId, $accountKey);
            try {
                if (! $state['pending'] && ! $state['catalog_complete']) {
                    $cursorKey = $state['cursor'] ?? '<start>';
                    if (in_array($cursorKey, $state['pages'], true)) throw new \RuntimeException('Cursor berulang.');
                    $page = $this->gateway->catalogPage($accountKey, $state['cursor']);
                    $state['pages'][] = $cursorKey;
                    $state['pending'] = array_values(array_diff($page['products'], array_map('strval', array_keys($state['groups']))));
                    $state['cursor'] = $page['next_cursor'];
                    $state['catalog_complete'] = $page['complete'];
                }
                // One product per request bounds HTTP time and provides resumable progress.
                if ($state['pending']) {
                    $productId = (string) array_shift($state['pending']);
                    try {
                        $plan = $this->classifier->classify($this->gateway->snapshot($accountKey, $productId));
                        $state['groups'][$productId] = ['revision' => $plan['revision'], 'items' => $plan['items'], 'status' => 'preview'];
                    } catch (Throwable) {
                        $state['groups'][$productId] = ['revision' => '', 'status' => 'preview', 'items' => [[
                            'item_id' => hash('sha256', $accountKey.'|'.$productId), 'product_id' => $productId,
                            'product_name' => '', 'source_product_id' => '', 'source_name' => '', 'variant_id' => '', 'name' => '', 'seller_sku' => '',
                            'status' => 'blocked', 'reason' => 'Detail akun/produk tidak dapat diverifikasi. Segarkan token/katalog.',
                        ]]];
                    }
                }
                $ready = ! $state['pending'] && $state['catalog_complete'];
                if ($ready) $state['revision'] = $this->classifier->revision($state['groups']);
                $this->save($runId, $ready ? 'ready' : 'scanning', $state);
                if ($ready) DB::table('orphan_variant_cleanup_runs')->where('id', $runId)->update(['expires_at' => now()->addHour()]);
            } catch (Throwable) {
                $state['message'] = 'Pemindaian katalog tidak lengkap. Periksa token akun lalu buat preview baru; penghapusan dinonaktifkan.';
                $this->save($runId, 'scan_failed', $state);
            }
            return $this->show($runId, $accountKey);
        });
    }

    public function submit(string $runId, string $accountKey, string $revision, array $itemIds): array
    {
        return $this->locked($runId, function () use ($runId, $accountKey, $revision, $itemIds) {
            [$row, $state] = $this->load($runId, $accountKey);
            abort_unless($row->status === 'ready' && Carbon::parse($row->expires_at)->isFuture()
                && hash_equals($state['revision'] ?? '', $revision), 409, 'Preview berubah, kedaluwarsa, atau sudah digunakan.');
            $eligible = [];
            foreach ($state['groups'] as $group) foreach ($group['items'] as $item) if ($item['status'] === 'eligible') $eligible[] = $item['item_id'];
            abort_if(! $itemIds || array_diff($itemIds, $eligible) || count(array_unique($itemIds)) !== count($itemIds), 422, 'Pilihan bukan kandidat aman preview ini.');
            $state['selection'] = $itemIds;
            $this->save($runId, 'running', $state);
            return $this->show($runId, $accountKey);
        });
    }

    public function step(string $runId, string $accountKey): array
    {
        return $this->locked($runId, function () use ($runId, $accountKey) {
            [$row, $state] = $this->load($runId, $accountKey);
            if ($row->status !== 'running') return $this->show($runId, $accountKey);
            foreach ($state['groups'] as $productId => &$group) {
                $selected = array_values(array_filter($group['items'], fn ($i) => $i['status'] === 'eligible' && in_array($i['item_id'], $state['selection'], true)));
                if (! $selected) { $group['status'] = 'done'; continue; }
                if ($group['status'] === 'done') continue;
                $key = $accountKey.':'.$productId;
                // An interrupted attempted request must never be replayed, even by a new run.
                if ($group['status'] === 'attempted') {
                    $this->outcome($group, $state['selection'], 'unverified', 'Proses sebelumnya terputus; periksa marketplace sebelum tindakan lanjutan.');
                } elseif (! $this->claimProduct($key, $runId)) {
                    $this->outcome($group, $state['selection'], 'blocked', 'Produk sedang diproses atau memiliki penghapusan yang belum terverifikasi.');
                } else {
                    $attempted = false;
                    try {
                        $before = $this->gateway->snapshot($accountKey, (string) $productId);
                        $plan = $this->classifier->classify($before);
                        $eligibleIds = array_column(array_filter($plan['items'], fn ($i) => $i['status'] === 'eligible'), 'variant_id');
                        if (Carbon::parse($row->expires_at)->isPast() || ! hash_equals($group['revision'], $plan['revision'])
                            || array_diff(array_column($selected, 'variant_id'), $eligibleIds)) {
                            $this->outcome($group, $state['selection'], 'stale', 'Katalog berubah setelah preview. Buat preview baru.');
                        } else {
                            $batches = $accountKey === 'tiktok-agnishopbjm' ? [array_column($selected, 'variant_id')]
                                : [[$selected[0]['variant_id']]];
                            foreach ($batches as $ids) {
                                $fresh = $this->gateway->snapshot($accountKey, (string) $productId);
                                if ($this->classifier->revision($fresh) !== $this->classifier->revision($before)) {
                                    $this->outcome($group, $state['selection'], 'stale', 'Katalog berubah selama proses.');
                                    break;
                                }
                                $attempted = true;
                                $group['status'] = 'attempted';
                                $this->save($runId, 'running', $state);
                                DB::table('orphan_variant_cleanup_guards')->where('product_key', $key)->update(['status' => 'attempted', 'updated_at' => now()]);
                                $this->gateway->delete($accountKey, $fresh, $ids);
                                $after = $this->gateway->snapshot($accountKey, (string) $productId);
                                $remaining = array_column($after['target_variants'], 'id');
                                $expected = array_values(array_diff(array_column($fresh['target_variants'], 'id'), $ids));
                                sort($remaining, SORT_STRING); sort($expected, SORT_STRING);
                                if (! $after['target_complete'] || $remaining !== $expected) throw new \RuntimeException('Unverified deletion');
                                $this->gateway->reconcileVerified($accountKey, $fresh, $after, $ids);
                                foreach ($group['items'] as &$item) {
                                    if (in_array($item['variant_id'], $ids, true)) { $item['status'] = 'deleted'; $item['reason'] = 'Terhapus dan terverifikasi di marketplace.'; }
                                }
                                unset($item);
                                $before = $after;
                                $group['revision'] = $this->classifier->classify($after)['revision'];
                                $attempted = false;
                            }
                        }
                    } catch (Throwable) {
                        $this->outcome($group, $state['selection'], $attempted ? 'unverified' : 'blocked',
                            $attempted ? 'Hasil penghapusan belum pasti; proses produk dihentikan. Periksa marketplace, jangan ulangi otomatis.' : 'Data terbaru tidak dapat diverifikasi; tidak ada penghapusan baru.');
                    } finally {
                        DB::table('orphan_variant_cleanup_guards')->where('product_key', $key)->where('run_id', $runId)
                            ->update(['status' => $attempted ? 'unverified' : 'clear', 'updated_at' => now()]);
                    }
                }
                $group['status'] = count(array_filter($group['items'], fn ($i) => $i['status'] === 'eligible'
                    && in_array($i['item_id'], $state['selection'], true))) > 0 ? 'preview' : 'done';
                break;
            }
            unset($group);
            $pending = false;
            foreach ($state['groups'] as $group) {
                if ($group['status'] !== 'done' && array_intersect(array_column($group['items'], 'item_id'), $state['selection'])) $pending = true;
            }
            $this->save($runId, $pending ? 'running' : 'completed', $state);
            return $this->show($runId, $accountKey);
        });
    }

    private function outcome(array &$group, array $selection, string $status, string $reason): void
    {
        foreach ($group['items'] as &$item) {
            if (in_array($item['item_id'], $selection, true) && $item['status'] !== 'deleted') {
                $item['status'] = $status; $item['reason'] = $reason;
            }
        }
    }

    private function claimProduct(string $key, string $runId): bool
    {
        DB::table('orphan_variant_cleanup_guards')->insertOrIgnore(['product_key' => $key, 'run_id' => $runId,
            'status' => 'clear', 'created_at' => now(), 'updated_at' => now()]);
        return DB::table('orphan_variant_cleanup_guards')->where('product_key', $key)->where('status', 'clear')
            ->update(['run_id' => $runId, 'status' => 'claimed', 'updated_at' => now()]) === 1;
    }

    private function load(string $id, string $account): array
    {
        $row = DB::table('orphan_variant_cleanup_runs')->where('id', $id)->where('account_key', $account)->first();
        abort_unless($row, 404, 'Preview tidak ditemukan untuk akun ini.');
        return [$row, json_decode($row->state, true, 512, JSON_THROW_ON_ERROR)];
    }

    private function save(string $id, string $status, array $state): void
    {
        DB::table('orphan_variant_cleanup_runs')->where('id', $id)->update(['status' => $status,
            'state' => json_encode($state, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
    }

    private function locked(string $id, callable $callback): array
    {
        $lock = Cache::lock('orphan-variant-run:'.$id, 900);
        abort_unless($lock->get(), 423, 'Proses masih berjalan.');
        try { return $callback(); } finally { $lock->release(); }
    }
}
