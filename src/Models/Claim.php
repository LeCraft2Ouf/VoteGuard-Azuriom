<?php

namespace Azuriom\Plugin\VoteGuard\Models;

use Azuriom\Models\Traits\HasTablePrefix;
use Azuriom\Models\User;
use Illuminate\Database\Eloquent\Model;

class Claim extends Model
{
    use HasTablePrefix;

    public const REWARDED = ['success', 'denied'];

    protected $prefix = 'voteguard_';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'site_id',
        'vote_id',
        'outcome',
        'pendings',
        'ip',
        'asn',
        'country',
        'user_agent',
        'authenticated',
        'flags',
        'created_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'flags' => 'array',
        'authenticated' => 'boolean',
        'asn' => 'integer',
        'pendings' => 'integer',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
