<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KnowledgeChunk extends Model
{
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'property_id', 'document_id', 'version_id', 'ordinal', 'content', 'keywords'];
}
