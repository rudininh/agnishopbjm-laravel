<?php

namespace Tests\Feature;

use App\Services\OrphanVariantClassifier;
use App\Services\OrphanVariantCleanupService;
use App\Services\OrphanVariantGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrphanVariantCleanupServiceTest extends TestCase
{
    use RefreshDatabase;

    private function setupService(): array
    {
        $gateway = new CleanupFakeGateway;
        return [new OrphanVariantCleanupService($gateway, new OrphanVariantClassifier), $gateway];
    }

    private function preview(OrphanVariantCleanupService $service, string $account = 'tiktok-agnishopbjm'): array
    {
        $run = $service->start($account);
        do { $run = $service->scan($run['run_id'], $account); } while ($run['status'] === 'scanning');
        return $run;
    }

    private function submit(OrphanVariantCleanupService $service, array $run): array
    {
        return $service->submit($run['run_id'], $run['account_key'], $run['revision'],
            array_column(array_filter($run['items'], fn ($i) => $i['status'] === 'eligible'), 'item_id'));
    }

    public function test_bulk_deletion_is_verified_and_not_replayed(): void
    {
        [$s, $g] = $this->setupService();
        $run = $this->preview($s);
        $this->submit($s, $run);
        $result = $s->step($run['run_id'], $run['account_key']);
        $this->assertSame('completed', $result['status']);
        $this->assertSame(['retained', 'deleted', 'deleted'], array_column($result['items'], 'status'));
        $s->step($run['run_id'], $run['account_key']);
        $this->assertSame(1, $g->writes);
        $this->assertSame(['21'], array_column($g->snapshot($run['account_key'], '20')['target_variants'], 'id'));
        $this->assertSame(1, $g->reconciles);
    }

    public function test_single_selection_does_not_delete_other_eligible_variant(): void
    {
        [$s, $g] = $this->setupService();
        $run = $this->preview($s);
        $s->submit($run['run_id'], $run['account_key'], $run['revision'], [$run['items'][1]['item_id']]);
        $s->step($run['run_id'], $run['account_key']);
        $this->assertSame(['21', '23'], array_column($g->snapshot($run['account_key'], '20')['target_variants'], 'id'));
    }

    public function test_stale_live_source_blocks_mutation(): void
    {
        [$s, $g] = $this->setupService();
        $run = $this->preview($s);
        $this->submit($s, $run);
        $g->source[] = ['id' => '12', 'name' => 'Blue', 'seller_sku' => 'INT-10-BLUE'];
        $result = $s->step($run['run_id'], $run['account_key']);
        $this->assertSame('stale', $result['items'][1]['status']);
        $this->assertSame(0, $g->writes);
    }

    public function test_unverified_delete_blocks_later_runs_and_never_reconciles(): void
    {
        [$s, $g] = $this->setupService();
        $g->apply = false;
        $run = $this->preview($s);
        $this->submit($s, $run);
        $result = $s->step($run['run_id'], $run['account_key']);
        $this->assertSame('unverified', $result['items'][1]['status']);
        $next = $this->preview($s);
        $this->submit($s, $next);
        $result = $s->step($next['run_id'], $next['account_key']);
        $this->assertSame('blocked', $result['items'][1]['status']);
        $this->assertSame(1, $g->writes);
        $this->assertSame(0, $g->reconciles);
    }

    public function test_unknown_selection_and_wrong_account_are_rejected(): void
    {
        [$s, $g] = $this->setupService();
        $run = $this->preview($s);
        try { $s->show($run['run_id'], 'shopee-gitacollectionbjm'); $this->fail(); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(404, $e->getStatusCode()); }
        try { $s->submit($run['run_id'], $run['account_key'], $run['revision'], ['unknown']); $this->fail(); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
        $this->assertSame(0, $g->writes);
    }

    public function test_gita_deletes_and_verifies_one_variant_at_a_time(): void
    {
        [$s, $g] = $this->setupService();
        $run = $this->preview($s, 'shopee-gitacollectionbjm');
        $this->submit($s, $run);
        $result = $s->step($run['run_id'], $run['account_key']);
        $this->assertSame('running', $result['status']);
        $this->assertSame(1, $g->writes);
        $result = $s->step($run['run_id'], $run['account_key']);
        $this->assertSame('completed', $result['status']);
        $this->assertSame(2, $g->writes);
        $this->assertSame(2, $g->reconciles);
    }

    public function test_source_read_failure_after_first_gita_delete_stops_remaining_variants(): void
    {
        [$s, $g] = $this->setupService();
        $g->loseSource = true;
        $run = $this->preview($s, 'shopee-gitacollectionbjm');
        $this->submit($s, $run);
        $s->step($run['run_id'], $run['account_key']);
        $result = $s->step($run['run_id'], $run['account_key']);
        $this->assertSame(1, $g->writes);
        $this->assertNotSame('deleted', $result['items'][2]['status']);
    }

    public function test_interrupted_attempt_never_replays_even_with_new_preview(): void
    {
        [$s, $g] = $this->setupService();
        $run = $this->preview($s);
        $this->submit($s, $run);
        $row = DB::table('orphan_variant_cleanup_runs')->where('id', $run['run_id'])->first();
        $state = json_decode($row->state, true);
        $state['groups']['20']['status'] = 'attempted';
        DB::table('orphan_variant_cleanup_runs')->where('id', $row->id)->update(['state' => json_encode($state)]);
        DB::table('orphan_variant_cleanup_guards')->insert(['product_key' => 'tiktok-agnishopbjm:20', 'run_id' => $row->id, 'status' => 'attempted']);
        $result = $s->step($run['run_id'], $run['account_key']);
        $this->assertSame('unverified', $result['items'][1]['status']);
        $this->assertSame(0, $g->writes);
    }

    public function test_expired_preview_is_rejected_before_mutation(): void
    {
        [$s, $g] = $this->setupService();
        $run = $this->preview($s);
        $this->travel(61)->minutes();
        try { $this->submit($s, $run); $this->fail(); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(409, $e->getStatusCode()); }
        $this->assertSame(0, $g->writes);
    }

    public function test_verified_results_left_in_attempted_group_recover_without_infinite_polling(): void
    {
        [$s, $g] = $this->setupService();
        $run = $this->preview($s);
        $this->submit($s, $run);
        $row = DB::table('orphan_variant_cleanup_runs')->where('id', $run['run_id'])->first();
        $state = json_decode($row->state, true);
        $state['groups']['20']['status'] = 'attempted';
        foreach ($state['groups']['20']['items'] as &$item) if ($item['status'] === 'eligible') $item['status'] = 'deleted';
        unset($item);
        DB::table('orphan_variant_cleanup_runs')->where('id', $row->id)->update(['state' => json_encode($state)]);
        $this->assertSame('completed', $s->step($run['run_id'], $run['account_key'])['status']);
        $this->assertSame(0, $g->writes);
    }
}

class CleanupFakeGateway extends OrphanVariantGateway
{
    public int $writes = 0;
    public int $reconciles = 0;
    public bool $apply = true;
    public bool $loseSource = false;
    public array $source = [['id' => '11', 'name' => 'Green', 'seller_sku' => 'INT-10-GREEN']];
    public array $variants = [
        ['id' => '21', 'name' => 'Green', 'seller_sku' => 'INT-10-GREEN'],
        ['id' => '22', 'name' => 'Blue', 'seller_sku' => 'INT-10-BLUE'],
        ['id' => '23', 'name' => 'Red', 'seller_sku' => 'INT-10-RED'],
    ];
    public function __construct() {}
    public function catalogPage(string $accountKey, ?string $cursor): array { return ['products' => ['20'], 'next_cursor' => null, 'complete' => true]; }
    public function snapshot(string $accountKey, string $productId): array
    {
        return ['account_key' => $accountKey, 'source_account_key' => 'shopee-agnishopbjm', 'source_product_id' => '10',
            'target_product_id' => $productId, 'source_complete' => true, 'target_complete' => true,
            'source_variants' => $this->source, 'target_variants' => $this->variants, 'mapping_conflicts' => false, 'target_detail' => []];
    }
    public function delete(string $accountKey, array $snapshot, array $targetIds): array
    {
        $this->writes++;
        if ($this->apply) $this->variants = array_values(array_filter($this->variants, fn ($v) => ! in_array($v['id'], $targetIds, true)));
        if ($this->loseSource) $this->source = [];
        return ['accepted' => true];
    }
    public function reconcileVerified(string $accountKey, array $before, array $after, array $targetIds): void { $this->reconciles++; }
}
