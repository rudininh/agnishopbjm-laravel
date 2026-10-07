<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable one-mutation-per-step workflow. Attempt markers are permanent. */
class StockHubGitaProductService
{
    private const TABLE = 'stock_hub_gita_product_runs';
    private const GUARDS = 'stock_hub_gita_product_guards';
    private const RUNNING = ['preparing','scanning','uploading','submitting','initializing_variants','verifying','publishing','verifying_publication'];
    private const STATUSES = ['NORMAL','UNLIST','BANNED','REVIEWING','SELLER_DELETE','SHOPEE_DELETE'];

    public function __construct(private StockHubGitaProductGateway $gateway, private MarketplaceStockMirrorLease $lease, private MarketplaceCatalogMutationRevision $revision) {}

    public function start(string $product, string $key, array $context): array
    {
        $hash = hash('sha256', json_encode([$product, $this->canonical($context)], JSON_THROW_ON_ERROR));
        $id = DB::transaction(function () use ($product,$key,$context,$hash): string {
            DB::table(self::GUARDS)->insertOrIgnore(['source_product_id' => $product]);
            $g = DB::table(self::GUARDS)->where('source_product_id', $product)->lockForUpdate()->first();
            $repeat = DB::table(self::TABLE)->where('request_key', $key)->first();
            if ($repeat) { abort_unless($repeat->request_hash === $hash, 409, 'Request key sudah digunakan untuk input berbeda.'); return $repeat->id; }
            if ($g->run_id) {
                $r = DB::table(self::TABLE)->where('id', $g->run_id)->first();
                if ($r && ($r->remote_product_id || !in_array($r->status, ['blocked','rejected'], true) || ($r->attempted_at && $r->status !== 'rejected') || ($g->owner && $g->expires_at > now()->toDateTimeString()))) { return $r->id; }
            }
            $id = (string) Str::uuid();
            $state = ['prepare' => 'source', 'context' => $context, 'required_fields' => [], 'progress' => ['scanned_products' => 0, 'uploaded_images' => 0, 'total_images' => 0], 'result' => null, 'message' => 'Menyiapkan produk Shopee Agni untuk Gita.'];
            DB::table(self::TABLE)->insert(['id' => $id, 'request_key' => $key, 'request_hash' => $hash, 'source_product_id' => $product, 'status' => 'preparing', 'state' => json_encode($state, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            DB::table(self::GUARDS)->where('source_product_id', $product)->update(['run_id' => $id, 'owner' => null, 'expires_at' => null]);
            return $id;
        });
        return $this->show($id);
    }

    public function show(string $id): array { return $this->snapshot($this->row($id)); }
    public function source(string $id): ?array { $g = DB::table(self::GUARDS)->where('source_product_id', $id)->first(); return $g?->run_id ? $this->show($g->run_id) : null; }
    public function categories(): array { return $this->gateway->categories(); }

    public function step(string $id): array
    {
        $row = $this->row($id);
        if (!in_array($row->status, self::RUNNING, true) && !($row->remote_product_id && in_array($row->status, ['partial_unverified','under_review'], true))) { return $this->snapshot($row); }
        $owner = (string) Str::uuid();
        $claimed = DB::table(self::GUARDS)->where('source_product_id', $row->source_product_id)->where('run_id', $id)->where(fn ($q) => $q->whereNull('owner')->orWhere('expires_at', '<=', now()))->update(['owner' => $owner, 'expires_at' => now()->addMinutes(5)]);
        if (!$claimed) { return $this->show($id); }
        $state = json_decode($row->state, true, 512, JSON_THROW_ON_ERROR);
        try {
            $row = $this->row($id); $state = json_decode($row->state, true, 512, JSON_THROW_ON_ERROR);
            if ($row->attempted_at && !$row->remote_product_id) { $this->save($id, 'submitted_unverified', $state, 'Hasil pembuatan induk belum diketahui. Periksa Seller Center; produk tidak akan dibuat ulang.'); return $this->show($id); }
            $this->lease->acquire();
            $this->gateway->identities($state['identities'] ?? null);
            if ($row->remote_product_id) { $this->afterParent($row, $state, $owner); }
            elseif ($row->status === 'preparing') { $this->prepare($row, $state); }
            elseif ($row->status === 'scanning') { $this->scan($row, $state); }
            elseif ($row->status === 'uploading') {
                $i = $state['progress']['uploaded_images'];
                if ($i < count($state['images'])) { $state['images'][$i]['image_id'] = $this->gateway->upload($state['images'][$i], $state['identities']); $state['progress']['uploaded_images']++; }
                $done = $state['progress']['uploaded_images'] === count($state['images']);
                if ($done) { $state['payload'] = $this->gateway->payload($state['source'], $state['context'], $state['images'], $state['logistics']); }
                $this->save($id, $done ? 'submitting' : 'uploading', $state, $done ? 'Memeriksa sumber sebelum membuat induk Gita.' : 'Mengunggah gambar ke Gita.');
            } elseif ($row->status === 'submitting') {
                $fresh = $this->gateway->source($row->source_product_id);
                if ($fresh !== $state['source']) { throw new \DomainException('Produk sumber berubah selama proses. Periksa data lalu coba kembali.'); }
                if ($this->gateway->linked($row->source_product_id)) { $this->save($id, 'exists', $state, 'Produk sudah memiliki relasi Gita.'); return $this->show($id); }
                if ($this->revision->current() !== $state['scan_revision'] || $this->attempts() !== $state['scan_attempts']) { throw new \DomainException('Katalog berubah selama pemindaian. Ulangi pemeriksaan produk.'); }
                $submit = $this->mutation($row, $state, $owner, 'add_item', 'attempted_at');
                try { $accepted = $submit($state['payload']); }
                catch (StockHubGitaProductRejected $e) {
                    $state['rejection'] = ['code' => $e->marketplaceCode, 'field' => $e->field];
                    $this->save($id, 'rejected', $state, $e->getMessage()); return $this->show($id);
                }
                $remote = $this->gateway->acceptedParent($accepted, $state['identities'], $state['source']);
                $state['init_after'] = now()->addSeconds(5)->format('Y-m-d H:i:s.u');
                $state['result'] = ['product_id' => $remote, 'skus' => [], 'published' => false];
                DB::transaction(function () use ($id,$remote,$state): void {
                    DB::table(self::TABLE)->where('id', $id)->update(['remote_product_id' => $remote]);
                    $this->save($id, $state['source']['has_model'] ? 'initializing_variants' : 'verifying', $state, 'Induk Gita diterima dalam status UNLIST; memeriksa hasil.');
                });
            }
        } catch (\Throwable $e) {
            $latest = $this->row($id);
            if ($latest->remote_product_id) { $state['result'] ??= ['product_id' => $latest->remote_product_id, 'skus' => [], 'published' => false]; $this->save($id, 'partial_unverified', $state, 'Produk Gita '.$latest->remote_product_id.' belum terverifikasi lengkap. Gunakan Periksa Status atau periksa Seller Center; induk/varian/publikasi yang sudah dicoba tidak dikirim ulang.'); }
            elseif ($latest->attempted_at) { $this->save($id, 'submitted_unverified', $state, 'Hasil pembuatan induk belum diketahui. Periksa Seller Center; produk tidak akan dibuat ulang.'); }
            else { $this->save($id, 'blocked', $state, $e instanceof \DomainException ? $e->getMessage() : 'Pemeriksaan Gita gagal. Periksa koneksi, akun, dan proses marketplace lain.'); }
        } finally {
            $this->lease->release();
            DB::table(self::GUARDS)->where('source_product_id', $row->source_product_id)->where('owner', $owner)->update(['owner' => null, 'expires_at' => null]);
        }
        return $this->show($id);
    }

    private function prepare(object $row, array $state): void
    {
        if ($state['prepare'] === 'source') {
            $state['identities'] = $this->gateway->identities(); $state['source'] = $this->gateway->source($row->source_product_id);
            if ($this->gateway->linked($row->source_product_id)) { $this->save($row->id, 'exists', $state, 'Produk sudah memiliki relasi Gita.'); return; }
            $state['prepare'] = 'metadata'; $this->save($row->id, 'preparing', $state, 'Memvalidasi metadata Gita.'); return;
        }
        $validated = $this->gateway->context($state['source'], $state['context']); $state = [...$state, ...$validated];
        if ($state['required_fields']) { $this->save($row->id, 'blocked', $state, 'Lengkapi konteks Gita yang ditampilkan untuk melanjutkan.'); return; }
        $state['scan_revision'] = $this->revision->current(); $state['scan_attempts'] = $this->attempts();
        $state['scan'] = ['group' => 0, 'offset' => 0, 'seen' => [], 'queue' => [], 'count' => 0, 'total' => null, 'done' => false];
        $this->save($row->id, 'scanning', $state, 'Memeriksa seluruh katalog Gita, termasuk produk tidak aktif.');
    }

    private function scan(object $row, array $state): void
    {
        $scan = &$state['scan'];
        if ($scan['queue']) {
            $id = array_shift($scan['queue']); $match = $this->gateway->duplicate($state['source'], $id); $state['progress']['scanned_products']++;
            if ($match) { $state['result'] = $match === 'exists' ? ['product_id' => $id, 'skus' => [], 'published' => false] : null; $this->save($row->id, $match === 'exists' ? 'exists' : 'blocked', $state, $match === 'exists' ? 'SKU sumber sudah ditemukan di Gita. Periksa relasinya.' : 'Judul yang sama ditemukan tanpa bukti SKU. Periksa Seller Center sebelum mencoba lagi.'); return; }
        } elseif ($scan['group'] < count(self::STATUSES)) {
            $p = $this->gateway->page(self::STATUSES[$scan['group']], $scan['offset']);
            if (array_intersect($scan['seen'], $p['ids']) || ($scan['total'] !== null && $scan['total'] !== $p['total'])) { throw new \DomainException('Katalog Gita berubah atau memuat produk berulang.'); }
            $scan['seen'] = [...$scan['seen'], ...$p['ids']]; $scan['queue'] = $p['ids']; $scan['count'] += count($p['ids']); $scan['total'] = $p['total'];
            if ($p['next'] === null) { if ($scan['count'] !== $scan['total']) { throw new \DomainException('Jumlah katalog Gita belum lengkap.'); } $scan['group']++; $scan['offset'] = 0; $scan['count'] = 0; $scan['total'] = null; }
            else { $scan['offset'] = $p['next']; }
        }
        if ($scan['group'] === count(self::STATUSES) && !$scan['queue']) {
            $state['images'] = $this->gateway->images($state['source'], $state['context']); $state['progress']['total_images'] = count($state['images']);
            $this->save($row->id, 'uploading', $state, 'Mengunggah galeri, gambar opsi, dan tabel ukuran ke Gita.');
        } else { $this->save($row->id, 'scanning', $state, 'Memeriksa seluruh katalog Gita.'); }
    }

    private function afterParent(object $row, array $state, string $owner): void
    {
        $id = $row->remote_product_id;
        if ($row->publication_attempted_at) {
            $r = $this->gateway->verify($id, $state, true); $state['result'] = $this->publicResult($r);
            if ($r['status'] === 'NORMAL') { $this->save($row->id, 'success', $state, 'Produk Gita aktif dan seluruh konten/varian terverifikasi.'); }
            elseif ($r['status'] === 'REVIEWING') { $this->save($row->id, 'under_review', $state, 'Produk Gita terverifikasi dan sedang ditinjau Shopee.'); }
            else { throw new \DomainException('Publikasi belum terverifikasi.'); }
            return;
        }
        if ($state['source']['has_model'] && !$row->variants_attempted_at) {
            $parent = $this->gateway->verify($id, $state, false);
            if ($this->delay($state) > 0) { $this->save($row->id, 'initializing_variants', $state, 'Menunggu pemrosesan induk Gita sebelum varian.'); return; }
            $body = $this->gateway->variantPayload($id, $state['source'], $state['context'], $state['images']);
            $init = $this->mutation($row, $state, $owner, 'init_tier_variation', 'variants_attempted_at');
            $result = $init($body);
            StockHubGitaProductSource::need(is_array($result['model'] ?? null) && count($result['model']) === count($body['model']), 'Penerimaan model Gita belum lengkap.');
            $this->save($row->id, 'verifying', $state, 'Varian Gita diterima; memverifikasi seluruh model.'); return;
        }
        $result = $this->gateway->verify($id, $state, true); $state['result'] = $this->publicResult($result);
        StockHubGitaProductSource::need($result['status'] === 'UNLIST', 'Produk harus tetap UNLIST sebelum publikasi.');
        if ($row->status !== 'publishing') { $this->save($row->id, 'publishing', $state, 'Seluruh konten/varian cocok; siap mempublikasikan Gita.'); return; }
        $publish = $this->mutation($row, $state, $owner, 'unlist_item', 'publication_attempted_at');
        $accepted = $publish(['item_list' => [['item_id' => (int) $id, 'unlist' => false]]]);
        $this->gateway->publicationAccepted($accepted, $id);
        $this->save($row->id, 'verifying_publication', $state, 'Publikasi diterima; memeriksa status aktif Gita.');
    }

    private function mutation(object $row, array $state, string $owner, string $path, string $column): \Closure
    {
        $this->lease->renew();
        $owned = DB::table(self::GUARDS)->where('source_product_id', $row->source_product_id)->where('run_id', $row->id)->where('owner', $owner)->where('expires_at', '>', now())->update(['expires_at' => now()->addMinutes(5)]);
        StockHubGitaProductSource::need($owned === 1, 'Kepemilikan proses Gita berubah.');
        $submit = $this->gateway->prepareMutation($path, $state['identities']);
        $this->revision->beforeLegacyMutation();
        $marked = DB::table(self::TABLE)->where('id', $row->id)->whereNull($column)->update([$column => now(), 'updated_at' => now()]);
        StockHubGitaProductSource::need($marked === 1, 'Tahap Gita sudah pernah dicoba dan tidak boleh diulang.');
        return $submit;
    }

    private function attempts(): array
    {
        return DB::table(self::TABLE)->where(fn ($q) => $q->whereNotNull('attempted_at')->orWhereNotNull('variants_attempted_at')->orWhereNotNull('publication_attempted_at'))->orderBy('id')->get(['id','attempted_at','variants_attempted_at','publication_attempted_at'])->map(fn ($r) => (array) $r)->all();
    }

    private function row(string $id): object { $r = DB::table(self::TABLE)->where('id', $id)->first(); abort_unless($r, 404, 'Proses Gita tidak ditemukan.'); return $r; }
    private function save(string $id, string $status, array $state, string $message): void { $state['message'] = $message; DB::table(self::TABLE)->where('id', $id)->update(['status' => $status, 'state' => json_encode($state, JSON_THROW_ON_ERROR), 'updated_at' => now()]); }
    private function delay(array $state): int { return isset($state['init_after']) ? max(0, min(5000, (int) ceil(now()->diffInMilliseconds(\Illuminate\Support\Carbon::parse($state['init_after']), false)))) : 5000; }
    private function publicResult(array $r): array { return ['product_id' => $r['product_id'], 'skus' => $r['skus'], 'published' => $r['published']]; }
    private function canonical(array $value): array { if (!array_is_list($value)) { ksort($value); } foreach ($value as &$v) { if (is_array($v)) { $v = $this->canonical($v); } } return $value; }

    private function snapshot(object $row): array
    {
        $s = json_decode($row->state, true, 512, JSON_THROW_ON_ERROR); $status = $row->status;
        if ($row->attempted_at && !$row->remote_product_id && $status !== 'rejected') { $status = 'submitted_unverified'; }
        return ['run_id' => $row->id, 'source_product_id' => $row->source_product_id, 'status' => $status, 'stage' => $status, 'message' => $s['message'] ?? '', 'can_continue' => in_array($status, self::RUNNING, true), 'can_retry' => !$row->remote_product_id && in_array($status, ['blocked','rejected'], true), 'remote_product_id' => $row->remote_product_id, 'variant_count' => count($s['source']['variants'] ?? []), 'progress' => $s['progress'], 'required_fields' => $s['required_fields'] ?? [], 'context' => $s['context'], 'result' => $s['result'] ?? null, 'next_step_after_ms' => $status === 'initializing_variants' && !$row->variants_attempted_at ? $this->delay($s) : 0];
    }
}
