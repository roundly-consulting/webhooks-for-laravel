<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Tests\Fixtures\App\Models;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Webhooks\Concerns\HasWebhooks;

/**
 * A host model that owns endpoints.
 *
 * @property int $id
 * @property string $name
 */
final class Team extends Model
{
    use HasWebhooks;

    protected $guarded = [];

    protected $table = 'teams';
}
