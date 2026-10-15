<?php

namespace NineteenNinetyFour\SignOff\Database;

use Illuminate\Database\Eloquent\Model;

class SubmissionModel extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'steps' => 'array',
        'history' => 'array',
        'snapshot' => 'array',
    ];

    public function getConnectionName()
    {
        return config('sign-off.drivers.database.connection') ?: parent::getConnectionName();
    }

    public function getTable()
    {
        return config('sign-off.drivers.database.table', 'sign_off_submissions');
    }
}
