<?php

namespace RoyScheepens\HexonExport\Models;

use Illuminate\Database\Eloquent\Model;

use Storage;

class OccasionImage extends Model
{
    /**
     * The table name
     * todo: make this a config setting
     * @var string
     */
    protected $table = 'hexon_occasion_images';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'occasion_id',
        'resource_id',
        'filename'
    ];

    /**
     * The attributes that are appended to the model
     * @var array
     */
    protected $appends = [
        'path',
        'url'
    ];

    /**
     * Relations
     * ----------------------------------------
     */

    public function occasion()
    {
        return $this->belongsTo('RoyScheepens\HexonExport\Models\Occasion');
    }

    /**
     * Attributes
     * ----------------------------------------
     */

    public function getPathAttribute()
    {
        return implode('/', [
            config('hexon-export.images_storage_path') . $this->occasion->resource_id,
            $this->filename
        ]);
    }

    public function getUrlAttribute()
    {
        // todo: check this
        $url = Storage::disk('public')->url($this->path);

        return $url;
    }
}
