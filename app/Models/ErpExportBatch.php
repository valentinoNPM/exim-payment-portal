<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ErpExportBatch extends Model
{
    use HasFactory;

    protected $table = 'erp_export_batches';

    protected $fillable = [
        'batch_number',
        'format_code',
        'approval_date_from',
        'approval_date_to',
        'file_path',
        'created_by',
        'exported_at',
    ];

    protected $casts = [
        'approval_date_from' => 'date',
        'approval_date_to' => 'date',
        'exported_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function exportItems(): HasMany
    {
        return $this->hasMany(ErpExportItem::class);
    }
}
