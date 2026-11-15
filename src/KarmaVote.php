<?php
namespace Funseco\Karma;

use Illuminate\Database\Eloquent\Model;

/** A player's karma vote on a map: -3 (waste) .. +3 (fantastic). */
class KarmaVote extends Model
{
    protected $table = 'karma_votes';
    protected $guarded = ['id'];
    protected $casts = ['score' => 'integer'];
}
