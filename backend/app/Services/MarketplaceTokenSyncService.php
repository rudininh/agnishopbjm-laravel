<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class MarketplaceTokenSyncService
{
    private const ALLOWED_ACCOUNT_KEYS = ['shopee-agnishopbjm', 'shopee-gitacollectionbjm', 'tiktok-agnishopbjm'];

    private const SHOPEE_AGNI_CONFIGURATION_FIELD = 'shopee_agnishopbjm_configuration';
    public function pullFromStb(): array
    {
        $summary = $this->summary();
        $lock = Cache::lock('stb-marketplace-token-sync', 120);

        if (! $lock->get()) {
            return $this->saveStatus($summary, 'skipped', 'Sinkron token STB sedang berjalan.');
        }

        try {
            if (! (bool) config('stb.token_sync_enabled', false)) {
                return $this->saveStatus($summary, 'error', 'Sinkron token STB belum diaktifkan.');
            }

            $url = trim((string) config('stb.token_sync_url', ''));
            $token = trim((string) config('stb.token_sync_token', ''));
            if ($url === '' || $token === '') {
                return $this->saveStatus($summary, 'error', 'URL atau token sinkron STB belum dikonfigurasi.');
            }

            try {
                $response = Http::acceptJson()
                    ->withToken($token)
                    ->timeout((int) config('stb.token_sync_timeout_seconds', 15))
                    ->get($url);
            } catch (\Throwable) {
                return $this->saveStatus($summary, 'error', 'STB tidak dapat dihubungi.');
            }

            $payload = $response->json();
            if ($response->status() === 404) {
                return $this->saveStatus($summary, 'error', 'Endpoint token STB belum tersedia. Perbarui aplikasi di STB.');
            }

            if (! $response->successful() || ! is_array($payload) || ($payload['source'] ?? null) !== 'stb') {
                return $this->saveStatus($summary, 'error', 'Respons token dari STB tidak valid.');
            }

            $summary['shopee'] = $this->importShopeeTokens((array) ($payload['shopee'] ?? []));
            $summary['tiktok'] = $this->importTiktokTokens((array) ($payload['tiktok'] ?? []));
            $status = $summary['shopee']['updated'] > 0 || $summary['tiktok']['updated'] > 0 ? 'success' : 'unchanged';

            return $this->saveStatus($summary, $status, $status === 'success'
                ? 'Token marketplace dari STB diperbarui.'
                : 'Token marketplace PC sudah paling baru.');
        } finally {
            $lock->release();
        }
    }

    public function pushToStb(): array
    {
        $summary = $this->summary('pc');

        if (! (bool) config('stb.token_sync_enabled', false)) {
            return $this->finalizeSummary($summary, 'error', 'Sinkron token STB belum diaktifkan.');
        }

        $url = trim((string) config('stb.token_sync_push_url', ''));
        $token = trim((string) config('stb.token_sync_token', ''));
        if ($url === '' || $token === '') {
            return $this->finalizeSummary($summary, 'error', 'URL push atau token sinkron STB belum dikonfigurasi.');
        }

        try {
            $response = Http::acceptJson()
                ->withToken($token)
                ->timeout((int) config('stb.token_sync_timeout_seconds', 15))
                ->post($url, $this->exportForStb());
        } catch (\Throwable) {
            return $this->finalizeSummary($summary, 'error', 'STB tidak dapat dihubungi.');
        }

        $payload = $response->json();
        if ($response->status() === 404) {
            return $this->finalizeSummary($summary, 'error', 'Endpoint import token STB belum tersedia. Perbarui aplikasi di STB.');
        }

        if (! $response->successful() || ! is_array($payload) || ($payload['source'] ?? null) !== 'pc') {
            return $this->finalizeSummary($summary, 'error', 'Respons import token dari STB tidak valid.');
        }

        $summary['shopee'] = $this->sanitizeChannelSummary((array) ($payload['shopee'] ?? []));
        $summary['tiktok'] = $this->sanitizeChannelSummary((array) ($payload['tiktok'] ?? []));
        $summary['shopee_configuration'] = $this->sanitizeConfigurationSummary((array) ($payload['shopee_configuration'] ?? []));
        $status = (string) ($payload['status'] ?? 'success');
        if (! in_array($status, ['success', 'unchanged', 'skipped'], true)) {
            $status = 'success';
        }

        return $this->finalizeSummary($summary, $status, (string) ($payload['message'] ?? 'Token marketplace PC berhasil dikirim ke STB.'));
    }

    public function importFromPc(array $payload): array
    {
        $summary = $this->summary('pc');

        if (($payload['source'] ?? null) !== 'pc') {
            return $this->saveStatus($summary, 'error', 'Payload token PC tidak valid.');
        }

        $summary['shopee'] = $this->importShopeeTokens((array) ($payload['shopee'] ?? []));
        $summary['tiktok'] = $this->importTiktokTokens((array) ($payload['tiktok'] ?? []));
        $summary['shopee_configuration'] = $this->importShopeeAgniConfiguration((array) ($payload[self::SHOPEE_AGNI_CONFIGURATION_FIELD] ?? []));
        $status = $summary['shopee']['updated'] > 0 || $summary['tiktok']['updated'] > 0 || $summary['shopee_configuration']['status'] === 'updated'
            ? 'success'
            : 'unchanged';

        return $this->saveStatus($summary, $status, $status === 'success'
            ? 'Token marketplace dari PC berhasil disinkronisasi ke STB.'
            : 'Token marketplace STB sudah paling baru.');
    }

    public function exportForStb(): array
    {
        $payload = [
            'source' => 'pc',
            'generated_at' => now()->toISOString(),
            'shopee' => $this->activeTokens('shopee_tokens', [
                'account_key',
                'account_name',
                'shop_id',
                'access_token',
                'refresh_token',
                'access_token_expire_at',
                'refresh_token_expire_at',
                'expire_at',
                'request_id',
                'updated_at',
            ], ['account_key', 'shop_id', 'updated_at']),
            'tiktok' => $this->activeTokens('tiktok_tokens', [
                'account_key',
                'account_name',
                'shop_id',
                'open_id',
                'access_token',
                'refresh_token',
                'access_token_expire_at',
                'refresh_token_expire_at',
                'expire_at',
                'request_id',
                'updated_at',
            ], ['account_key', 'shop_id', 'updated_at']),
        ];

        try {
            $context = app(MarketplaceAccountRegistry::class)->shopeeContext('shopee-agnishopbjm');
            $payload[self::SHOPEE_AGNI_CONFIGURATION_FIELD] = [
                'partner_id' => $context['partner_id'],
                'partner_key' => $context['partner_key'],
                'host' => $context['host'],
                'redirect_url' => $context['redirect_url'],
            ];
        } catch (\Throwable) {
            $payload[self::SHOPEE_AGNI_CONFIGURATION_FIELD] = [];
        }

        return $payload;
    }

    public function status(): array
    {
        if (! Schema::hasTable('marketplace_token_sync_statuses')) {
            return $this->summary();
        }

        $status = DB::table('marketplace_token_sync_statuses')->where('source', 'stb')->first();
        if (! $status) {
            return $this->summary();
        }

        return [
            'status' => $status->status,
            'source' => 'stb',
            'shopee' => ['updated' => (int) $status->shopee_updated, 'unchanged' => 0, 'skipped_stale' => 0],
            'tiktok' => ['updated' => (int) $status->tiktok_updated, 'unchanged' => 0, 'skipped_stale' => 0],
            'last_succeeded_at' => $status->last_succeeded_at,
            'message' => $status->message,
        ];
    }

    public function exportForPc(): array
    {
        return [
            'source' => 'stb',
            'generated_at' => now()->toISOString(),
            'shopee' => $this->activeTokens('shopee_tokens', [
                'account_key',
                'account_name',
                'shop_id',
                'access_token',
                'refresh_token',
                'access_token_expire_at',
                'refresh_token_expire_at',
                'expire_at',
                'request_id',
                'updated_at',
            ], ['account_key', 'shop_id', 'updated_at']),
            'tiktok' => $this->activeTokens('tiktok_tokens', [
                'account_key',
                'account_name',
                'shop_id',
                'open_id',
                'access_token',
                'refresh_token',
                'access_token_expire_at',
                'refresh_token_expire_at',
                'expire_at',
                'request_id',
                'updated_at',
            ], ['account_key', 'shop_id', 'updated_at']),
        ];
    }

    private function activeTokens(string $table, array $columns, array $orderBy): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        $availableColumns = Schema::getColumnListing($table);
        if (! in_array('access_token', $availableColumns, true) || ! in_array('refresh_token', $availableColumns, true)) {
            return [];
        }

        $selectedColumns = array_values(array_intersect($columns, $availableColumns));
        $query = DB::table($table)->select($selectedColumns);

        if (in_array('is_active', $availableColumns, true)) {
            $query->whereRaw('is_active = true');
        }

        foreach ($orderBy as $column) {
            if (in_array($column, $availableColumns, true)) {
                $query->orderBy($column);
            }
        }

        return $query->get()
            ->filter(fn (object $token): bool => $this->isAllowedAccountKey((string) ($token->account_key ?? '')))
            ->map(static fn (object $token): array => (array) $token)
            ->values()
            ->all();
    }

    private function importShopeeTokens(array $tokens): array
    {
        $summary = $this->emptyChannelSummary();

        foreach ($tokens as $token) {
            if (! is_array($token) || ! $this->hasRequiredTokenFields($token)) {
                $summary['unchanged'] += 1;
                continue;
            }

            $accountKey = trim((string) ($token['account_key'] ?? ''));
            $shopId = (int) ($token['shop_id'] ?? 0);
            if ($accountKey === '' || $shopId <= 0 || ! $this->isAllowedAccountKey($accountKey)) {
                $summary['unchanged'] += 1;
                continue;
            }

            $current = DB::table('shopee_tokens')
                ->where('account_key', $accountKey)
                ->where('shop_id', $shopId)
                ->whereRaw('is_active = true')
                ->orderByDesc('updated_at')
                ->first();

            if ($current && ! $this->incomingTokenIsNewer($token, $current)) {
                $summary['skipped_stale'] += 1;
                continue;
            }

            DB::transaction(function () use ($current, $accountKey, $shopId, $token): void {
                if ($current) {
                    DB::table('shopee_tokens')->where('id', $current->id)->update([
                        'is_active' => DB::raw('false'),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('shopee_tokens')->insert([
                    'account_key' => $accountKey,
                    'account_name' => trim((string) ($token['account_name'] ?? $accountKey)),
                    'shop_id' => $shopId,
                    'access_token' => $token['access_token'],
                    'refresh_token' => $token['refresh_token'],
                    'access_token_expire_at' => $this->tokenDate($token['access_token_expire_at'] ?? null),
                    'refresh_token_expire_at' => $this->tokenDate($token['refresh_token_expire_at'] ?? null),
                    'expire_at' => $this->tokenDate($token['expire_at'] ?? null),
                    'request_id' => $token['request_id'] ?? null,
                    'is_active' => DB::raw('true'),
                    'created_at' => now(),
                    'updated_at' => $this->tokenDate($token['updated_at'] ?? null) ?: now(),
                ]);
            });

            $summary['updated'] += 1;
        }

        return $summary;
    }

    private function incomingTokenIsNewer(array $incoming, object $current): bool
    {
        $incomingUpdatedAt = $this->tokenDate($incoming['updated_at'] ?? null);
        $currentUpdatedAt = $this->tokenDate($current->updated_at ?? null);
        if ($incomingUpdatedAt && (! $currentUpdatedAt || $incomingUpdatedAt->greaterThan($currentUpdatedAt))) {
            return true;
        }

        $incomingExpiry = $this->tokenDate($incoming['access_token_expire_at'] ?? null);
        $currentExpiry = $this->tokenDate($current->access_token_expire_at ?? null);

        return $incomingExpiry && (! $currentExpiry || $incomingExpiry->greaterThan($currentExpiry));
    }

    private function importTiktokTokens(array $tokens): array
    {
        $summary = $this->emptyChannelSummary();
        if (! Schema::hasTable('tiktok_tokens')) {
            return $summary;
        }

        foreach ($tokens as $token) {
            if (! is_array($token) || ! $this->hasRequiredTokenFields($token)) {
                $summary['unchanged'] += 1;
                continue;
            }

            $accountKey = trim((string) ($token['account_key'] ?? ''));
            $openId = trim((string) ($token['open_id'] ?? ''));
            $shopId = trim((string) ($token['shop_id'] ?? ''));
            if ($accountKey === '' || ($openId === '' && $shopId === '') || ! $this->isAllowedAccountKey($accountKey)) {
                $summary['unchanged'] += 1;
                continue;
            }

            $current = DB::table('tiktok_tokens')
                ->where('account_key', $accountKey)
                ->when($openId !== '', fn ($query) => $query->where('open_id', $openId), fn ($query) => $query->where('shop_id', $shopId))
                ->whereRaw('is_active = true')
                ->orderByDesc('updated_at')
                ->first();
            if ($current && ! $this->incomingTokenIsNewer($token, $current)) {
                $summary['skipped_stale'] += 1;
                continue;
            }

            DB::transaction(function () use ($current, $accountKey, $openId, $shopId, $token): void {
                if ($current) {
                    DB::table('tiktok_tokens')->where('id', $current->id)->update(['is_active' => DB::raw('false'), 'updated_at' => now()]);
                }

                DB::table('tiktok_tokens')->insert([
                    'account_key' => $accountKey,
                    'account_name' => trim((string) ($token['account_name'] ?? $accountKey)),
                    'shop_id' => $shopId !== '' ? $shopId : null,
                    'open_id' => $openId !== '' ? $openId : null,
                    'access_token' => $token['access_token'],
                    'refresh_token' => $token['refresh_token'],
                    'access_token_expire_at' => $this->tokenDate($token['access_token_expire_at'] ?? null),
                    'refresh_token_expire_at' => $this->tokenDate($token['refresh_token_expire_at'] ?? null),
                    'expire_at' => $this->tokenDate($token['expire_at'] ?? null),
                    'request_id' => $token['request_id'] ?? null,
                    'is_active' => DB::raw('true'),
                    'created_at' => now(),
                    'updated_at' => $this->tokenDate($token['updated_at'] ?? null) ?: now(),
                ]);
            });
            $summary['updated'] += 1;
        }

        return $summary;
    }

    private function importShopeeAgniConfiguration(array $configuration): array
    {
        $fields = ['partner_id', 'partner_key', 'host', 'redirect_url'];
        foreach ($fields as $field) {
            if (! is_scalar($configuration[$field] ?? null) || trim((string) $configuration[$field]) === '') {
                return ['status' => 'skipped'];
            }
        }

        $partnerId = (int) $configuration['partner_id'];
        $partnerKey = trim((string) $configuration['partner_key']);
        $host = rtrim(trim((string) $configuration['host']), '/');
        $redirectUrl = trim((string) $configuration['redirect_url']);
        if ($partnerId <= 0 || filter_var($host, FILTER_VALIDATE_URL) === false || filter_var($redirectUrl, FILTER_VALIDATE_URL) === false
            || ! Schema::hasTable('shopee_config') || ! Schema::hasColumns('shopee_config', ['partner_id', 'partner_key', 'host', 'redirect_url', 'is_active'])) {
            return ['status' => 'skipped'];
        }

        $current = DB::table('shopee_config')
            ->whereRaw('COALESCE(is_active, true) = true')
            ->orderByDesc('id')
            ->first();
        if ($current
            && (int) $current->partner_id === $partnerId
            && hash_equals((string) $current->partner_key, $partnerKey)
            && rtrim((string) $current->host, '/') === $host
            && (string) ($current->redirect_url ?? '') === $redirectUrl) {
            return ['status' => 'unchanged'];
        }

        DB::transaction(function () use ($partnerId, $partnerKey, $host, $redirectUrl): void {
            $now = now();
            DB::table('shopee_config')
                ->whereRaw('COALESCE(is_active, true) = true')
                ->update(['is_active' => DB::raw('false'), 'updated_at' => $now]);

            $existing = DB::table('shopee_config')->where('partner_id', $partnerId)->orderByDesc('id')->first();
            $values = [
                'partner_key' => $partnerKey,
                'host' => $host,
                'redirect_url' => $redirectUrl,
                'is_active' => DB::raw('true'),
                'updated_at' => $now,
            ];
            if ($existing) {
                DB::table('shopee_config')->where('id', $existing->id)->update($values);

                return;
            }

            DB::table('shopee_config')->insert([
                'partner_id' => $partnerId,
                ...$values,
                'created_at' => $now,
            ]);
        });

        return ['status' => 'updated'];
    }

    private function isAllowedAccountKey(string $accountKey): bool
    {
        return in_array($accountKey, self::ALLOWED_ACCOUNT_KEYS, true);
    }

    private function sanitizeChannelSummary(array $summary): array
    {
        return [
            'updated' => max(0, (int) ($summary['updated'] ?? 0)),
            'unchanged' => max(0, (int) ($summary['unchanged'] ?? 0)),
            'skipped_stale' => max(0, (int) ($summary['skipped_stale'] ?? 0)),
        ];
    }

    private function sanitizeConfigurationSummary(array $summary): array
    {
        $status = (string) ($summary['status'] ?? 'skipped');

        return ['status' => in_array($status, ['updated', 'unchanged', 'skipped'], true) ? $status : 'skipped'];
    }

    private function hasRequiredTokenFields(array $token): bool
    {
        return trim((string) ($token['access_token'] ?? '')) !== ''
            && trim((string) ($token['refresh_token'] ?? '')) !== '';
    }

    private function tokenDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function summary(string $source = 'stb'): array
    {
        return [
            'status' => 'unchanged',
            'source' => $source,
            'shopee' => $this->emptyChannelSummary(),
            'tiktok' => $this->emptyChannelSummary(),
            'shopee_configuration' => ['status' => 'skipped'],
            'last_succeeded_at' => null,
            'message' => 'Belum ada sinkron token STB.',
        ];
    }

    private function emptyChannelSummary(): array
    {
        return ['updated' => 0, 'unchanged' => 0, 'skipped_stale' => 0];
    }

    private function finalizeSummary(array $summary, string $status, string $message): array
    {
        $success = in_array($status, ['success', 'unchanged'], true);
        $summary['status'] = $status;
        $summary['last_succeeded_at'] = $success ? now()->toISOString() : null;
        $summary['message'] = $message;

        return $summary;
    }

    private function saveStatus(array $summary, string $status, string $message): array
    {
        $success = in_array($status, ['success', 'unchanged'], true);
        $now = now();
        $lastSucceededAt = $success
            ? $now
            : DB::table('marketplace_token_sync_statuses')->where('source', $summary['source'] ?? 'stb')->value('last_succeeded_at');
        DB::table('marketplace_token_sync_statuses')->updateOrInsert(
            ['source' => $summary['source'] ?? 'stb'],
            [
                'status' => $status,
                'last_attempted_at' => $now,
                'last_succeeded_at' => $lastSucceededAt,
                'shopee_updated' => (int) ($summary['shopee']['updated'] ?? 0),
                'tiktok_updated' => (int) ($summary['tiktok']['updated'] ?? 0),
                'message' => $message,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $summary['status'] = $status;
        $summary['last_succeeded_at'] = $success ? $now->toISOString() : null;
        $summary['message'] = $message;

        return $summary;
    }
}
