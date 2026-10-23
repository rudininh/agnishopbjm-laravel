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
    private const PARTIAL_LINEAGE_LIMIT = 64;

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
            try { StockHubTiktokVariantInterlock::assertRepairAvailable($source); }
            catch (\DomainException $e) { abort(409, $e->getMessage()); }
            $repeat = DB::table(self::TABLE)->where('request_key', $key)->first();
            if ($repeat) { abort_unless(hash_equals($repeat->request_hash, $hash), 409, 'Identitas permintaan sudah dipakai untuk input berbeda.'); return $repeat->id; }
            $current = $guard->run_id ? DB::table(self::TABLE)->where('id', $guard->run_id)->first() : null;
            if ($current && (! $this->restartable($current) || ($guard->owner && $guard->expires_at > now()->toDateTimeString()))) { return $current->id; }
            $id = (string) Str::uuid();
            $state = ['stage' => 'preparing', 'message' => 'Membaca varian Agni dan produk TikTok terkait.', 'context' => $context, 'repair_available' => false,
                'required_fields' => [], 'progress' => ['scanned_products' => 0, 'uploaded_images' => 0, 'total_images' => 0], 'failures' => [], 'result' => null];
            if($current&&in_array($this->status($current),['blocked','rejected'],true)){
                $previous=json_decode($current->state,true,512,JSON_THROW_ON_ERROR);
                $state=[...$state,...array_intersect_key($previous,array_flip(['fixed_target_product_id','after_verified_repair']))];
                if(isset($previous['missing_recovery_of'])){
                    $repair=DB::table('stock_hub_tiktok_variant_repair_runs as r')->join('stock_hub_tiktok_variant_repair_guards as g','g.run_id','=','r.id')
                        ->where('g.key','source:'.$source)->where('r.source_product_id',$source)->where('r.target_product_id',$current->target_product_id)
                        ->whereIn('r.status',['success','exists'])->where('r.created_at','>',$current->created_at)->select('r.id')->first();
                    if($repair)$state['after_verified_repair']=$repair->id;
                    else $state=[...$state,...array_intersect_key($previous,array_flip(['missing_recovery_of','fixed_target_product_id','recovery_missing','recovery_retained']))];
                    // A verified explicit repair may remove/rename the previously pinned rows.
                    // Its new addition still stays bound to the same target and runs every fresh check.
                    $state['fixed_target_product_id']=$previous['fixed_target_product_id'];
                }
            }
            DB::table(self::TABLE)->insert(['id' => $id, 'request_key' => $key, 'request_hash' => $hash, 'source_product_id' => $source,
                'status' => 'preparing', 'state' => json_encode($state, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            DB::table(self::GUARDS)->where('key', 'source:'.$source)->update(['run_id' => $id, 'owner' => null, 'expires_at' => null]);
            return $id;
        });
        return $this->show($id);
    }

    public function show(string $id): array { return $this->snapshot($this->row($id)); }

    /** Internal batch continuation: preserve the accepted subset across new request identities. */
    public function startRemaining(string $source,string $prior,string $key,array $context=[]):array
    {
        $row=$this->row($prior);
        $this->require((string)$row->source_product_id===$source&&$this->status($row)==='partial','Hasil parsial tidak cocok dengan sumber batch.');
        $held=false;
        try{$this->lease->acquire();$held=true;$this->assertPartial($row,$source);}
        finally{if($held)$this->lease->release();}
        $run=$this->start($source,$key,[...$context,'prior_partial_run_id'=>$prior]);
        $this->require(($run['context']['prior_partial_run_id']??null)===$prior,'Proses lain masih memiliki penambahan. Periksa status tersimpan.');
        return $run;
    }

    public function source(string $source): ?array
    {
        $id = DB::table(self::GUARDS)->where('key', 'source:'.$source)->value('run_id');
        return $id ? $this->show($id) : null;
    }

    /** User-requested new attempt after dual approved readback proves additions absent. */
    public function recoverMissing(string $id, string $key): array
    {
        $prior=$this->row($id);$source=(string)$prior->source_product_id;
        $hash=hash('sha256',json_encode(['missing_recovery',$id,$source],JSON_THROW_ON_ERROR));
        $repeat=DB::table(self::TABLE)->where('request_key',$key)->first();
        if($repeat){abort_unless(hash_equals($repeat->request_hash,$hash),409,'Identitas permintaan telah digunakan.');return $this->snapshot($repeat);}
        $owner=(string)Str::uuid();$keys=['source:'.$source,'target:'.$prior->target_product_id];
        DB::transaction(function()use($keys,$id,$owner){
            $row=$this->row($id);$state=json_decode($row->state,true,512,JSON_THROW_ON_ERROR);
            abort_unless($this->canRecoverMissing($row,$state),409,'Periksa Status terbaru sebelum menambahkan varian yang belum ada.');
            foreach($keys as $key){
                $guard=$this->lockedGuard($key);
                abort_unless($guard->run_id===$id&&(!$guard->owner||$guard->expires_at<=now()->toDateTimeString()),409,'Produk sedang diproses. Muat status tersimpan.');
                DB::table(self::GUARDS)->where('key',$key)->update(['owner'=>$owner,'expires_at'=>now()->addMinutes(5)]);
            }
        });
        $held=false;
        try{
            $this->lease->acquire();$held=true;
            $prior=$this->row($id);$state=json_decode($prior->state,true,512,JSON_THROW_ON_ERROR);
            StockHubTiktokVariantInterlock::assertRepairAvailable($source,$prior->target_product_id);
            $this->gateway->assertTargetShop($state['target_shop_id']);
            $fresh=$this->gateway->source($source,$state['context']['allow_inactive_source']??false);
            $this->require(($fresh['id']??null)===$source&&($fresh['shop_id']??null)===($state['source']['shop_id']??null),'Identitas toko atau produk Agni berubah.');
            $proof=$this->gateway->reconcileMissing($fresh,$state['expected'],$prior->target_product_id);
            $this->require($proof['missing']!==[],'Semua varian kiriman sudah ditemukan. Gunakan Periksa Status untuk memverifikasi hasil.');
            $this->assertTargetRelation($fresh,$proof['target']);
            StockHubTiktokVariantInterlock::assertNoUnresolvedLegacy($prior->target_product_id,$fresh,$proof['target']);
            $this->lease->renew();
            $newId=(string)Str::uuid();
            DB::transaction(function()use($keys,$owner,$prior,$state,$proof,$newId,$key,$hash,$source){
                foreach($keys as $guardKey){$guard=$this->lockedGuard($guardKey);$this->require($guard->run_id===$prior->id&&$guard->owner===$owner&&$guard->expires_at>now()->toDateTimeString(),'Kepemilikan proses berubah. Muat status tersimpan.');}
                $state['missing_recovery']=['checked_at'=>now()->utc()->toIso8601String(),'next_run_id'=>$newId,'missing'=>$proof['missing']];
                $state['stage']='reconciled_missing';$state['message']='Varian yang belum ada sudah diperiksa. Penambahan dilanjutkan melalui proses baru.';
                DB::table(self::TABLE)->where('id',$prior->id)->update(['status'=>'reconciled_missing','state'=>json_encode($state,JSON_THROW_ON_ERROR),'updated_at'=>now()]);
                $next=['stage'=>'preparing','message'=>'Memeriksa ulang Agni dan TikTok untuk menambahkan varian yang masih belum ada.',
                    'context'=>array_intersect_key($state['context']??[],array_flip(['manual_price','allow_inactive_source'])),
                    'missing_recovery_of'=>$prior->id,'fixed_target_product_id'=>$prior->target_product_id,'repair_available'=>false,
                    'recovery_missing'=>$proof['missing'],
                    'recovery_retained'=>array_map(fn($v)=>['id'=>$v['id'],'seller_sku'=>$v['seller_sku'],'attributes'=>$this->payload->combination($v['sales_attributes'])],$proof['target']['skus']),
                    'required_fields'=>[],'progress'=>['scanned_products'=>0,'uploaded_images'=>0,'total_images'=>0],'failures'=>[],'result'=>null];
                DB::table(self::TABLE)->insert(['id'=>$newId,'request_key'=>$key,'request_hash'=>$hash,'source_product_id'=>$source,'target_product_id'=>$prior->target_product_id,
                    'status'=>'preparing','state'=>json_encode($next,JSON_THROW_ON_ERROR),'created_at'=>now(),'updated_at'=>now()]);
                DB::table(self::GUARDS)->whereIn('key',$keys)->where('owner',$owner)->update(['run_id'=>$newId]);
            });
            return $this->show($newId);
        }catch(\Throwable $e){
            $state=json_decode($this->row($id)->state,true,512,JSON_THROW_ON_ERROR);
            $this->save($id,$this->status($this->row($id)),$state,$e instanceof \DomainException
                ? 'Penambahan belum dilanjutkan: '.$e->getMessage()
                : 'Pemeriksaan penambahan belum berhasil. Periksa koneksi dan akun lalu coba kembali; tidak ada varian dikirim ulang.');
            return $this->show($id);
        }finally{
            if($held)$this->lease->release();
            DB::table(self::GUARDS)->whereIn('key',$keys)->where('owner',$owner)->update(['owner'=>null,'expires_at'=>null]);
        }
    }

    public function step(string $id): array
    {
        $row = $this->row($id);
        if (! in_array($this->status($row), [...self::RUNNING, 'submitted_unverified', 'under_review'], true)) { return $this->snapshot($row); }
        $owner = (string) Str::uuid();
        $claimed = DB::table(self::GUARDS)->where('key', 'source:'.$row->source_product_id)->where('run_id', $id)
            ->where(fn ($q) => $q->whereNull('owner')->orWhere('expires_at', '<=', now()))->update(['owner' => $owner, 'expires_at' => now()->addMinutes(5)]);
        if (! $claimed) { return $this->show($id); }
        $state = []; $checking = false;
        try {
            $row = $this->row($id); $checking = $row->attempted_at !== null;
            $state = json_decode($row->state, true, 512, JSON_THROW_ON_ERROR);
            if(!in_array($this->status($row),[...self::RUNNING,'submitted_unverified','under_review'],true))return $this->snapshot($row);
            if ($this->delay($state) > 0) { return $this->show($id); }
            unset($state['coordination_retry_at']);
            try { $this->lease->acquire(); }
            catch (MarketplaceOperationBusy) {
                $state['coordination_retry_at'] = now()->addSeconds(5)->getTimestampMs();
                if($checking)$state['verification']=['checked_at'=>now()->utc()->toIso8601String(),'outcome'=>'unavailable','code'=>'coordination_busy'];
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
            if($checking) {
                $state['verification']=['checked_at'=>now()->utc()->toIso8601String(),'outcome'=>'unavailable','code'=>'check_failed'];
                if($e instanceof StockHubTiktokVariantCoverageMismatch)$state['verification']=[...$state['verification'],...$e->verification,'outcome'=>'mismatch'];
                elseif($e instanceof StockHubTiktokVariantStockMismatch)$state['verification']=[...$state['verification'],'outcome'=>'mismatch','code'=>'stock_mismatch'];
            }
            if ($e instanceof StockHubTiktokVariantIdentityRepairRequired) { $state['repair_available'] = true; }
            $this->save($id, $attempted ? 'submitted_unverified' : 'blocked', $state, $attempted
                ? ($e instanceof StockHubTiktokVariantStockMismatch || $e instanceof StockHubTiktokVariantCoverageMismatch ? $e->getMessage() : 'Hasil pengiriman varian belum dapat dipastikan. Gunakan Periksa Status; jangan menambahkan ulang.')
                : ($e instanceof \DomainException ? $e->getMessage() : 'Pemeriksaan varian gagal. Periksa akun, token, koneksi, dan katalog lalu coba kembali.'));
        } finally {
            $this->lease->release();
            DB::table(self::GUARDS)->where('key', 'source:'.$row->source_product_id)->where('owner', $owner)->update(['owner' => null, 'expires_at' => null]);
        }
        return $this->show($id);
    }

    private function prepare(object $row, array $state): void
    {
        StockHubTiktokVariantInterlock::assertRepairAvailable($row->source_product_id);
        $this->assertContinuation($row,$state);
        $source = $this->gateway->source($row->source_product_id, $state['context']['allow_inactive_source'] ?? false);
        $coverage = $this->coverage->forProducts([['item_id' => $source['id'], 'shop_id' => $source['shop_id'],
            'models' => array_map(fn ($v) => ['model_id' => $v['id'], 'model_sku' => $v['seller_sku'], 'name' => $v['name']], $source['variants'])]])[$source['id']]['tiktok-agnishopbjm'];
        $this->require(in_array($coverage['state'], ['complete', 'missing_variants'], true) && $coverage['target_product_id'], 'Produk TikTok terkait belum dapat dipastikan. Segarkan katalog atau perbaiki relasi SKU.');
        $targetId = (string) $coverage['target_product_id'];
        if(isset($state['fixed_target_product_id']))$this->require($targetId===$state['fixed_target_product_id'],'Produk TikTok tujuan pemulihan berubah. Periksa relasi sebelum melanjutkan.');
        if(isset($state['context']['prior_partial_run_id']))$this->require($this->row($state['context']['prior_partial_run_id'])->target_product_id===$targetId,'Produk TikTok hasil parsial berubah. Periksa relasi sebelum melanjutkan.');
        $this->bindTarget($row, $targetId);
        $state['target_product_id'] = $targetId; $state['target_shop_id'] = $this->gateway->targetShop(); $state['source'] = $source;
        $this->gateway->assertTargetShop($state['target_shop_id']);
        $target = $this->gateway->target($targetId);
        $this->assertRecoveryContract($source,$target,$state);
        $this->assertTargetRelation($source, $target);
        StockHubTiktokVariantInterlock::assertNoUnresolvedLegacy($targetId, $source, $target);
        $state['package_template'] = $this->packages->snapshot();
        $package = $this->packages->apply($target, $source, $state['package_template']);
        $target = $package['target']; $state['package_template_applied'] = $package['applied'];
        $prepared = $this->payload->prepare($source, $target, $state['context']['manual_price'] ?? null);
        $this->assertRecoverySubset($prepared,$state);
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
        StockHubTiktokVariantInterlock::assertRepairAvailable($row->source_product_id, $row->target_product_id);
        $this->assertContinuation($row,$state);
        $this->require($this->revision->current() === $state['scan_revision'] && $this->attempts() === $state['scan_attempts'], 'Katalog berubah selama proses. Coba Lagi untuk memeriksa ulang semua SKU.');
        $source = $this->gateway->source($row->source_product_id, $state['context']['allow_inactive_source'] ?? false);
        $this->require($this->sameSource($source, $state['source']), 'Data varian Agni berubah selama proses. Coba Lagi untuk membaca sumber terbaru.');
        $target = $this->gateway->target($state['target_product_id']);
        $this->assertRecoveryContract($source,$target,$state);
        $this->assertTargetRelation($source, $target);
        StockHubTiktokVariantInterlock::assertNoUnresolvedLegacy($state['target_product_id'], $source, $target);
        $package = isset($state['package_template']) ? $this->packages->apply($target, $source, $state['package_template']) : null;
        if ($package !== null) { $target = $package['target']; }
        $fresh = $this->payload->prepare($source, $target, $state['context']['manual_price'] ?? null);
        $this->assertRecoverySubset($fresh,$state);
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
        $saved=DB::table(self::TABLE)->where('id', $row->id)->where('status','submitting')->whereNull('attempted_at')->whereNull('accepted_at')->update(['state' => json_encode($state, JSON_THROW_ON_ERROR), 'attempted_at' => now(), 'updated_at' => now()]);
        $this->require($saved===1,'Persiapan telah berubah atau percobaan sudah tercatat. Periksa Status.');
        $edit($body);
        DB::table(self::TABLE)->where('id', $row->id)->update(['accepted_at' => now()]);
        $this->save($row->id, 'verifying', $state, 'Batch varian diterima. Memeriksa hasil TikTok tanpa mengirim ulang.');
    }

    private function readback(object $row, array $state): void
    {
        $result = $this->gateway->verify($state['source'], $state['expected'], $state['target_product_id']);
        $result['variants'] = [...$result['variants'], ...$state['failures'], ...($state['skipped'] ?? [])]; $state['result'] = $result;
        $status = ! $result['published'] ? 'under_review' : ($state['failures'] ? 'partial' : 'success');
        $state['verification']=['checked_at'=>now()->utc()->toIso8601String(),'outcome'=>!$result['published']?'under_review':($state['failures']?'partial':'verified'),
            'code'=>$result['published']?'verified':'under_review','expected_count'=>count($state['expected']['payload']['skus']),
            'actual_count'=>count($state['expected']['payload']['skus']),'missing_count'=>0,'missing_variants'=>[]];
        DB::table(self::TABLE)->where('id', $row->id)->update(['accepted_at' => $row->accepted_at ?? now()]);
        $this->save($row->id, $status, $state, $status === 'under_review' ? 'Varian terverifikasi pada versi peninjauan TikTok. Periksa Status setelah peninjauan selesai.'
            : ($status === 'partial' ? 'Sebagian varian berhasil. Periksa rincian gambar yang gagal.' : 'Semua varian dalam batch terverifikasi di TikTok.'));
    }

    private function assertContinuation(object $row,array $state):void
    {
        if(array_key_exists('prior_partial_run_id',$state['context']??[]))$this->assertPartial($this->partialAncestor($state['context']['prior_partial_run_id']),(string)$row->source_product_id);
    }
    private function assertPartial(object $row,string $source):void
    {
        // An unsent partial still inherits accepted IDs from earlier continuations.
        // Validate the complete bounded lineage before any readback/cache reconciliation.
        $lineage=[];$seen=[];$target=$row->target_product_id;
        while(true){
            $this->require(count($lineage)<self::PARTIAL_LINEAGE_LIMIT&&!isset($seen[$row->id]),'Riwayat hasil parsial berulang atau terlalu panjang. Periksa hasil tersimpan; jangan kirim ulang.');
            $this->require((string)$row->source_product_id===$source&&$this->status($row)==='partial'
                &&is_string($target)&&$target!==''&&$row->target_product_id===$target,'Identitas riwayat hasil parsial berbeda atau belum pasti.');
            $state=json_decode($row->state,true,512,JSON_THROW_ON_ERROR);
            $this->require(is_array($state)&&(!array_key_exists('context',$state)||is_array($state['context'])),'Bukti riwayat hasil parsial belum lengkap.');
            $seen[$row->id]=true;$lineage[]=[$row,$state];
            if(!array_key_exists('prior_partial_run_id',$state['context']??[]))break;
            $row=$this->partialAncestor($state['context']['prior_partial_run_id']);
        }
        foreach($lineage as [$row,$state]){
            $accepted=array_values(array_filter($state['result']['variants']??[],fn($v)=>($v['status']??null)==='updated'));
            if($row->attempted_at===null&&$row->accepted_at===null){$this->require(!$accepted&&!isset($state['expected']),'Bukti hasil parsial belum lengkap.');continue;}
            $this->require($row->attempted_at!==null&&$row->accepted_at!==null&&$accepted!==[]&&isset($state['expected'],$state['source'],$state['target_shop_id']),'Bukti hasil parsial belum lengkap.');
            $this->gateway->assertTargetShop($state['target_shop_id']);
            $fresh=$this->gateway->source($source,$state['context']['allow_inactive_source']??false);
            $this->require($this->sameSource($fresh,$state['source']),'Sumber hasil parsial berubah. Periksa identitas sebelum melanjutkan.');
            $verified=$this->gateway->verifyContinuation($fresh,$state['expected'],$row->target_product_id,$accepted);
            $this->require(($verified['published']??false)===true,'Hasil parsial sebelumnya belum disetujui TikTok.');
        }
    }
    private function partialAncestor(mixed $id):object
    {
        $this->require(is_string($id)&&Str::isUuid($id),'Identitas riwayat hasil parsial tidak valid.');
        $row=DB::table(self::TABLE)->where('id',$id)->first();
        $this->require($row!==null,'Riwayat hasil parsial tidak ditemukan. Periksa hasil tersimpan; jangan kirim ulang.');
        return $row;
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

    private function assertRecoveryContract(array $source,array $target,array $state):void
    {
        if(!isset($state['missing_recovery_of']))return;
        $this->require(!empty($state['recovery_missing'])&&!empty($state['recovery_retained']),'Bukti pemulihan belum lengkap.');
        $bySku=array_column($target['skus'],null,'seller_sku');
        $allowed=[...array_column($state['recovery_retained'],'seller_sku'),...array_column($state['recovery_missing'],'seller_sku')];
        $this->require(!array_diff(array_keys($bySku),$allowed),'Ada varian TikTok di luar rencana pemulihan. Periksa relasi produk terlebih dahulu.');
        foreach($state['recovery_retained'] as $pin){
            $row=$bySku[$pin['seller_sku']]??null;
            $this->require($row!==null&&$row['id']===$pin['id']&&$this->payload->combination($row['sales_attributes'])===$pin['attributes'],'Varian TikTok yang dipertahankan berubah atau hilang. Periksa relasi sebelum melanjutkan.');
        }
        foreach($state['recovery_missing'] as $pin){
            $matches=array_filter($source['variants'],fn($v)=>$v['id']===($pin['source_variant_id']??$pin['id']??null)&&$v['seller_sku']===$pin['seller_sku']);
            $this->require(count($matches)===1,'Identitas varian Agni untuk pemulihan berubah. Periksa SKU sumber.');
        }
    }

    private function assertRecoverySubset(array $prepared,array $state):void
    {
        if(!isset($state['missing_recovery_of']))return;
        $this->require(!array_diff(array_column($prepared['variants'],'seller_sku'),array_column($state['recovery_missing'],'seller_sku')),'Ada varian lain yang belum ada di luar rencana pemulihan. Periksa sumber dan relasi terlebih dahulu.');
    }

    private function bindTarget(object $row, string $target): void
    {
        DB::transaction(function () use ($row, $target): void {
            $guard = $this->lockedGuard('target:'.$target);
            StockHubTiktokVariantInterlock::assertRepairAvailable($row->source_product_id, $target);
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
    private function save(string $id, string $status, array $s, string $message): void
    {
        DB::transaction(function()use($id,$status,$s,$message){
            $current=DB::table(self::TABLE)->where('id',$id)->lockForUpdate()->first();
            if(json_decode($current->state,true)['batch_retired_before_attempt']??false)return;
            $s['stage']=$status;$s['message']=$message;
            DB::table(self::TABLE)->where('id',$id)->update(['status'=>$status,'state'=>json_encode($s,JSON_THROW_ON_ERROR),'updated_at'=>now()]);
        });
    }

    private function snapshot(object $row): array
    {
        $s = json_decode($row->state, true, 512, JSON_THROW_ON_ERROR); $status = $this->status($row);
        $message=$status === 'submitted_unverified' && $status !== $row->status ? 'Hasil pengiriman belum pasti. Periksa Status; jangan kirim ulang.' : ($s['message'] ?? '');
        if($this->canRecoverMissing($row,$s))$message=str_replace('jangan menambahkan ulang.','gunakan Tambahkan Varian yang Belum Ada untuk memeriksa ulang dan melanjutkan.',$message);
        return ['run_id' => $row->id, 'source_product_id' => $row->source_product_id, 'remote_product_id' => $row->target_product_id,
            'status' => $status, 'stage' => $status, 'message' => $message,
            'can_continue' => in_array($status, self::RUNNING, true), 'can_retry' => $this->restartable($row), 'required_fields' => $s['required_fields'] ?? [],
            'context' => $s['context'] ?? [], 'progress' => $s['progress'] ?? [], 'price' => $s['prepared']['price'] ?? null,
            'package_template' => $s['package_template'] ?? config('stock_hub_package'), 'package_template_applied' => $s['package_template_applied'] ?? false,
            'variant_count' => count($s['prepared']['variants'] ?? []), 'result' => $s['result'] ?? null, 'next_step_after_ms' => $this->delay($s),
            'verification' => $s['verification'] ?? null,
            'can_add_missing' => $this->canRecoverMissing($row,$s),
            'repair_available' => ($s['repair_available'] ?? false) === true,
            'repair_check_available' => !array_key_exists('repair_available', $s) && $status === 'blocked' && !$row->attempted_at && $row->target_product_id !== null,
            'failure_kind' => ($s['failure_kind'] ?? null) === 'coordination_unavailable' ? 'coordination_unavailable' : null];
    }

    private function canRecoverMissing(object $row,array $state):bool
    {
        $v=$state['verification']??[];
        return $row->attempted_at!==null&&$row->accepted_at!==null&&$row->target_product_id!==null
            &&in_array($this->status($row),['submitted_unverified','under_review'],true)
            &&($v['outcome']??null)==='mismatch'&&($v['code']??null)==='coverage_mismatch'
            &&($v['audit_status']??null)==='APPROVED'&&($v['missing_count']??0)>0;
    }
}
