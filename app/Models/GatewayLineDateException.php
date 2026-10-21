<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A dated exception (public holiday, leave, cover) of a gateway line route.
 *
 * `ends_on` is INCLUSIVE: an exception 2026-01-01..2026-01-05 covers the whole
 * of 2026-01-05. The range is interpreted in the organization's timezone.
 */
class GatewayLineDateException extends Model
{
    protected $fillable = [
        'gateway_line_route_id', 'starts_on', 'ends_on', 'destination_extension_id', 'label',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
    ];

    public function route(): BelongsTo
    {
        return $this->belongsTo(GatewayLineRoute::class, 'gateway_line_route_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Extension::class, 'destination_extension_id');
    }
}
