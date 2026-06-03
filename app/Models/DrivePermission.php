<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DrivePermission extends Model
{
    use BelongsToCompany;
    use HasFactory;

    public const RESOURCE_FOLDER = 'folder';

    public const RESOURCE_FILE = 'file';

    public const SUBJECT_USER = 'user';

    public const SUBJECT_ROLE = 'role';

    public const EFFECT_ALLOW = 'allow';

    public const EFFECT_DENY = 'deny';

    protected $fillable = [
        'company_id',
        'resource_type',
        'resource_id',
        'subject_type',
        'subject_id',
        'ability',
        'effect',
        'created_by',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
