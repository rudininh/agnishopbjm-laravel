<?php

namespace App\Services;

use App\Models\MarketplaceAccount;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class MarketplaceAccountRegistry
{
    /**
     * @return array<int, array{key:string, account_key:string, name:string, channel:string, enabled:bool, connect_action:string, uses_primary_app:bool, required_env:array<int, string>, credentials_configured:bool}>
     */
    public function publicAccounts(): array
    {
        $accounts = [];

        foreach ($this->accountKeys() as $key) {
            $account = $this->account($key);
            $usesPrimaryApp = (bool) ($account['use_primary_app'] ?? false);
            $requiredEnv = $account['required_env'] ?? [];
            if ($key === 'shopee-gitacollectionbjm' && $usesPrimaryApp) {
                $requiredEnv = config('marketplace_accounts.accounts.shopee-agnishopbjm.required_env', []);
            }

            $accounts[] = [
                'key' => (string) $key,
                'account_key' => (string) $key,
                'name' => (string) ($account['name'] ?? ''),
                'channel' => (string) ($account['channel'] ?? ''),
                'enabled' => (bool) ($account['enabled'] ?? false),
                'connect_action' => (string) ($account['connect_action'] ?? ''),
                'uses_primary_app' => $usesPrimaryApp,
                'required_env' => array_values($requiredEnv),
                'credentials_configured' => $this->credentialsConfigured($account),
            ];
        }

        return $accounts;
    }

    /**
     * @return array<string, mixed>
     */
    public function account(string $accountKey): array
    {
        $configured = config('marketplace_accounts.accounts.'.$accountKey);
        $stored = $this->storedAccount($accountKey);

        if (! is_array($configured) && $stored === null) {
            throw new InvalidArgumentException('Akun marketplace tidak dikenal.');
        }

        if ($stored === null) {
            return $configured;
        }

        $account = is_array($configured) ? $configured : [
            'name' => $stored->name,
            'channel' => $stored->channel,
            'enabled' => $stored->enabled,
            'connect_action' => 'auth-'.$stored->account_key,
            'use_primary_app' => false,
            'required_env' => [],
            'credentials' => [],
        ];
        $configuredCredentials = is_array($account['credentials'] ?? null) ? $account['credentials'] : [];
        $storedCredentials = is_array($stored->credentials) ? $stored->credentials : [];

        $account['name'] = $stored->name;
        $account['channel'] = $stored->channel;
        $account['enabled'] = (bool) $stored->enabled;
        $account['credentials'] = array_replace($configuredCredentials, $storedCredentials);
        $account['settings'] = array_replace(
            is_array($account['settings'] ?? null) ? $account['settings'] : [],
            is_array($stored->settings) ? $stored->settings : [],
        );

        return $account;
    }

    /**
     * @return array{partner_id:int, partner_key:string, host:string, redirect_url:string}
     */
    public function shopeeContext(string $accountKey): array
    {
        $account = $this->requireChannel($accountKey, 'shopee');
        $credentials = $this->shopeeCredentials($accountKey, $account);
        $context = [
            'partner_id' => (int) ($credentials['partner_id'] ?? 0),
            'partner_key' => trim((string) ($credentials['partner_key'] ?? '')),
            'host' => rtrim(trim((string) ($credentials['host'] ?? '')), '/'),
            'redirect_url' => trim((string) ($credentials['redirect_url'] ?? '')),
        ];

        if ($context['partner_id'] <= 0 || $context['partner_key'] === '' || $context['host'] === '' || $context['redirect_url'] === '') {
            throw new RuntimeException('Konfigurasi '.$this->accountName($account).' belum lengkap.');
        }

        return $context;
    }

    /**
     * @return array{app_key:string, app_secret:string, auth_host:string, api_host:string, redirect_url:string, warehouse_id:string}
     */
    public function tiktokContext(string $accountKey, bool $requireWarehouse = true): array
    {
        $account = $this->requireChannel($accountKey, 'tiktok');
        $credentials = $account['credentials'] ?? [];
        $context = [
            'app_key' => trim((string) ($credentials['app_key'] ?? '')),
            'app_secret' => trim((string) ($credentials['app_secret'] ?? '')),
            'auth_host' => rtrim(trim((string) ($credentials['auth_host'] ?? '')), '/'),
            'api_host' => rtrim(trim((string) ($credentials['api_host'] ?? '')), '/'),
            'redirect_url' => trim((string) ($credentials['redirect_url'] ?? '')),
            'warehouse_id' => trim((string) ($credentials['warehouse_id'] ?? '')),
        ];

        $required = $requireWarehouse ? $context : array_diff_key($context, ['warehouse_id' => true]);
        if (in_array('', $required, true)) {
            throw new RuntimeException('Konfigurasi '.$this->accountName($account).' belum lengkap.');
        }

        return $context;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireChannel(string $accountKey, string $channel): array
    {
        $account = $this->account($accountKey);

        if (($account['channel'] ?? null) !== $channel) {
            throw new InvalidArgumentException('Kanal akun marketplace tidak sesuai.');
        }

        return $account;
    }

    /**
     * @param array<string, mixed> $account
     * @return array<string, mixed>
     */
    private function shopeeCredentials(string $accountKey, array $account): array
    {
        if ($accountKey === 'shopee-agnishopbjm') {
            return $this->primaryShopeeCredentials($account);
        }

        if ($accountKey === 'shopee-gitacollectionbjm' && (bool) ($account['use_primary_app'] ?? false)) {
            return $this->primaryShopeeCredentials($this->requireChannel('shopee-agnishopbjm', 'shopee'));
        }

        return $account['credentials'] ?? [];
    }

    /**
     * @param array<string, mixed> $account
     * @return array<string, mixed>
     */
    private function primaryShopeeCredentials(array $account): array
    {
        $credentials = $account['credentials'] ?? [];

        try {
            $override = DB::table('shopee_config')
                ->whereRaw('COALESCE(is_active, true) = true')
                ->orderByDesc('id')
                ->first();
        } catch (Throwable) {
            $override = null;
        }

        if ($override === null) {
            return $credentials;
        }

        $partnerId = (int) ($override->partner_id ?? 0);
        $partnerKey = trim((string) ($override->partner_key ?? ''));
        $host = rtrim(trim((string) ($override->host ?? '')), '/');
        $redirectUrl = trim((string) ($override->redirect_url ?? ''));

        return [
            'partner_id' => $partnerId > 0 ? $partnerId : ($credentials['partner_id'] ?? 0),
            'partner_key' => $partnerKey !== '' ? $partnerKey : ($credentials['partner_key'] ?? ''),
            'host' => $host !== '' ? $host : ($credentials['host'] ?? ''),
            'redirect_url' => $redirectUrl !== '' ? $redirectUrl : ($credentials['redirect_url'] ?? ''),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function accountKeys(): array
    {
        $keys = array_keys(config('marketplace_accounts.accounts', []));
        foreach ($this->storedAccounts() as $account) {
            $keys[] = (string) $account->account_key;
        }

        return array_values(array_unique($keys));
    }

    private function storedAccount(string $accountKey): ?MarketplaceAccount
    {
        foreach ($this->storedAccounts() as $account) {
            if ((string) $account->account_key === $accountKey) {
                return $account;
            }
        }

        return null;
    }

    /**
     * @return array<int, MarketplaceAccount>
     */
    private function storedAccounts(): array
    {
        try {
            return MarketplaceAccount::query()->orderBy('id')->get()->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $account
     */
    private function credentialsConfigured(array $account): bool
    {
        $credentials = is_array($account['credentials'] ?? null) ? $account['credentials'] : [];
        if (($account['channel'] ?? '') === 'shopee') {
            return (int) ($credentials['partner_id'] ?? 0) > 0
                && trim((string) ($credentials['partner_key'] ?? '')) !== '';
        }

        if (($account['channel'] ?? '') === 'tiktok') {
            return trim((string) ($credentials['app_key'] ?? '')) !== ''
                && trim((string) ($credentials['app_secret'] ?? '')) !== '';
        }

        return false;
    }

    /**
     * @param array<string, mixed> $account
     */
    private function accountName(array $account): string
    {
        return (string) ($account['name'] ?? 'marketplace');
    }
}
