<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockHubTiktokProductService
{
    private const TABLE = 'stock_hub_tiktok_product_runs';
    private const GUARDS = 'stock_hub_tiktok_product_guards';
    private const RUNNING = ['preparing','scanning','uploading','submitting','verifying'];

    public function __construct(
        private StockHubTiktokProductGateway $gateway,
        private MarketplaceStockMirrorLease $lease,
        private MarketplaceCatalogMutationRevision $catalogRevision,
    ) {}

    public function start(string $product, string $key, array $context): array
    {
        $hash = hash('sha256', json_encode([$product, $context], JSON_THROW_ON_ERROR));
        $id = DB::transaction(function () use ($product, $key, $context, $hash): string {
            DB::table(self::GUARDS)->insertOrIgnore(['source_product_id' => $product]);
            $guard = DB::table(self::GUARDS)->where('source_product_id', $product)->lockForUpdate()->first();
            $repeat = DB::table(self::TABLE)->where('request_key', $key)->first();
            if ($repeat) {
                abort_unless($repeat->request_hash === $hash, 409, 'Request key sudah digunakan untuk input berbeda.');
                return $repeat->id;
            }
            if ($guard->run_id) {
                $current = DB::table(self::TABLE)->where('id', $guard->run_id)->first();
                if ($current && ! in_array($current->status, ['blocked','rejected'], true)) {
                    return $current->id;
                }
                if ($guard->owner && $guard->expires_at > now()->toDateTimeString()) {
                    return $guard->run_id;
                }
            }
            $id = (string) Str::uuid();
            $state = ['stage' => 'preparing', 'prepare' => 'source', 'message' => 'Menyiapkan produk Shopee Agni.', 'context' => $context, 'required_fields' => [], 'progress' => ['scanned_products' => 0, 'uploaded_images' => 0, 'total_images' => 0], 'result' => null];
            DB::table(self::TABLE)->insert(['id' => $id, 'request_key' => $key, 'request_hash' => $hash, 'source_product_id' => $product, 'status' => 'preparing', 'state' => json_encode($state, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            DB::table(self::GUARDS)->where('source_product_id', $product)->update(['run_id' => $id, 'owner' => null, 'expires_at' => null]);
            return $id;
        });
        return $this->show($id);
    }

    public function show(string $id): array { return $this->snapshot($this->row($id)); }

    public function source(string $id): ?array
    {
        $guard = DB::table(self::GUARDS)->where('source_product_id', $id)->first();
        return $guard?->run_id ? $this->show($guard->run_id) : null;
    }

    public function categories(): array { return $this->gateway->categories(); }

    public function step(string $id): array
    {
        $row = $this->row($id);
        if (! in_array($row->status, self::RUNNING, true) && ! ($row->status === 'submitted_unverified' && $row->remote_product_id)) { return $this->snapshot($row); }
        $owner = (string) Str::uuid();
        $claimed = DB::table(self::GUARDS)->where('source_product_id', $row->source_product_id)->where('run_id', $id)->where(fn ($q) => $q->whereNull('owner')->orWhere('expires_at', '<=', now()))->update(['owner' => $owner, 'expires_at' => now()->addMinutes(5)]);
        if (! $claimed) { return $this->show($id); }
        $state = [];
        try {
            $row = $this->row($id);
            $state = json_decode($row->state, true, 512, JSON_THROW_ON_ERROR);
            // A process can die after persisting the attempt but before saving its result.
            if ($row->attempted_at && ! $row->remote_product_id) {
                $this->save($id, 'submitted_unverified', $state, 'Terkirim, belum terverifikasi. Jangan membuat ulang produk; periksa Seller Center.');
                return $this->show($id);
            }
            $this->lease->acquire();
            if (isset($state['target_shop_id'])) {
                try {
                    $this->gateway->assertTargetShop($state['target_shop_id']);
                } catch (\Throwable $e) {
                    if ($row->status !== 'uploading') { throw $e; }
                    throw new \DomainException('Otorisasi akun TikTok untuk gambar gagal. Periksa akun dan token lalu coba kembali.');
                }
            }
            if ($row->attempted_at) {
                $result = $this->gateway->verify($state['source'], $state['context'], $row->remote_product_id);
                $state['result'] = $result;
                $this->save($id, $result['published'] ? 'success' : 'under_review', $state, $result['published'] ? 'Produk TikTok berhasil dibuat dan terverifikasi.' : 'Produk TikTok terverifikasi, menunggu peninjauan/publikasi.');
            } elseif ($row->status === 'preparing') {
                $this->prepare($row, $state);
            } elseif ($row->status === 'scanning') {
                $this->scan($row, $state);
            } elseif ($row->status === 'uploading') {
                $index = $state['progress']['uploaded_images'];
                if ($index < count($state['images'])) {
                    $state['images'][$index]['uri'] = $this->gateway->upload($state['images'][$index]);
                    $state['progress']['uploaded_images']++;
                }
                $done = $state['progress']['uploaded_images'] === count($state['images']);
                if ($done) { $state['payload'] = $this->gateway->payload($state['source'], $state['context'], $state['images']); }
                $this->save($id, $done ? 'submitting' : 'uploading', $state, $done ? 'Memeriksa sumber sebelum mengirim produk.' : 'Mengunggah gambar ke TikTok.');
            } elseif ($row->status === 'submitting') {
                if (($state['validated_category_version'] ?? null) !== StockHubTiktokProductGateway::CATEGORY_VERSION
                    || ($state['payload']['category_version'] ?? null) !== StockHubTiktokProductGateway::CATEGORY_VERSION) {
                    $state['required_fields'] = $this->gateway->contextFields(['category_id']);
                    throw new \DomainException('Kategori belum divalidasi dengan V2. Coba Lagi untuk memuat dan memvalidasi kategori V2.');
                }
                $fresh = $this->gateway->source($row->source_product_id);
                if (! $this->sameSource($fresh, $state['source'])) { throw new \DomainException('Produk sumber berubah selama proses. Periksa data dan coba kembali.'); }
                if ($this->gateway->linked($row->source_product_id)) { $this->save($id, 'exists', $state, 'Produk sudah memiliki relasi TikTok.'); return $this->show($id); }
                if ($this->catalogRevision->current() !== ($state['scan_revision'] ?? null)
                    || $this->attempts() !== $state['scan_attempts']) {
                    throw new \DomainException('Katalog tujuan berubah selama pemindaian. Ulangi pemeriksaan produk.');
                }
                $this->lease->renew();
                $submit = $this->gateway->prepareCreate($state['target_shop_id']);
                // This durable marker is never cleared, including after explicit rejection.
                DB::table(self::TABLE)->where('id', $id)->whereNull('attempted_at')->update(['attempted_at' => now(), 'updated_at' => now()]);
                try {
                    $result = $submit($state['payload']);
                } catch (StockHubTiktokProductRejected $e) {
                    $state['required_fields'] = $this->gateway->contextFields();
                    $this->save($id, 'rejected', $state, $e->getMessage());
                    return $this->show($id);
                }
                $remote = (string) ($result['product_id'] ?? $result['id'] ?? '');
                if (! ctype_digit($remote)) { throw new \DomainException('Identitas hasil TikTok belum tersedia.'); }
                DB::table(self::TABLE)->where('id', $id)->update(['remote_product_id' => $remote]);
                $state['accepted_sku_ids'] = array_values(array_filter(array_map(fn ($s) => (string) ($s['id'] ?? ''), $result['skus'] ?? []), fn ($id) => ctype_digit($id)));
                $state['result'] = ['product_id' => $remote, 'skus' => [], 'published' => false];
                $this->save($id, 'verifying', $state, 'Produk diterima TikTok; memverifikasi semua SKU.');
            }
        } catch (\Throwable $e) {
            $latest = $this->row($id);
            if ($latest->attempted_at) {
                $this->save($id, 'submitted_unverified', $state, 'Terkirim, belum terverifikasi. '.($latest->remote_product_id ? 'Gunakan Periksa Status; produk tidak akan dikirim ulang.' : 'Periksa Seller Center; produk tidak akan dikirim ulang.'));
            } else {
                $message = $e instanceof \DomainException ? $e->getMessage() : 'Pemeriksaan marketplace gagal. Periksa token, koneksi, dan proses marketplace lain lalu coba kembali.';
                if ($row->status === 'uploading') {
                    $message = 'Gambar '.(($state['progress']['uploaded_images'] ?? 0) + 1).'/'.($state['progress']['total_images'] ?? 0).': '.$message;
                }
                if ($row->status === 'preparing' && $e instanceof \DomainException) {
                    $keys = match ($state['prepare'] ?? '') {
                        'category', 'attributes' => ['category_id'],
                        'warehouse' => ['warehouse_id'],
                        default => [],
                    };
                    $state['required_fields'] = $this->gateway->contextFields($keys);
                }
                $this->save($id, 'blocked', $state, $message);
            }
        } finally {
            $this->lease->release();
            DB::table(self::GUARDS)->where('source_product_id', $row->source_product_id)->where('owner', $owner)->update(['owner' => null, 'expires_at' => null]);
        }
        return $this->show($id);
    }

    private function sameSource(array $fresh, array $saved): bool
    {
        // The gateway validates unique string IDs; only the variant list order may differ.
        $byId = fn (array $a, array $b): int => strcmp($a['id'], $b['id']);
        usort($fresh['variants'], $byId);
        usort($saved['variants'], $byId);

        return $fresh === $saved;
    }

    private function prepare(object $row, array $state): void
    {
        $phase = $state['prepare'];
        if ($phase === 'source') {
            $state['source'] = $this->gateway->source($row->source_product_id);
            $context = $this->gateway->context($state['source'], $state['context']);
            $state = [...$state, ...$context];
            if ($this->gateway->linked($row->source_product_id)) { $this->save($row->id, 'exists', $state, 'Produk sudah memiliki relasi TikTok.'); return; }
            if ($state['required_fields']) { $this->save($row->id, 'blocked', $state, 'Lengkapi konteks produk TikTok untuk melanjutkan.'); return; }
            $state['prepare'] = 'category';
        } elseif ($phase === 'category') {
            $this->gateway->validateCategory($state['context']['category_id']);
            $state['validated_category_version'] = StockHubTiktokProductGateway::CATEGORY_VERSION;
            $state['prepare'] = 'attributes';
        } elseif ($phase === 'attributes') {
            $this->gateway->validateAttributes($state['context']['category_id']);
            $state['prepare'] = 'warehouse';
        } elseif ($phase === 'warehouse') {
            $this->gateway->validateWarehouse($state['context']['warehouse_id']);
            $state['scan'] = ['cursor' => null, 'cursors' => [], 'seen' => [], 'queue' => [], 'complete' => false, 'total' => null];
            $state['scan_attempts'] = $this->attempts();
            $state['scan_revision'] = $this->catalogRevision->current();
            $this->save($row->id, 'scanning', $state, 'Memeriksa seluruh katalog TikTok, termasuk produk tidak aktif.');
            return;
        }
        $this->save($row->id, 'preparing', $state, 'Memvalidasi kategori, atribut, dan gudang TikTok.');
    }

    private function scan(object $row, array $state): void
    {
        $scan = &$state['scan'];
        if ($scan['queue']) {
            $id = array_shift($scan['queue']);
            $target = $this->gateway->target($id, true);
            $state['progress']['scanned_products']++;
            $match = $this->gateway->duplicate($state['source'], $target);
            if ($match === 'ambiguous') {
                $this->save($row->id, 'blocked', $state, 'Produk dengan judul sama ditemukan, tetapi hubungan SKU belum terbukti. Periksa produk '.$id.' di Seller Center dan perbaiki judul atau relasi SKU sebelum mencoba kembali.');
                return;
            }
            if ($match === 'exists') {
                $state['result'] = ['product_id' => $id, 'skus' => [], 'published' => false];
                $this->save($row->id, 'exists', $state, 'SKU produk sumber sudah ditemukan di TikTok. Periksa katalog dan relasinya.');
                return;
            }
        } elseif (! $scan['complete']) {
            $page = $this->gateway->page($scan['cursor']);
            if (array_intersect($scan['seen'], $page['ids']) || ($page['next'] !== null && in_array($page['next'], $scan['cursors'], true))) { throw new \DomainException('Paginasi katalog TikTok berulang. Segarkan katalog dan coba kembali.'); }
            if ($page['next'] !== null && ($page['ids'] === [] || $page['next'] === $scan['cursor'])) { throw new \DomainException('Paginasi katalog TikTok tidak bergerak.'); }
            if ($page['total'] !== null) {
                if (! ctype_digit((string) $page['total']) || ($scan['total'] !== null && (string) $page['total'] !== (string) $scan['total'])) { throw new \DomainException('Katalog TikTok berubah selama pemindaian.'); }
                $scan['total'] = $page['total'];
            }
            $scan['seen'] = [...$scan['seen'], ...$page['ids']];
            $scan['queue'] = $page['ids'];
            $scan['cursor'] = $page['next'];
            $scan['cursors'][] = $page['next'];
            $scan['complete'] = $page['next'] === null;
            if ($scan['complete'] && $scan['total'] !== null && count($scan['seen']) !== (int) $scan['total']) { throw new \DomainException('Jumlah katalog TikTok tidak lengkap.'); }
        }
        if ($scan['complete'] && ! $scan['queue']) {
            $state['images'] = $this->gateway->images($state['source']);
            $state['progress']['total_images'] = count($state['images']);
            $this->save($row->id, 'uploading', $state, 'Mengunggah gambar ke TikTok.');
        } else { $this->save($row->id, 'scanning', $state, 'Memeriksa seluruh katalog TikTok.'); }
    }

    private function row(string $id): object
    {
        $row = DB::table(self::TABLE)->where('id', $id)->first();
        abort_unless($row, 404, 'Proses pembuatan tidak ditemukan.');
        return $row;
    }

    private function attempts(): array
    {
        return DB::table(self::TABLE)->whereNotNull('attempted_at')->orderBy('id')->pluck('id')->all();
    }

    private function save(string $id, string $status, array $state, string $message): void
    {
        $state['stage'] = $status; $state['message'] = $message;
        DB::table(self::TABLE)->where('id', $id)->update(['status' => $status, 'state' => json_encode($state, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
    }

    private function snapshot(object $row): array
    {
        $s = json_decode($row->state, true, 512, JSON_THROW_ON_ERROR);
        $status = $row->status;
        if ($row->attempted_at && ! $row->remote_product_id && $status !== 'rejected') {
            $status = 'submitted_unverified';
        }

        return [
            'run_id' => $row->id,
            'source_product_id' => $row->source_product_id,
            'status' => $status,
            'stage' => $status,
            'message' => $status === 'submitted_unverified' && $row->status !== $status
                ? 'Terkirim, belum terverifikasi. Periksa Seller Center sebelum tindakan lain.'
                : ($s['message'] ?? ''),
            'can_continue' => in_array($status, self::RUNNING, true),
            'can_retry' => in_array($status, ['blocked','rejected'], true),
            'remote_product_id' => $row->remote_product_id,
            'variant_count' => count($s['source']['variants'] ?? []),
            'progress' => $s['progress'] ?? ['scanned_products' => 0, 'uploaded_images' => 0, 'total_images' => 0],
            'required_fields' => $s['required_fields'] ?? [],
            'context' => $s['context'] ?? [],
            'result' => $s['result'] ?? null,
        ];
    }
}
