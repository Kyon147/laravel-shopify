<?php

namespace Osiset\ShopifyApp\Test\Stubs;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Osiset\ShopifyApp\Contracts\ShopModel as IShopModel;
use Osiset\ShopifyApp\Traits\ShopModel;

class User extends Authenticatable implements IShopModel
{
    use Notifiable;
    use ShopModel;

    protected $fillable = [
        'name',
        'email',
        'password',
        'shopify_offline_refresh_token',
        'shopify_offline_access_token_expires_at',
        'shopify_offline_refresh_token_expires_at',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];
}
