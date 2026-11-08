<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Tests\Fixtures\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $delivery_id
 * @property string|null $type
 * @property bool $replay
 * @property int $run
 */
final class HandledWebhook extends Model
{
    protected $guarded = [];

    protected $table = 'handled_webhooks';

    protected $casts = ['replay' => 'boolean', 'run' => 'integer'];
}
