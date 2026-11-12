<?php

namespace App\Models;

use App\Enums\ProcurementCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcurementType extends Model
{
    protected $fillable = [
        'code',
        'name',
        'category',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'category' => ProcurementCategory::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ProcurementTypeDocument::class)->orderBy('sort_order')->orderBy('id');
    }

    public function cases(): HasMany
    {
        return $this->hasMany(ProcurementCase::class, 'procurement_type_id');
    }
}
