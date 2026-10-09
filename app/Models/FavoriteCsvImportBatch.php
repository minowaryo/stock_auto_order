<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['imported_at', 'registered_count'])]
class FavoriteCsvImportBatch extends Model
{
    protected function casts(): array
    {
        return [
            'imported_at' => 'datetime',
            'registered_count' => 'integer',
        ];
    }
}
