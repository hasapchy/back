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

    public const EFFECT_ALLOW = 'allow';

    public const ABILITY_VIEW = 'view';

    public const ABILITY_CREATE = 'create';

    public const ABILITY_UPDATE = 'update';

    public const ABILITY_DELETE = 'delete';

    /** @var array<int, string> */
    public const FOLDER_ABILITIES = [
        self::ABILITY_VIEW,
        self::ABILITY_CREATE,
        self::ABILITY_UPDATE,
        self::ABILITY_DELETE,
    ];

    /** @var array<int, string> */
    public const FILE_ABILITIES = [
        self::ABILITY_VIEW,
        self::ABILITY_UPDATE,
        self::ABILITY_DELETE,
    ];

    /**
     * @return array<int, string>
     */
    public static function abilitiesForResourceType(string $resourceType): array
    {
        return $resourceType === self::RESOURCE_FILE
            ? self::FILE_ABILITIES
            : self::FOLDER_ABILITIES;
    }

    /**
     * @return string
     */
    public static function normalizeAbility(string $ability): string
    {
        $legacy = [
            'upload' => self::ABILITY_CREATE,
            'rename' => self::ABILITY_UPDATE,
            'share' => self::ABILITY_UPDATE,
        ];
        $normalized = $legacy[$ability] ?? $ability;

        return $normalized === 'edit' ? self::ABILITY_UPDATE : $normalized;
    }

    /**
     * @param  array<int, string>  $abilities
     * @return array<int, string>
     */
    public static function sortAbilities(array $abilities, string $resourceType): array
    {
        $order = array_flip(self::abilitiesForResourceType($resourceType));
        $normalized = array_values(array_unique(array_map(
            static fn (string $ability) => self::normalizeAbility($ability),
            $abilities
        )));
        usort(
            $normalized,
            static fn (string $left, string $right) => ($order[$left] ?? 99) <=> ($order[$right] ?? 99)
        );

        return array_values(array_filter(
            $normalized,
            static fn (string $ability) => isset($order[$ability])
        ));
    }

    /** @var array<int, string> */
    public const DEPENDENT_ON_VIEW_ABILITIES = [
        self::ABILITY_CREATE,
        self::ABILITY_UPDATE,
        self::ABILITY_DELETE,
    ];

    /**
     * @param  array<int, string>  $abilities
     * @return array<int, string>
     */
    public static function expandAbilityDependencies(array $abilities, string $resourceType): array
    {
        $sorted = self::sortAbilities($abilities, $resourceType);
        $hasDependent = array_intersect($sorted, self::DEPENDENT_ON_VIEW_ABILITIES) !== [];
        if ($hasDependent && ! in_array(self::ABILITY_VIEW, $sorted, true)) {
            $sorted[] = self::ABILITY_VIEW;
        }

        return self::sortAbilities($sorted, $resourceType);
    }

    /**
     * @param  array<int, string>  $abilities
     * @return array<int, string>
     */
    public static function stripDependentAbilitiesWithoutView(array $abilities, string $resourceType): array
    {
        $sorted = self::sortAbilities($abilities, $resourceType);
        if (in_array(self::ABILITY_VIEW, $sorted, true)) {
            return $sorted;
        }

        return [];
    }

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

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function subject()
    {
        return $this->belongsTo(User::class, 'subject_id');
    }
}
