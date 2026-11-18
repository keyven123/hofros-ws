<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingPortalConnection extends Model
{
    use HasFactory;
    use HasUuids;

    public $incrementing = false;
    public $primaryKey = 'uuid';
    protected $keyType = 'string';

    protected $fillable = [
        'user_uuid',
        'portal_key',
        'is_connected',
        'is_active',
        'listing_count',
        'last_synced_at',
        'has_sync_issue',
        'guest_portal_live',
        'guest_portal_design_completed',
        'guest_portal_headline',
        'guest_portal_message',
        'guest_portal_page_title',
        'guest_portal_theme_preset',
        'guest_portal_primary_color',
        'guest_portal_accent_color',
        'guest_portal_hero_image_url',
        'guest_portal_layout',
        'external_base_url',
        'external_merchant_email',
        'external_api_secret',
        'external_credentials',
        'external_access_token',
        'external_token_expires_at',
        'external_organization_uuid',
        'external_organization_name',
        'connection_meta',
    ];

    protected $hidden = [
        'external_api_secret',
        'external_credentials',
        'external_access_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_connected' => 'boolean',
            'is_active' => 'boolean',
            'has_sync_issue' => 'boolean',
            'guest_portal_live' => 'boolean',
            'guest_portal_design_completed' => 'boolean',
            'last_synced_at' => 'datetime',
            'guest_portal_layout' => 'array',
            'external_api_secret' => 'encrypted',
            'external_credentials' => 'encrypted:array',
            'external_access_token' => 'encrypted',
            'external_token_expires_at' => 'datetime',
            'connection_meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
