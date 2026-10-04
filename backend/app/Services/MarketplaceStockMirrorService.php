<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class MarketplaceStockMirrorService
{
    private const TERMINAL = ['completed', 'cancelled', 'scan_failed'];

    public function __construct(private MarketplaceStockMirrorGateway $gateway, private MarketplaceStockMirrorLease $lease) {}

    public function start(array $scope, array $targetAccounts, string $requestKey): array
    {
        [$scope, $targetAccounts] = $this->validate($scope, $targetAccounts, $requestKey);
        $hash = hash('sha256', json_encode([$scope, $targetAccounts]));
        $existing = DB::table('marketplace_stock_mirror_runs')->where('request_key', $requestKey)->first();
        if ($existing) {
            abort_unless(hash_equals($existing->request_hash, $hash), 409, 'Kunci permintaan sudah dipakai untuk pilihan berbeda.');
            return $this->show($existing->id);
        }
        $selection = [];
        if ($scope['type'] !== 'all') {
            try {
                $selection = $this->gateway->sourceSelection($scope['view_account_key'], $scope['product_id'], $scope['variant_id'] ?? null);
                if (! $selection) {
                    throw new \RuntimeException();
                }
            } catch (Throwable) {
                abort(422, 'Identitas pilihan tidak dapat diverifikasi di akun tersebut.');
            }
        }
        $runId = DB::transaction(function () use ($scope, $targetAccounts, $requestKey, $hash, $selection): string {
            $guard = DB::table('marketplace_stock_mirror_claims')->where('id', 'global')->lockForUpdate()->first();
            $existing = DB::table('marketplace_stock_mirror_runs')->where('request_key', $requestKey)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'Kunci permintaan sudah dipakai untuk pilihan berbeda.');
                return $existing->id;
            }
            abort_if($guard->active_run_id !== null, 409, 'Sinkronisasi stok lain masih aktif. Lanjutkan atau batalkan proses tersebut.');
            $id = (string) Str::uuid();
            $products = array_values(array_unique(array_column($selection, 'source_product_id')));
            $state = ['cursor' => null, 'pages' => [], 'products' => $products, 'seen' => [],
                'catalog_complete' => $scope['type'] !== 'all', 'selection' => $selection,
                'items' => [], 'snapshots' => [], 'message' => 'Membaca stok sumber terbaru.'];
            DB::table('marketplace_stock_mirror_runs')->insert(['id' => $id, 'request_key' => $requestKey,
                'request_hash' => $hash, 'status' => 'scanning', 'scope' => json_encode($scope),
                'target_accounts' => json_encode($targetAccounts), 'state' => json_encode($state),
                'created_at' => now(), 'updated_at' => now()]);
            DB::table('marketplace_stock_mirror_claims')->where('id', 'global')->update(['active_run_id' => $id]);
            return $id;
        });
        return $this->show($runId);
    }

    public function validate(array $scope, array $targets, string $key): array
    {
        $validator = Validator::make(['scope' => $scope, 'target_accounts' => $targets, 'request_key' => $key], [
            'request_key' => ['required', 'uuid'],
            'scope' => ['required', 'array:type,view_account_key,product_id,variant_id'],
            'scope.type' => ['required', Rule::in(['all', 'product', 'variant'])],
            'scope.view_account_key' => ['required', Rule::in([MarketplaceStockMirrorGateway::SOURCE, ...MarketplaceStockMirrorGateway::TARGETS])],
            'scope.product_id' => ['required_if:scope.type,product,variant', 'prohibited_if:scope.type,all', 'string', 'regex:/^[0-9]+$/D', 'max:64'],
            'scope.variant_id' => ['required_if:scope.type,variant', 'prohibited_unless:scope.type,variant', 'string', 'regex:/^[0-9]+$/D', 'max:64'],
            'target_accounts' => ['required', 'array', 'min:1', 'max:2'],
            'target_accounts.*' => ['required', 'distinct', Rule::in(MarketplaceStockMirrorGateway::TARGETS)],
        ]);
        abort_if($validator->fails(), 422, 'Pilihan akun atau ruang lingkup stok tidak valid.');
        $data = $validator->validated();
        $canonical = ['type' => $data['scope']['type'], 'view_account_key' => $data['scope']['view_account_key']];
        foreach (['product_id', 'variant_id'] as $field) {
            if (isset($data['scope'][$field])) {
                $canonical[$field] = $data['scope'][$field];
            }
        }
        $ordered = array_values(array_intersect(MarketplaceStockMirrorGateway::TARGETS, $data['target_accounts']));
        return [$canonical, $ordered];
    }

    public function active(): ?array
    {
        $runId = DB::table('marketplace_stock_mirror_claims')->where('id', 'global')->value('active_run_id');
        if ($runId === null) {
            return null;
        }
        $run = $this->show($runId);
        return $run['can_continue'] ? $run : null;
    }

    public function show(string $runId): array
    {
        [$row, $state] = $this->load($runId);
        $summary = array_fill_keys(['checked', 'success', 'unchanged', 'skipped', 'failed', 'unverified', 'pending'], 0);
        foreach ($state['items'] as $item) {
            $status = $item['status'];
            if (in_array($status, ['pending', 'attempted'], true)) {
                $summary['pending']++;
            } else {
                $summary['checked']++;
                $summary[$status]++;
            }
        }
        return ['run_id' => $row->id, 'status' => $row->status, 'scope' => json_decode($row->scope, true),
            'target_accounts' => json_decode($row->target_accounts, true), 'summary' => $summary,
            'items' => array_map(function (array $item): array {
                unset($item['write_accepted']);
                return $item;
            }, $state['items']), 'message' => $state['message'], 'can_continue' => ! in_array($row->status, self::TERMINAL, true)];
    }

    public function step(string $runId): array
    {
        [$row] = $this->load($runId);
        if (in_array($row->status, self::TERMINAL, true)) {
            return $this->show($runId);
        }
        $owner = $this->claim($runId);
        $acquired = false;
        try {
            try {
                $this->lease->acquire();
                $acquired = true;
            } catch (Throwable) {
                abort(423, 'Koordinasi marketplace tidak tersedia. Tunggu lalu lanjutkan.');
            }
            [$row, $state] = $this->load($runId);
            if ($row->status === 'scanning') {
                $this->scan($row, $state, $owner);
            } elseif ($row->status === 'running') {
                $this->deliver($row, $state, $owner);
            }
            return $this->show($runId);
        } finally {
            try {
                if ($acquired) {
                    $this->lease->release();
                }
            } finally {
                $this->releaseClaim($runId, $owner);
            }
        }
    }

    public function cancel(string $runId): array
    {
        [$row] = $this->load($runId);
        if (in_array($row->status, self::TERMINAL, true)) {
            return $this->show($runId);
        }
        $owner = $this->claim($runId);
        try {
            [$row, $state] = $this->load($runId);
            foreach ($state['items'] as &$item) {
                if ($item['status'] === 'pending') {
                    $item['status'] = 'skipped';
                    $item['message'] = 'Dibatalkan sebelum pengiriman.';
                } elseif ($item['status'] === 'attempted') {
                    $item['status'] = 'unverified';
                    $item['message'] = 'Pengiriman sebelumnya terputus; periksa marketplace.';
                }
            }
            unset($item);
            $state['message'] = 'Proses dibatalkan. Tidak ada pengiriman lanjutan.';
            $this->save($runId, 'cancelled', $state, $owner);
        } finally {
            $this->releaseClaim($runId, $owner);
        }
        return $this->show($runId);
    }

    private function scan(object $row, array $state, string $owner): void
    {
        try {
            if (! $state['products'] && ! $state['catalog_complete']) {
                $cursor = $state['cursor'] ?? '<start>';
                if (in_array($cursor, $state['pages'], true)) {
                    throw new \RuntimeException();
                }
                $page = $this->gateway->catalogPage($state['cursor']);
                if (! is_array($page['products'] ?? null) || ! is_bool($page['complete'] ?? null)
                    || ! array_key_exists('next_cursor', $page) || ($page['complete'] !== ($page['next_cursor'] === null))) {
                    throw new \RuntimeException();
                }
                $state['pages'][] = $cursor;
                $state['products'] = array_values(array_diff($page['products'], $state['seen']));
                $state['cursor'] = $page['next_cursor'];
                $state['catalog_complete'] = $page['complete'];
            } elseif ($state['products']) {
                $productId = (string) array_shift($state['products']);
                $state['seen'][] = $productId;
                $this->scanProduct($row, $state, $productId);
            }
            $ready = ! $state['products'] && $state['catalog_complete'];
            $state['message'] = $ready ? 'Siap mengirim stok sumber terbaru.' : 'Pemindaian sumber berlangsung.';
            $status = $ready ? ($state['items'] ? 'running' : 'completed') : 'scanning';
            $this->save($row->id, $status, $state, $owner);
        } catch (Throwable) {
            foreach ($state['items'] as &$item) {
                if ($item['status'] === 'pending') {
                    $item['status'] = 'skipped';
                    $item['message'] = 'Katalog sumber tidak lengkap; tidak ada pengiriman.';
                }
            }
            unset($item);
            $state['message'] = 'Pemindaian katalog gagal. Buat proses baru setelah memeriksa akun.';
            $this->save($row->id, 'scan_failed', $state, $owner);
        }
    }

    private function scanProduct(object $row, array &$state, string $productId): void
    {
        $scope = json_decode($row->scope, true);
        try {
            $product = $this->gateway->product(MarketplaceStockMirrorGateway::SOURCE, $productId);
            if (! ($product['active'] ?? false) || ! ($product['complete'] ?? false)) {
                throw new \RuntimeException();
            }
            $variants = $product['variants'];
            if ($scope['type'] !== 'all') {
                $ids = array_column(array_filter($state['selection'], fn ($s) => $s['source_product_id'] === $productId), 'source_variant_id');
                $variants = array_values(array_filter($variants, fn ($v) => in_array($v['id'], $ids, true)));
                if (count($variants) !== count($ids)) {
                    throw new \RuntimeException();
                }
            }
        } catch (Throwable) {
            $product = ['name' => ''];
            $variants = [['id' => '', 'name' => '', 'seller_sku' => '', 'stock' => null]];
        }
        foreach ($variants as $variant) {
            foreach (json_decode($row->target_accounts, true) as $account) {
                $id = hash('sha256', $row->id.'|'.$productId.'|'.$variant['id'].'|'.$account);
                $valid = is_int($variant['stock']) && $variant['stock'] >= 0;
                $state['items'][] = ['item_id' => $id, 'source_product_id' => $productId,
                    'source_variant_id' => $variant['id'], 'source_product_name' => $product['name'],
                    'source_variant_name' => $variant['name'], 'seller_sku' => $variant['seller_sku'],
                    'source_stock' => $variant['stock'], 'target_account_key' => $account,
                    'target_product_id' => null, 'target_variant_id' => null, 'before_stock' => null, 'after_stock' => null,
                    'status' => $valid ? 'pending' : 'skipped',
                    'message' => $valid ? 'Menunggu pemeriksaan terbaru.' : 'Stok atau identitas sumber tidak dapat diverifikasi.'];
            }
        }
    }

    private function deliver(object $row, array $state, string $owner): void
    {
        foreach ($state['items'] as $index => $item) {
            if (! in_array($item['status'], ['pending', 'attempted'], true)) {
                continue;
            }
            $attempted = $item['status'] === 'attempted';
            try {
                if ($attempted) {
                    $this->verify($item);
                } else {
                    $source = $this->variant(MarketplaceStockMirrorGateway::SOURCE, $item['source_product_id'], $item['source_variant_id']);
                    if ($source['seller_sku'] !== $item['seller_sku']) {
                        throw new \RuntimeException();
                    }
                    $key = $item['source_product_id'].':'.$item['source_variant_id'];
                    $snapshot = ['stock' => $source['stock'], 'seller_sku' => $source['seller_sku'], 'name' => $source['name']];
                    if (! $this->selectionStillMatches($row, $state, $item, $source)) {
                        $item['status'] = 'skipped';
                        $item['message'] = 'Hubungan pilihan awal berubah atau tidak dapat diverifikasi. Buat proses baru.';
                    } elseif (isset($state['snapshots'][$key]) && $state['snapshots'][$key] !== $snapshot) {
                        $item['status'] = 'skipped';
                        $item['message'] = 'Sumber berubah antar tujuan. Buat proses baru.';
                    } else {
                        $state['snapshots'][$key] = $snapshot;
                        $item['source_stock'] = $source['stock'];
                        $target = $this->gateway->target($item['source_product_id'], $source, $item['target_account_key']);
                        if (($target['status'] ?? '') !== 'ready') {
                            $item['status'] = 'skipped';
                            $item['message'] = 'Identitas tujuan tidak tersedia, tidak aktif, atau ambigu.';
                        } else {
                            $item['target_product_id'] = $target['product_id'];
                            $item['target_variant_id'] = $target['variant_id'];
                            $before = $this->variant($item['target_account_key'], $target['product_id'], $target['variant_id']);
                            $item['before_stock'] = $before['stock'];
                            // Mapping resolution can make several reads: recheck the source immediately before mutation.
                            $fresh = $this->variant(MarketplaceStockMirrorGateway::SOURCE, $item['source_product_id'], $item['source_variant_id']);
                            if (['stock' => $fresh['stock'], 'seller_sku' => $fresh['seller_sku'], 'name' => $fresh['name']] !== $snapshot) {
                                $item['status'] = 'skipped';
                                $item['message'] = 'Sumber berubah sebelum pengiriman. Buat proses baru.';
                            } elseif (! $this->selectionStillMatches($row, $state, $item, $fresh)) {
                                $item['status'] = 'skipped';
                                $item['message'] = 'Hubungan pilihan awal berubah sebelum pengiriman. Buat proses baru.';
                            } elseif ($before['stock'] === $source['stock']) {
                                $item['status'] = 'unchanged';
                                $item['after_stock'] = $before['stock'];
                                $item['message'] = 'Stok tujuan sudah sama.';
                                $this->cache($item);
                            } else {
                                $this->lease->renew();
                                $item['status'] = 'attempted';
                                $item['write_accepted'] = null;
                                $item['message'] = 'Permintaan tersimpan; menunggu verifikasi.';
                                $state['items'][$index] = $item;
                                $this->save($row->id, 'running', $state, $owner);
                                $attempted = true;
                                $response = $this->gateway->write($item['target_account_key'], $target['product_id'], $target['variant_id'], $source['stock'], $item['item_id']);
                                $item['write_accepted'] = ($response['status'] ?? '') === 'success';
                                $state['items'][$index] = $item;
                                $this->save($row->id, 'running', $state, $owner);
                                $this->verify($item);
                            }
                        }
                    }
                }
            } catch (Throwable) {
                $item['status'] = $attempted ? 'unverified' : 'failed';
                $item['message'] = $attempted ? 'Hasil pengiriman belum terverifikasi. Periksa marketplace; tidak diulang otomatis.'
                    : 'Data sumber atau tujuan terbaru tidak dapat diverifikasi. Tidak ada pengiriman.';
            }
            $state['items'][$index] = $item;
            break; // At most one delivery or recovery verification per request.
        }
        $pending = count(array_filter($state['items'], fn ($i) => in_array($i['status'], ['pending', 'attempted'], true))) > 0;
        $state['message'] = $pending ? 'Proses berlangsung; hasil setiap tujuan disimpan.' : 'Proses selesai. Periksa hasil setiap tujuan.';
        $this->save($row->id, $pending ? 'running' : 'completed', $state, $owner);
    }

    private function selectionStillMatches(object $row, array $state, array $item, array $source): bool
    {
        $scope = json_decode($row->scope, true);
        if ($scope['type'] === 'all' || $scope['view_account_key'] === MarketplaceStockMirrorGateway::SOURCE) {
            return true;
        }
        $selected = array_values(array_filter($state['selection'], fn ($selection) =>
            $selection['source_product_id'] === $item['source_product_id']
            && $selection['source_variant_id'] === $item['source_variant_id']));
        if (count($selected) !== 1 || ($selected[0]['view_product_id'] ?? null) !== $scope['product_id']
            || ! isset($selected[0]['view_variant_id'])
            || ($scope['type'] === 'variant' && $selected[0]['view_variant_id'] !== $scope['variant_id'])) {
            return false;
        }
        try {
            $target = $this->gateway->target($item['source_product_id'], $source, $scope['view_account_key']);
            return ($target['status'] ?? '') === 'ready'
                && $target['product_id'] === $selected[0]['view_product_id']
                && $target['variant_id'] === $selected[0]['view_variant_id'];
        } catch (Throwable) {
            return false;
        }
    }

    private function variant(string $account, string $productId, string $variantId): array
    {
        $product = $this->gateway->product($account, $productId);
        $variants = array_values(array_filter($product['variants'] ?? [], fn ($v) => $v['id'] === $variantId));
        if (! ($product['active'] ?? false) || ! ($product['complete'] ?? false) || count($variants) !== 1
            || ! is_int($variants[0]['stock']) || $variants[0]['stock'] < 0) {
            throw new \RuntimeException();
        }
        return $variants[0];
    }

    private function verify(array &$item): void
    {
        $after = $this->variant($item['target_account_key'], $item['target_product_id'], $item['target_variant_id']);
        $item['after_stock'] = $after['stock'];
        if ($after['stock'] !== $item['source_stock']) {
            throw new \RuntimeException();
        }
        if (($item['write_accepted'] ?? null) !== true) {
            $item['status'] = 'unverified';
            $item['message'] = 'Stok cocok, tetapi penerimaan permintaan tidak tercatat atau terverifikasi. Tidak diulang otomatis.';
            return;
        }
        $item['status'] = 'success';
        $item['message'] = 'Stok tujuan sama dengan sumber dan terverifikasi.';
        $this->cache($item);
    }

    private function cache(array &$item): void
    {
        try {
            $this->gateway->cacheVerified($item['target_account_key'], $item['target_product_id'], $item['target_variant_id'], $item['after_stock']);
        } catch (Throwable) {
            $item['message'] .= ' Cache lokal belum diperbarui.';
        }
    }

    private function load(string $runId): array
    {
        $row = DB::table('marketplace_stock_mirror_runs')->where('id', $runId)->first();
        abort_unless($row, 404, 'Proses stok tidak ditemukan.');
        return [$row, json_decode($row->state, true)];
    }

    private function claim(string $runId): string
    {
        $owner = (string) Str::uuid();
        $claimed = DB::table('marketplace_stock_mirror_claims')->where('id', 'global')->where('active_run_id', $runId)
            ->where(function ($query): void {
                $query->whereNull('owner')->orWhere('expires_at', '<=', now());
            })->update(['owner' => $owner, 'expires_at' => now()->addMinutes(15)]);
        abort_unless($claimed === 1, 423, 'Langkah lain masih berjalan. Tunggu lalu muat ulang.');
        return $owner;
    }

    private function save(string $runId, string $status, array $state, string $owner): void
    {
        DB::transaction(function () use ($runId, $status, $state, $owner): void {
            $guard = DB::table('marketplace_stock_mirror_claims')->where('id', 'global')->lockForUpdate()->first();
            abort_unless($guard->active_run_id === $runId && $guard->owner === $owner && $guard->expires_at > now()->toDateTimeString(), 423, 'Kepemilikan langkah kedaluwarsa.');
            DB::table('marketplace_stock_mirror_runs')->where('id', $runId)->update(['status' => $status, 'state' => json_encode($state), 'updated_at' => now()]);
            if (in_array($status, self::TERMINAL, true)) {
                // Terminal state and guard release are atomic even if the process dies before finally.
                DB::table('marketplace_stock_mirror_claims')->where('id', 'global')->update([
                    'active_run_id' => null, 'owner' => null, 'expires_at' => null,
                ]);
            }
        });
    }

    private function releaseClaim(string $runId, string $owner): void
    {
        $status = DB::table('marketplace_stock_mirror_runs')->where('id', $runId)->value('status');
        $values = ['owner' => null, 'expires_at' => null];
        if (in_array($status, self::TERMINAL, true)) {
            $values['active_run_id'] = null;
        }
        DB::table('marketplace_stock_mirror_claims')->where('id', 'global')->where('active_run_id', $runId)->where('owner', $owner)->update($values);
    }
}
