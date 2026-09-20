<?php

namespace Azuriom\Plugin\VoteGuard\Models;

use Azuriom\Models\Traits\HasTablePrefix;
use Azuriom\Models\User;
use Illuminate\Database\Eloquent\Model;

class Suspect extends Model
{
    use HasTablePrefix;

    protected $prefix = 'voteguard_';

    protected $fillable = [
        'user_id',
        'score',
        'max_score',
        'votes_analyzed',
        'status',
        'last_flags',
        'note',
        'reviewed_by',
        'reviewed_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'last_flags' => 'array',
        'score' => 'integer',
        'max_score' => 'integer',
        'votes_analyzed' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function detections()
    {
        return $this->hasMany(Detection::class, 'user_id', 'user_id');
    }

    public function isLocked(): bool
    {
        return in_array($this->status, ['confirmed', 'false_positive'], true);
    }
}
