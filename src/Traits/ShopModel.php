<?php

namespace Osiset\ShopifyApp\Traits;

use Gnikyt\BasicShopifyAPI\BasicShopifyAPI;
use Gnikyt\BasicShopifyAPI\Session;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Osiset\ShopifyApp\Actions\MigrateShopToExpiringOfflineAccessToken;
use Osiset\ShopifyApp\Contracts\ApiHelper as IApiHelper;
use Osiset\ShopifyApp\Contracts\Objects\Values\AccessToken as AccessTokenValue;
use Osiset\ShopifyApp\Contracts\Objects\Values\ShopDomain as ShopDomainValue;
use Osiset\ShopifyApp\Contracts\Objects\Values\ShopId as ShopIdValue;
use Osiset\ShopifyApp\Messaging\Events\ShopDeletedEvent;
use Osiset\ShopifyApp\Objects\Values\AccessToken;
use Osiset\ShopifyApp\Objects\Values\SessionContext;
use Osiset\ShopifyApp\Objects\Values\ShopDomain;
use Osiset\ShopifyApp\Objects\Values\ShopId;
use Osiset\ShopifyApp\Services\OfflineAccessTokenRefresher;
use Osiset\ShopifyApp\Storage\Models\Charge;
use Osiset\ShopifyApp\Storage\Models\Plan;
use Osiset\ShopifyApp\Storage\Scopes\Namespacing;
use Osiset\ShopifyApp\Util;

/**
 * Responsible for representing a shop record.
 */
trait ShopModel
{
    use SoftDeletes;

    /**
     * The API helper instance.
     *
     * @var IApiHelper
     */
    public $apiHelper;

    /**
     * Session context used between requests.
     *
     * @var SessionContext
     */
    protected $sessionContext;

    /**
     * Boot the trait.
     *
     * Note that the method boot[TraitName] is automatically booted by Laravel.
     *
     * @return void
     */
    protected static function bootShopModel(): void
    {
        static::addGlobalScope(new Namespacing());

        static::deleted(function ($shop) {
            event(new ShopDeletedEvent($shop));
        });
    }

    /**
     * Merge casts for expiring offline token timestamps.
     *
     * @return void
     */
    public function initializeShopModel(): void
    {
        $this->mergeCasts([
            'shopify_offline_access_token_expires_at' => 'datetime',
            'shopify_offline_refresh_token_expires_at' => 'datetime',
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function getId(): ShopIdValue
    {
        return ShopId::fromNative($this->id);
    }

    /**
     * {@inheritdoc}
     */
    public function getDomain(): ShopDomainValue
    {
        return ShopDomain::fromNative($this->name);
    }

    /**
     * {@inheritdoc}
     */
    public function getAccessToken(): AccessTokenValue
    {
        return AccessToken::fromNative($this->password);
    }

    /**
     * {@inheritdoc}
     */
    public function charges(): HasMany
    {
        return $this->hasMany(
            Util::getShopifyConfig('models.charge', Charge::class),
            Util::getShopsTableForeignKey(),
            'id'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function hasCharges(): bool
    {
        return $this->charges->isNotEmpty();
    }

    /**
     * {@inheritdoc}
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Util::getShopifyConfig('models.plan', Plan::class));
    }

    /**
     * {@inheritdoc}
     */
    public function isGrandfathered(): bool
    {
        return (bool) $this->shopify_grandfathered === true;
    }

    /**
     * {@inheritdoc}
     */
    public function isFreemium(): bool
    {
        return (bool) $this->shopify_freemium === true;
    }

    /**
     * {@inheritdoc}
     */
    public function hasOfflineAccess(): bool
    {
        return ! $this->getAccessToken()->isNull() && ! empty($this->password);
    }

    /**
     * {@inheritdoc}
     */
    public function hasExpiringOfflineAccess(): bool
    {
        return ! empty($this->shopify_offline_refresh_token);
    }

    /**
     * {@inheritdoc}
     */
    public function hasCorruptExpiringTokenState(): bool
    {
        if (! Util::getShopifyConfig('expiring_offline_tokens', $this)) {
            return false;
        }

        return ! empty($this->shopify_offline_access_token_expires_at)
            && empty($this->shopify_offline_refresh_token)
            && Carbon::now()->greaterThan($this->shopify_offline_access_token_expires_at);
    }

    /**
     * {@inheritDoc}
     */
    public function setSessionContext(SessionContext $session): void
    {
        $this->sessionContext = $session;
    }

    /**
     * {@inheritDoc}
     */
    public function getSessionContext(): ?SessionContext
    {
        return $this->sessionContext;
    }

    /**
     * {@inheritdoc}
     */
    public function offlineAccessTokenIsFresh(): bool
    {
        return ! app(OfflineAccessTokenRefresher::class)->offlineAccessTokenNeedsRefresh($this);
    }

    /**
     * {@inheritdoc}
     */
    public function refreshOfflineAccessTokenIfNeeded(): void
    {
        app(OfflineAccessTokenRefresher::class)->refreshIfNeeded($this);
        $this->resetApiClient();
    }

    /**
     * {@inheritdoc}
     */
    public function resetApiClient(): void
    {
        $this->apiHelper = null;
    }

    /**
     * {@inheritdoc}
     */
    public function apiHelper(): IApiHelper
    {
        $this->ensureApiClientIsReady();

        return $this->apiHelper;
    }

    /**
     * {@inheritdoc}
     */
    public function api(): BasicShopifyAPI
    {
        $this->ensureApiClientIsReady();

        return $this->apiHelper->getApi();
    }

    /**
     * Re-check token expiry and rebuild the cached client when configured or not yet built.
     *
     * @return void
     */
    protected function ensureApiClientIsReady(): void
    {
        if (Util::getShopifyConfig('refresh_offline_token_before_api_call', $this)
            && $this->apiHelper !== null
            && app(OfflineAccessTokenRefresher::class)->offlineAccessTokenNeedsRefresh($this)
        ) {
            $this->resetApiClient();
        }

        if ($this->apiHelper === null) {
            $this->buildApiHelper();
        }
    }

    /**
     * Build the API helper with lazy migration, token refresh, and a fresh session.
     *
     * @return void
     */
    protected function buildApiHelper(): void
    {
        if (Util::getShopifyConfig('auto_migrate_legacy', $this)
            && Util::getShopifyConfig('expiring_offline_tokens', $this)
            && ! $this->hasExpiringOfflineAccess()
            && $this->hasOfflineAccess()
        ) {
            try {
                app(MigrateShopToExpiringOfflineAccessToken::class)($this);
                $this->refresh();
            } catch (\Throwable $e) {
                Log::warning('On-the-fly Shopify offline token migration failed.', [
                    'shop' => $this->getDomain()->toNative(),
                    'message' => $e->getMessage(),
                ]);
            }
        }

        app(OfflineAccessTokenRefresher::class)->refreshIfNeeded($this);

        $session = new Session(
            $this->getDomain()->toNative(),
            $this->getAccessToken()->toNative()
        );
        $this->apiHelper = resolve(IApiHelper::class)->make($session);
    }
}
