<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiModelPrice extends Model
{
    protected $fillable = ['provider', 'model', 'input_per_million', 'output_per_million'];

    protected function casts(): array
    {
        return ['input_per_million' => 'float', 'output_per_million' => 'float'];
    }
}
