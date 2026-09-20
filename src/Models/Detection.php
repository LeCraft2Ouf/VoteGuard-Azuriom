<?php

namespace Azuriom\Plugin\VoteGuard\Models;

use Azuriom\Models\Traits\HasTablePrefix;
use Azuriom\Models\User;
use Illuminate\Database\Eloquent\Model;

class Detection extends Model
{
    use HasTablePrefix;

    protected $prefix = 'voteguard_';

    protected $fillable = [
        'user_id',
        'vote_id',
        'site_id',
        'ip',
        'user_agent',
        'score',
        'flags',
        'source',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'flags' => 'array',
        'score' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
