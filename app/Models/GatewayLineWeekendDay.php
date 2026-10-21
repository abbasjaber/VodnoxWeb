<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A weekly holiday of a gateway line route (e.g. Friday and Saturday).
 *
 * A weekend day always wins over a business-hours window, so a holiday is
 * routed to the weekend destination even if a window happens to cover it.
 */
class GatewayLineWeekendDay extends Model
{
    protected $fillable = ['gateway_line_route_id', 'day'];

    protected $casts = ['day' => 'integer'];

    public function route(): BelongsTo
    {
        return $this->belongsTo(GatewayLineRoute::class, 'gateway_line_route_id');
    }
}
