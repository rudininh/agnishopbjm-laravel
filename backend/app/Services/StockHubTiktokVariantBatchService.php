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

    public function start(string $requestKey, string $workflow = 'legacy'): array
    {
        abort_unless(in_array($workflow,['legacy',StockHubTiktokVariantBatchPipeline::WORKFLOW],true),422);
        $id = DB::transaction(function () use ($requestKey,$workflow): string {
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
            DB::table(self::RUNS)->insert(['id' => $id, 'request_key' => $requestKey, 'workflow'=>$workflow, 'status' => $products ? 'running' : 'completed',
                'message' => $products ? 'Katalog tersimpan siap diproses.' : 'Tidak ada produk Agni dalam katalog tersimpan.', 'created_at' => now(), 'updated_at' => now()]);
            DB::table(self::REQUESTS)->insert(['request_key' => $requestKey, 'batch_id' => $id]);
            foreach ($products as $position => $product) {
                $coverage = $product['coverage'];
                $status = match ($coverage['state'] ?? 'unknown') {
                    'missing_variants' => 'queued', 'complete' => 'skipped_complete', 'missing_product' => 'missing_product', default => 'unknown',
                };
                if($workflow===StockHubTiktokVariantBatchPipeline::WORKFLOW && $status==='skipped_complete')$status='queued';
                DB::table(self::ITEMS)->insert(['batch_id' => $id, 'position' => $position, 'source_product_id' => $product['source_product_id'],
                    'workflow_stage'=>$workflow===StockHubTiktokVariantBatchPipeline::WORKFLOW?'recover':null,
                    'source_sku_request_key'=>(string)Str::uuid(),'repair_request_key'=>(string)Str::uuid(),
                    'name' => mb_substr($product['name'], 0, 500), 'request_key' => (string) Str::uuid(), 'status' => $status,
                    'missing_count' => isset($coverage['missing_count']) ? max(0, (int) $coverage['missing_count']) : null,
                    'message' => mb_substr($coverage['message'] ?? '', 0, 500), 'processed' => DB::raw($status !== 'queued' ? 'true' : 'false'), 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table(self::CLAIMS)->where('key', 'catalog')->update(['run_id' => $products ? $id : null, 'owner' => null, 'expires_at' => null]);
            return $id;
        });
        // Competing starts alias the active catalog. Only the original durable key
        // authorizes the caller to follow automatically, including lost-response retry.
        return [...$this->show($id),'matches_request_key'=>hash_equals($this->row($id)->request_key,$requestKey)];
    }

    public function active(): ?array
    {
        $id = DB::table(self::CLAIMS)->where('key', 'catalog')->value('run_id');
        return $id ? $this->show($id) : null;
    }

    public function show(string $id): array
    {
        $run = $this->row($id);
        $pipeline=$run->workflow===StockHubTiktokVariantBatchPipeline::WORKFLOW;
        $rows = DB::table(self::ITEMS)->where('batch_id', $id)->orderBy('position')->get();
        // A per-product status check can finish an already processed child later.
        // Overlay only the same saved identity using local reads; never step or replace it.
        $children = DB::table('stock_hub_tiktok_variant_runs')->whereIn('id', $rows->pluck('child_run_id')->filter()->all())
            ->get(['id', 'source_product_id', 'status', 'state', 'attempted_at', 'accepted_at', 'updated_at'])->keyBy('id');
        $current = $rows->first(fn ($item) => ! $item->processed);
        $summary = ['products_total' => $rows->count(), 'products_processed' => 0, 'variants_verified' => 0,
            'variants_pending' => 0, 'variants_failed' => 0, 'variants_skipped' => 0, 'products_need_attention' => 0,
            'agni_skus_verified'=>0,'tiktok_variants_repaired'=>0,'tiktok_variants_removed'=>0,'products_waiting_review'=>0];
        $items = [];
        foreach ($rows as $item) {
            $progress=null;$updatedAt=$item->updated_at;
            $result = $item->result === null ? null : json_decode($item->result, true, 512, JSON_THROW_ON_ERROR);
            $child = $children->get($item->child_run_id);
            if ($child && (string) $child->source_product_id === (string) $item->source_product_id && (!$pipeline||in_array($item->workflow_stage,['addition','completed','recover_addition'],true))) {
                $state = json_decode($child->state, true, 512, JSON_THROW_ON_ERROR);
                $progress=$this->savedProgress($state);$updatedAt=max($updatedAt,$child->updated_at);
                if($item->processed) {
                    $item->status = $child->attempted_at && ! $child->accepted_at && $child->status !== 'rejected' ? 'submitted_unverified' : $child->status;
                    $item->message = mb_substr($state['message'] ?? $item->message, 0, 500);
                    $result = $state['result'] ?? null;
                }
            }
            $summary['products_processed'] += (int) $item->processed;
            $sourceResult=$item->source_sku_result?json_decode($item->source_sku_result,true,512,JSON_THROW_ON_ERROR):null;
            $repairResult=$item->repair_result?json_decode($item->repair_result,true,512,JSON_THROW_ON_ERROR):null;
            $sourceStatus=null;$repairStatus=null;
            if($pipeline) {
                foreach(['source_sku'=>['stock_hub_agni_sku_runs','source_sku_run_id'],'repair'=>['stock_hub_tiktok_variant_repair_runs','repair_run_id']] as $kind=>[$table,$column]) {
                    $saved=$item->$column?DB::table($table)->where('id',$item->$column)->where('source_product_id',$item->source_product_id)->first():null;
                    if(!$saved)continue;
                    $state=json_decode($saved->state,true,512,JSON_THROW_ON_ERROR); $status=$saved->status;
                    if($kind==='repair'&&$saved->attempted_at&&!$saved->accepted_at&&$status!=='rejected')$status='submitted_unverified';
                    if($kind==='source_sku') {
                        $sourceStatus=$status;
                        $sourceResult=['changed'=>DB::table('stock_hub_agni_sku_attempts')->where('run_id',$saved->id)->orderBy('id')->get()->map(fn($a)=>['name'=>$a->name,'old_sku'=>$a->old_sku,'seller_sku'=>$a->seller_sku,'status'=>$a->verified_at?'verified':($a->status==='rejected'?'rejected':($a->attempted_at?'submitted_unverified':'queued'))])->all()];
                        if(in_array($status,['success','exists'],true))$summary['agni_skus_verified']+=count(array_filter($sourceResult['changed'],fn($a)=>$a['status']==='verified'));
                    }else {
                        $repairStatus=$status;
                        $repairResult=$state['result']??$repairResult;
                        if(in_array($status,['success','exists'],true)){$summary['tiktok_variants_repaired']+=count($repairResult['changed']??[]);$summary['tiktok_variants_removed']+=count($repairResult['deleted']??[]);}
                    }
                    if(in_array($item->workflow_stage,[$kind,'recover_'.$kind],true)){
                        $item->status=$status;$item->message=mb_substr($state['message']??$item->message,0,500);
                        $updatedAt=max($updatedAt,$saved->updated_at);
                        $progress=$kind==='source_sku'
                            ?['verified_skus'=>count(array_filter($sourceResult['changed'],fn($a)=>$a['status']==='verified')),'total_skus'=>count($sourceResult['changed'])]
                            :$this->savedProgress($state);
                    }
                }
            }
            $attention = $item->processed && ($pipeline?$item->workflow_stage!=='completed':!in_array($item->status, ['success', 'exists', 'skipped_complete'], true));
            $summary['products_need_attention'] += (int) $attention;
            $summary['products_waiting_review']+=(int)($attention&&in_array($item->status,['under_review','submitted_unverified'],true));
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
                'workflow_stage'=>$item->workflow_stage,'source_sku_run_id'=>$item->source_sku_run_id,'repair_run_id'=>$item->repair_run_id,
                'source_sku_result'=>$sourceResult,'repair_result'=>$repairResult,
                'source_sku_status'=>$sourceStatus,'repair_status'=>$repairStatus,
                'progress'=>$progress,'updated_at'=>\Carbon\Carbon::parse($updatedAt)->utc()->toIso8601String(),
                'child_run_id' => $item->child_run_id, 'missing_count' => $item->missing_count === null ? null : (int) $item->missing_count,
                'message' => $item->message, 'result' => $result];
        }
        $claim = DB::table(self::CLAIMS)->where('key', 'catalog')->where('run_id', $id)->first();
        $owned = $claim && $claim->owner && $claim->expires_at > now()->toDateTimeString();
        $status = $run->status;
        if ($run->pause_requested && $status !== 'completed') { $status = $owned ? 'pausing' : 'paused'; }
        $delay = $current ? max(0, min(5000, (int) $current->retry_at - now()->getTimestampMs())) : 0;
        if ($owned) { $delay = max($delay, 1000); }
        $catalog=DB::table(self::CLAIMS)->where('key','catalog')->first();
        return ['run_id' => $id, 'workflow'=>$run->workflow,
            'can_resume_attention'=>$pipeline&&$summary['products_need_attention']>0&&(!$catalog?->run_id||$catalog->run_id===$id)&&!$owned,
            'status' => $status, 'message' => $run->message, 'can_continue' => $status === 'running',
            'package_template' => config('stock_hub_package'),
            'next_step_after_ms' => $delay, 'summary' => $summary, 'items' => $items,
            'current_source_product_id' => $current?->source_product_id];
    }

    /** Local read-only projection; never expose scan cursors or arbitrary child state. */
    private function savedProgress(array $state):?array
    {
        if(!is_array($state['progress']??null))return null;
        $count=fn($value)=>is_int($value)&&$value>=0&&$value<=10000?$value:0;
        $scanned=$count($state['progress']['scanned_products']??null);
        $total=$state['scan']['total']??null;
        if(is_string($total)&&ctype_digit($total)&&strlen($total)<=5)$total=(int)$total;
        $total=is_int($total)&&$total>=$scanned&&$total<=10000?$total:null;
        return ['scanned_products'=>$scanned,'total_products'=>$total,
            'uploaded_images'=>$count($state['progress']['uploaded_images']??null),'total_images'=>$count($state['progress']['total_images']??null)];
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

    public function resume(string $id, ?string $workflow = null): array
    {
        abort_unless($workflow===null||$workflow===StockHubTiktokVariantBatchPipeline::WORKFLOW,422);
        DB::transaction(function () use ($id,$workflow): void {
            // Match step's lock order. Do not undo a pause while a child call is outstanding.
            $claim = DB::table(self::CLAIMS)->where('key', 'catalog')->lockForUpdate()->first();
            $run = DB::table(self::RUNS)->where('id', $id)->lockForUpdate()->first();
            abort_unless($run, 404);
            if ($claim?->owner && $claim->expires_at > now()->toDateTimeString()) { return; }
            $pipeline=$run->workflow===StockHubTiktokVariantBatchPipeline::WORKFLOW||$workflow===StockHubTiktokVariantBatchPipeline::WORKFLOW;
            if($pipeline) {
                abort_unless(!$claim?->run_id||$claim->run_id===$id,409,'Batch lain memiliki katalog.');
                $upgrading=$run->workflow!==StockHubTiktokVariantBatchPipeline::WORKFLOW;
                $coverage=collect($this->selection->forProducts())->keyBy('source_product_id');
                foreach(DB::table(self::ITEMS)->where('batch_id',$id)->orderBy('position')->get() as $item) {
                    if($upgrading) {
                        DB::table(self::ITEMS)->where('id',$item->id)->update(['workflow_stage'=>'recover','source_sku_request_key'=>$item->source_sku_request_key??(string)Str::uuid(),
                            'repair_request_key'=>$item->repair_request_key??(string)Str::uuid(),'status'=>in_array($item->status,['missing_product','unknown'],true)?$item->status:'queued','processed'=>DB::raw(in_array($item->status,['missing_product','unknown'],true)?'true':'false')]);
                        continue;
                    }
                    if($item->processed&&in_array($item->status,['missing_product','unknown'],true)) {
                        $state=$coverage->get($item->source_product_id)['coverage']['state']??'unknown';
                        if(in_array($state,['complete','missing_variants'],true))DB::table(self::ITEMS)->where('id',$item->id)->update(['workflow_stage'=>'recover','status'=>'queued','processed'=>DB::raw('false')]);
                    }elseif($item->processed)app(StockHubTiktokVariantBatchPipeline::class)->revisit($item);
                }
                DB::table(self::RUNS)->where('id',$id)->update(['workflow'=>StockHubTiktokVariantBatchPipeline::WORKFLOW]);
                if(!DB::table(self::ITEMS)->where('batch_id',$id)->whereRaw('processed = false')->exists())return;
                DB::table(self::CLAIMS)->where('key','catalog')->update(['run_id'=>$id]);
            }elseif($run->status==='completed')return;
            if(!$pipeline)abort_unless($claim?->run_id === $id, 409, 'Batch lain memiliki katalog.');
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
                if($this->row($id)->workflow===StockHubTiktokVariantBatchPipeline::WORKFLOW) {
                    try { app(StockHubTiktokVariantBatchPipeline::class)->step($item); }
                    catch(\DomainException $e) { $this->productBlocked($item); }
                    catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
                        if(!in_array($e->getStatusCode(),[404,409,422],true))throw $e;
                        $this->productBlocked($item);
                    }
                } else {
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

    private function productBlocked(object $item): void
    {
        DB::table(self::ITEMS)->where('id',$item->id)->update(['status'=>'blocked','processed'=>DB::raw('true'),
            'message'=>'Produk ini memerlukan pemeriksaan nama, template, hubungan, atau hasil lama. Periksa dan Lanjutkan setelah diperbaiki.','updated_at'=>now()]);
    }

    private function row(string $id): object
    {
        $row = DB::table(self::RUNS)->where('id', $id)->first(); abort_unless($row, 404, 'Batch katalog tidak ditemukan.'); return $row;
    }
}
