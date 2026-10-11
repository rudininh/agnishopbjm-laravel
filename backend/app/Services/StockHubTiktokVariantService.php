<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable one-product additions. An uncertain partial edit is read back, never replayed. */
class StockHubTiktokVariantService
{
    private const TABLE = 'stock_hub_tiktok_variant_runs';
    private const GUARDS = 'stock_hub_tiktok_variant_guards';
    private const RUNNING = ['preparing', 'scanning', 'uploading', 'submitting', 'verifying'];
    private const RESTARTABLE = ['blocked', 'rejected', 'success', 'partial', 'exists'];

    public function __construct(
        private StockHubTiktokVariantGateway $gateway,
        private StockHubTiktokVariantPayload $payload,
        private MarketplaceVariantCoverageService $coverage,
        private MarketplaceStockMirrorLease $lease,
        private MarketplaceCatalogMutationRevision $revision,
        private StockHubTiktokPackageTemplate $packages,
    ) {}

    public function start(string $source, string $key, array $context = []): array
    {
        $hash = hash('sha256', json_encode([$source, $context], JSON_THROW_ON_ERROR));
        $id = DB::transaction(function () use ($source, $key, $context, $hash): string {
            $guard = $this->lockedGuard('source:'.$source);
            $repeat = DB::table(self::TABLE)->where('request_key', $key)->first();
            if ($repeat) { abort_unless(hash_equals($repeat->request_hash, $hash), 409, 'Identitas permintaan sudah dipakai untuk input berbeda.'); return $repeat->id; }
            $current = $guard->run_id ? DB::table(self::TABLE)->where('id', $guard->run_id)->first() : null;
            if ($current && (! $this->restartable($current) || ($guard->owner && $guard->expires_at > now()->toDateTimeString()))) { return $current->id; }
            $id = (string) Str::uuid();
            $state = ['stage' => 'preparing', 'message' => 'Membaca varian Agni dan produk TikTok terkait.', 'context' => $context,
                'required_fields' => [], 'progress' => ['scanned_products' => 0, 'uploaded_images' => 0, 'total_images' => 0], 'failures' => [], 'result' => null];
            DB::table(self::TABLE)->insert(['id' => $id, 'request_key' => $key, 'request_hash' => $hash, 'source_product_id' => $source,
                'status' => 'preparing', 'state' => json_encode($state, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            DB::table(self::GUARDS)->where('key', 'source:'.$source)->update(['run_id' => $id, 'owner' => null, 'expires_at' => null]);
            return $id;
        });
        return $this->show($id);
    }

    public function show(string $id): array { return $this->snapshot($this->row($id)); }

    public function source(string $source): ?array
    {
        $id = DB::table(self::GUARDS)->where('key', 'source:'.$source)->value('run_id');
        return $id ? $this->show($id) : null;
    }

    public function step(string $id): array
    {
        $row = $this->row($id);
        if (! in_array($this->status($row), [...self::RUNNING, 'submitted_unverified', 'under_review'], true)) { return $this->snapshot($row); }
        $owner = (string) Str::uuid();
        $claimed = DB::table(self::GUARDS)->where('key', 'source:'.$row->source_product_id)->where('run_id', $id)
            ->where(fn ($q) => $q->whereNull('owner')->orWhere('expires_at', '<=', now()))->update(['owner' => $owner, 'expires_at' => now()->addMinutes(5)]);
        if (! $claimed) { return $this->show($id); }
        $state = [];
        try {
            $row = $this->row($id); $state = json_decode($row->state, true, 512, JSON_THROW_ON_ERROR);
            if ($this->delay($state) > 0) { return $this->show($id); }
            unset($state['coordination_retry_at']);
            try { $this->lease->acquire(); }
            catch (MarketplaceOperationBusy) {
                $state['coordination_retry_at'] = now()->addSeconds(5)->getTimestampMs();
                $message = in_array($this->status($row), self::RUNNING, true)
                    ? 'Menunggu proses marketplace lain selesai. Progres tersimpan dan proses dilanjutkan otomatis.'
                    : 'Marketplace sedang dipakai proses lain. Gunakan Periksa Status kembali; varian tidak dikirim ulang.';
                $this->save($id, $this->status($row), $state, $message); return $this->show($id);
            }
            catch (\Throwable $e) {
                $state['failure_kind'] = 'coordination_unavailable';
                throw $e;
            }
            unset($state['failure_kind']);
            if (isset($state['target_shop_id'])) { $this->gateway->assertTargetShop($state['target_shop_id']); }
            if ($row->attempted_at) {
                $this->readback($row, $state);
            } elseif ($row->status === 'preparing') {
                $this->prepare($row, $state);
            } elseif ($row->status === 'scanning') {
                $this->scan($row, $state);
            } elseif ($row->status === 'uploading') {
                $this->upload($row, $state);
            } elseif ($row->status === 'submitting') {
                $this->submit($row, $state);
            }
        } catch (StockHubTiktokProductRejected $e) {
            $this->save($id, 'rejected', $state, $e->getMessage());
        } catch (\Throwable $e) {
            $fresh = $this->row($id);
            $attempted = $fresh->attempted_at !== null;
            $this->save($id, $attempted ? 'submitted_unverified' : 'blocked', $state, $attempted
                ? ($e instanceof StockHubTiktokVariantStockMismatch ? $e->getMessage() : 'Hasil pengiriman varian belum dapat dipastikan. Gunakan Periksa Status; jangan menambahkan ulang.')
                : ($e instanceof \DomainException ? $e->getMessage() : 'Pemeriksaan varian gagal. Periksa akun, token, koneksi, dan katalog lalu coba kembali.'));
        } finally {
            $this->lease->release();
            DB::table(self::GUARDS)->where('key', 'source:'.$row->source_product_id)->where('owner', $owner)->update(['owner' => null, 'expires_at' => null]);
        }
        return $this->show($id);
    }

    private function prepare(object $row, array $state): void
    {
        $source = $this->gateway->source($row->source_product_id);
        $coverage = $this->coverage->forProducts([['item_id' => $source['id'], 'shop_id' => $source['shop_id'],
            'models' => array_map(fn ($v) => ['model_id' => $v['id'], 'model_sku' => $v['seller_sku'], 'name' => $v['name']], $source['variants'])]])[$source['id']]['tiktok-agnishopbjm'];
        $this->require(in_array($coverage['state'], ['complete', 'missing_variants'], true) && $coverage['target_product_id'], 'Produk TikTok terkait belum dapat dipastikan. Segarkan katalog atau perbaiki relasi SKU.');
        $targetId = (string) $coverage['target_product_id'];
        $this->bindTarget($row, $targetId);
        $state['target_product_id'] = $targetId; $state['target_shop_id'] = $this->gateway->targetShop(); $state['source'] = $source;
        $this->gateway->assertTargetShop($state['target_shop_id']);
        $target = $this->gateway->target($targetId);
        $this->assertTargetRelation($source, $target);
        StockHubTiktokVariantInterlock::assertNoUnresolvedLegacy($targetId, $source, $target);
        $state['package_template'] = $this->packages->snapshot();
        $package = $this->packages->apply($target, $source, $state['package_template']);
        $target = $package['target']; $state['package_template_applied'] = $package['applied'];
        $prepared = $this->payload->prepare($source, $target, $state['context']['manual_price'] ?? null);
        $prepared['package_fields'] = $package['product_fields'];
        $state['prepared'] = $prepared; $state['required_fields'] = $prepared['required_fields']; $state['target'] = $target;
        if (! $prepared['variants']) { $state['required_fields'] = []; $this->save($row->id, 'exists', $state, 'Semua SKU Agni sudah ada di produk TikTok terkait.'); return; }
        if ($state['required_fields']) { $this->save($row->id, 'blocked', $state, 'Harga mayoritas TikTok belum pasti. Isi harga varian baru dalam rupiah.'); return; }
        $state['scan'] = ['cursor' => null, 'cursors' => [], 'seen' => [], 'queue' => [], 'complete' => false, 'total' => null];
        $state['scan_revision'] = $this->revision->current(); $state['scan_attempts'] = $this->attempts();
        $this->save($row->id, 'scanning', $state, 'Memastikan SKU Agni tidak dimiliki produk TikTok lain.');
    }

    private function scan(object $row, array $state): void
    {
        $scan = &$state['scan'];
        if ($scan['queue']) {
            $id = array_shift($scan['queue']); $target = $this->gateway->catalogTarget($id);
            if ($id !== $state['target_product_id']) {
                $owned = array_column($state['source']['variants'], 'seller_sku');
                foreach ($target['skus'] as $sku) {
                    $value = trim($sku['seller_sku']);
                    $this->require(! in_array($value, $owned, true) && ! preg_match('/^INT-'.preg_quote($row->source_product_id, '/').'(?:-|$)/i', $value), 'SKU Agni ditemukan pada produk TikTok lain. Periksa relasi produk sebelum menambahkan varian.');
                }
            }
            $state['progress']['scanned_products']++;
        } elseif (! $scan['complete']) {
            $page = $this->gateway->page($scan['cursor']);
            $this->require(! array_intersect($scan['seen'], $page['ids']) && ($page['next'] === null || (! in_array($page['next'], $scan['cursors'], true) && $page['next'] !== $scan['cursor'] && $page['ids'])), 'Paginasi katalog TikTok berulang atau tidak bergerak. Segarkan katalog.');
            if ($page['total'] !== null) {
                $this->require(ctype_digit((string) $page['total']) && ($scan['total'] === null || (string) $scan['total'] === (string) $page['total']), 'Jumlah katalog TikTok berubah selama pemeriksaan.');
                $scan['total'] = $page['total'];
            }
            $scan['seen'] = [...$scan['seen'], ...$page['ids']]; $scan['queue'] = $page['ids']; $scan['cursor'] = $page['next'];
            $scan['cursors'][] = $page['next']; $scan['complete'] = $page['next'] === null;
            $this->require(count($scan['seen']) <= 10000, 'Katalog TikTok melampaui batas pemeriksaan.');
            if ($scan['complete']) { $this->require($scan['total'] === null || count($scan['seen']) === (int) $scan['total'], 'Katalog TikTok belum terbaca lengkap.'); }
        }
        if ($scan['complete'] && ! $scan['queue']) {
            $this->require(in_array($state['target_product_id'], $scan['seen'], true), 'Produk TikTok tujuan tidak ditemukan dalam katalog terbaru.');
            $state['progress']['total_images'] = count($state['prepared']['variants']);
            $this->save($row->id, 'uploading', $state, 'Menyiapkan gambar semua varian TikTok yang kurang.');
        } else { $this->save($row->id, 'scanning', $state, 'Memeriksa kepemilikan SKU di katalog TikTok.'); }
    }

    private function upload(object $row, array $state): void
    {
        $index = $state['progress']['uploaded_images'];
        $variant = $state['prepared']['variants'][$index];
        try { $state['prepared']['variants'][$index]['uri'] = $this->gateway->upload($variant); }
        catch (\Throwable) {
            $state['failures'][] = ['source_variant_id' => $variant['id'], 'seller_sku' => $variant['seller_sku'], 'name' => $variant['name'], 'status' => 'failed', 'reason' => 'Gambar varian gagal diunduh atau diunggah. Periksa gambar dan koneksi.'];
        }
        $state['progress']['uploaded_images']++;
        $done = $state['progress']['uploaded_images'] === count($state['prepared']['variants']);
        if ($done && ! array_filter($state['prepared']['variants'], fn ($v) => isset($v['uri']))) {
            $state['result'] = ['variants' => $state['failures']];
            $this->save($row->id, 'blocked', $state, 'Gambar varian belum berhasil disiapkan. Coba Lagi setelah memeriksa gambar.'); return;
        }
        $this->save($row->id, $done ? 'submitting' : 'uploading', $state, $done ? 'Memeriksa ulang sumber dan produk TikTok sebelum mengirim satu batch.' : 'Menyiapkan gambar varian TikTok.');
    }

    private function submit(object $row, array $state): void
    {
        $this->require($this->revision->current() === $state['scan_revision'] && $this->attempts() === $state['scan_attempts'], 'Katalog berubah selama proses. Coba Lagi untuk memeriksa ulang semua SKU.');
        $source = $this->gateway->source($row->source_product_id);
        $this->require($this->sameSource($source, $state['source']), 'Data varian Agni berubah selama proses. Coba Lagi untuk membaca sumber terbaru.');
        $target = $this->gateway->target($state['target_product_id']);
        $this->assertTargetRelation($source, $target);
        StockHubTiktokVariantInterlock::assertNoUnresolvedLegacy($state['target_product_id'], $source, $target);
        $package = isset($state['package_template']) ? $this->packages->apply($target, $source, $state['package_template']) : null;
        if ($package !== null) { $target = $package['target']; }
        $fresh = $this->payload->prepare($source, $target, $state['context']['manual_price'] ?? null);
        if ($package !== null) { $fresh['package_fields'] = $package['product_fields']; $state['package_template_applied'] = $package['applied']; }
        $this->require(! $fresh['variants'] || ! $fresh['required_fields'], 'Harga TikTok berubah dan perlu diperiksa kembali. Coba Lagi.');
        $uris = array_column($state['prepared']['variants'], 'uri', 'seller_sku');
        // Only successfully prepared source variants are eligible; a fresh newly missing
        // SKU outside this batch requires a new scan, not a silently expanded mutation.
        $oldSkus = array_column($state['prepared']['variants'], 'seller_sku');
        foreach ($fresh['variants'] as $variant) { $this->require(in_array($variant['seller_sku'], $oldSkus, true), 'Varian TikTok berubah selama proses. Coba Lagi untuk memeriksa ulang.'); }
        $missing = array_column($fresh['variants'], 'seller_sku');
        $state['skipped'] = array_map(fn ($v) => ['source_variant_id' => $v['id'], 'seller_sku' => $v['seller_sku'], 'name' => $v['name'],
            'status' => 'skipped', 'reason' => 'SKU sudah ditemukan di TikTok saat pemeriksaan terakhir; tidak dikirim ulang.'],
            array_values(array_filter($state['prepared']['variants'], fn ($v) => ! in_array($v['seller_sku'], $missing, true))));
        $state['failures'] = array_values(array_filter($state['failures'], fn ($v) => in_array($v['seller_sku'], $missing, true)));
        $fresh['variants'] = array_values(array_filter($fresh['variants'], fn ($v) => isset($uris[$v['seller_sku']])));
        foreach ($fresh['variants'] as &$variant) { $variant['uri'] = $uris[$variant['seller_sku']]; } unset($variant);
        if (! $fresh['variants']) {
            $state['result'] = ['variants' => [...$state['failures'], ...$state['skipped']]];
            $this->save($row->id, $state['failures'] ? 'partial' : 'exists', $state, $state['failures'] ? 'Varian yang disiapkan sudah ada di TikTok; sebagian gambar varian lain masih gagal.' : 'SKU yang disiapkan sudah ditemukan di TikTok sebelum pengiriman.'); return;
        }
        $body = $this->payload->build($target, $fresh);
        $state['expected'] = [...$fresh, 'payload' => $body];
        $this->lease->renew();
        $edit = $this->gateway->prepareEdit($state['target_product_id'], $state['target_shop_id']);
        $this->revision->beforeLegacyMutation();
        DB::table(self::TABLE)->where('id', $row->id)->whereNull('attempted_at')->update(['state' => json_encode($state, JSON_THROW_ON_ERROR), 'attempted_at' => now(), 'updated_at' => now()]);
        $edit($body);
        DB::table(self::TABLE)->where('id', $row->id)->update(['accepted_at' => now()]);
        $this->save($row->id, 'verifying', $state, 'Batch varian diterima. Memeriksa hasil TikTok tanpa mengirim ulang.');
    }

    private function readback(object $row, array $state): void
    {
        $result = $this->gateway->verify($state['source'], $state['expected'], $state['target_product_id']);
        $result['variants'] = [...$result['variants'], ...$state['failures'], ...($state['skipped'] ?? [])]; $state['result'] = $result;
        $status = ! $result['published'] ? 'under_review' : ($state['failures'] ? 'partial' : 'success');
        DB::table(self::TABLE)->where('id', $row->id)->update(['accepted_at' => $row->accepted_at ?? now()]);
        $this->save($row->id, $status, $state, $status === 'under_review' ? 'Varian terverifikasi pada versi peninjauan TikTok. Periksa Status setelah peninjauan selesai.'
            : ($status === 'partial' ? 'Sebagian varian berhasil. Periksa rincian gambar yang gagal.' : 'Semua varian dalam batch terverifikasi di TikTok.'));
    }

    private function assertTargetRelation(array $source, array $target): void
    {
        $owned = array_map('trim', array_column($source['variants'], 'seller_sku'));
        $overlap = false;
        foreach ($target['skus'] as $sku) {
            $value = trim($sku['seller_sku']);
            $overlap = $overlap || in_array($value, $owned, true);
            if (preg_match('/^INT-(\d+)(?:-|$)/i', $value, $match)) {
                $this->require($match[1] === (string) $source['id'], 'SKU produk TikTok mengacu ke produk Agni lain. Perbaiki relasi produk terlebih dahulu.');
            }
        }
        // Check all cached Agni owners, including inactive products, against the fresh target.
        $targetSkus = array_map('trim', array_column($target['skus'], 'seller_sku'));
        $conflict = DB::table('shopee_product as p')->join('shopee_product_model as m', 'p.item_id', '=', 'm.item_id')
            ->where('p.shop_id', $source['shop_id'])->where('p.item_id', '<>', $source['id'])
            ->pluck('m.model_sku')->contains(fn ($sku) => is_string($sku) && in_array(trim($sku), $targetSkus, true));
        $this->require(! $conflict, 'SKU produk TikTok dimiliki produk Agni lain. Periksa relasi produk terlebih dahulu.');
        $this->require($overlap, 'Produk TikTok terbaru tidak memiliki SKU yang sama dengan sumber Agni. Segarkan katalog dan periksa relasi produk.');
    }

    private function bindTarget(object $row, string $target): void
    {
        DB::transaction(function () use ($row, $target): void {
            $guard = $this->lockedGuard('target:'.$target);
            $current = $guard->run_id ? DB::table(self::TABLE)->where('id', $guard->run_id)->first() : null;
            $this->require(! $current || $current->id === $row->id || $this->restartable($current), 'Produk TikTok sedang diproses atau hasil penambahan sebelumnya belum pasti. Periksa status tersimpan.');
            DB::table(self::GUARDS)->where('key', 'target:'.$target)->update(['run_id' => $row->id]);
            DB::table(self::TABLE)->where('id', $row->id)->update(['target_product_id' => $target]);
        });
    }

    private function lockedGuard(string $key): object
    {
        DB::table(self::GUARDS)->insertOrIgnore(['key' => $key]);
        return DB::table(self::GUARDS)->where('key', $key)->lockForUpdate()->first();
    }

    private function row(string $id): object { $row = DB::table(self::TABLE)->where('id', $id)->first(); abort_unless($row, 404, 'Proses varian tidak ditemukan.'); return $row; }
    private function status(object $row): string { return $row->attempted_at && ! $row->accepted_at && $row->status !== 'rejected' ? 'submitted_unverified' : $row->status; }
    private function restartable(object $row): bool { return in_array($this->status($row), self::RESTARTABLE, true); }
    private function delay(array $s): int { return max(0, min(5000, (int) ($s['coordination_retry_at'] ?? 0) - now()->getTimestampMs())); }
    private function require(bool $ok, string $message): void { if (! $ok) { throw new \DomainException($message); } }
    private function sameSource(array $a, array $b): bool { $sort = fn ($x, $y) => strcmp($x['id'], $y['id']); usort($a['variants'], $sort); usort($b['variants'], $sort); return $a === $b; }
    private function attempts(): array { return DB::table('stock_hub_tiktok_product_runs')->whereNotNull('attempted_at')->orderBy('id')->pluck('id')->all(); }
    private function save(string $id, string $status, array $s, string $message): void { $s['stage'] = $status; $s['message'] = $message; DB::table(self::TABLE)->where('id', $id)->update(['status' => $status, 'state' => json_encode($s, JSON_THROW_ON_ERROR), 'updated_at' => now()]); }

    private function snapshot(object $row): array
    {
        $s = json_decode($row->state, true, 512, JSON_THROW_ON_ERROR); $status = $this->status($row);
        return ['run_id' => $row->id, 'source_product_id' => $row->source_product_id, 'remote_product_id' => $row->target_product_id,
            'status' => $status, 'stage' => $status, 'message' => $status === 'submitted_unverified' && $status !== $row->status ? 'Hasil pengiriman belum pasti. Periksa Status; jangan kirim ulang.' : ($s['message'] ?? ''),
            'can_continue' => in_array($status, self::RUNNING, true), 'can_retry' => $this->restartable($row), 'required_fields' => $s['required_fields'] ?? [],
            'context' => $s['context'] ?? [], 'progress' => $s['progress'] ?? [], 'price' => $s['prepared']['price'] ?? null,
            'package_template' => $s['package_template'] ?? config('stock_hub_package'), 'package_template_applied' => $s['package_template_applied'] ?? false,
            'variant_count' => count($s['prepared']['variants'] ?? []), 'result' => $s['result'] ?? null, 'next_step_after_ms' => $this->delay($s),
            'failure_kind' => ($s['failure_kind'] ?? null) === 'coordination_unavailable' ? 'coordination_unavailable' : null];
    }
}
