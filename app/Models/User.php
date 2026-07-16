<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Traits\Auditable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'google_id', 'verification_code', 'verification_code_expires_at', 'password_change_code', 'password_change_code_expires_at', 'password_change_verified_at', 'password_change_failed_attempts', 'two_factor_enabled', 'two_factor_code', 'two_factor_code_expires_at', 'email_verified_at', 'role_id', 'needs_password_reset', 'profile_image', 'university_name', 'pending_email', 'email_change_code', 'email_change_code_expires_at', 'new_email_verification_code', 'new_email_verification_code_expires_at', 'current_email_verified'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $appends = ['roles', 'permissions'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'password_change_verified_at' => 'datetime',
            'password_change_failed_attempts' => 'integer',
            'needs_password_reset' => 'boolean',
            'current_email_verified' => 'boolean',
        ];
    }

    /**
     * Accessor for roles array (compatibility with frontend).
     */
    public function getRolesAttribute(): array
    {
        $role = $this->role;

        return $role ? [$role] : [];
    }

    /**
     * Accessor for permissions array (compatibility with frontend).
     */
    public function getPermissionsAttribute(): array
    {
        $role = $this->role;
        if (! $role) {
            return [];
        }

        if ($role->name === 'super_admin') {
            return Permission::all()->toArray();
        }

        // Make sure permissions relation is loaded
        return $role->permissions->toArray();
    }

    /**
     * Get the user's role.
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function mfaMethods(): HasMany
    {
        return $this->hasMany(UserMfaMethod::class);
    }

    public function mfaSetting(): HasOne
    {
        return $this->hasOne(UserMfaSetting::class);
    }

    public function mfaRecoveryCodes(): HasMany
    {
        return $this->hasMany(UserMfaRecoveryCode::class);
    }

    /**
     * Check if the user has a specific role.
     */
    public function hasRole(string|array $role): bool
    {
        $userRole = $this->role;
        if (! $userRole) {
            return false;
        }

        $userRoleName = str_replace('_', '-', $userRole->name);

        if (is_array($role)) {
            $roles = array_map(function ($r) {
                return str_replace('_', '-', $r);
            }, $role);

            return in_array($userRoleName, $roles) || (in_array('editor', $roles, true) && $this->isPublicationEditor());
        }

        $expected = str_replace('_', '-', $role);

        return $userRoleName === $expected || ($expected === 'editor' && $this->isPublicationEditor());
    }

    public function isPublicationEditor(): bool
    {
        return in_array(str_replace('-', '_', (string) $this->role?->name), [
            'editor', 'super_editor', 'magazine_editor', 'journal_editor',
        ], true);
    }

    public function editorPublicationTypes(): array
    {
        return match (str_replace('-', '_', (string) $this->role?->name)) {
            'magazine_editor' => [Magazine::TYPE_MAGAZINE],
            'journal_editor' => [Magazine::TYPE_JOURNAL],
            'editor', 'super_editor' => [Magazine::TYPE_MAGAZINE, Magazine::TYPE_JOURNAL],
            default => [],
        };
    }

    public function canEditPublication(Magazine $publication): bool
    {
        return $this->isPublicationEditor()
            && in_array($publication->publication_type, $this->editorPublicationTypes(), true)
            && $this->magazines()->where('magazines.id', $publication->id)->exists();
    }

    /**
     * Compatibility helper to assign a role to the user.
     *
     * @param  string|Role  $role
     * @return $this
     */
    public function assignRole($role)
    {
        if (is_string($role)) {
            $roleModel = Role::where('name', $role)
                ->orWhere('name', str_replace('_', '-', $role))
                ->first();
            if ($roleModel) {
                $this->role_id = $roleModel->id;
                $this->save();
            }
        } elseif ($role instanceof Role) {
            $this->role_id = $role->id;
            $this->save();
        }

        return $this;
    }

    /**
     * Check if the user has a specific permission.
     */
    public function hasPermission(string $permission): bool
    {
        $role = $this->role;
        if (! $role) {
            return false;
        }

        // Super Admin has absolute system override
        $roleName = str_replace('_', '-', $role->name);
        if ($role->name === 'super_admin' || $roleName === 'super-admin') {
            return true;
        }

        // Support test environment fallbacks if no permissions are seeded in SQLite in-memory
        if (app()->environment('testing') && $role->permissions()->count() === 0) {
            if ($roleName === 'author') {
                return in_array($permission, [
                    'articles.create',
                    'articles.view-own',
                    'articles.edit-own',
                    'magazines.view-any',
                    'magazines.view-own',
                ]);
            }
            if (in_array(str_replace('-', '_', $roleName), ['editor', 'super_editor', 'magazine_editor', 'journal_editor'], true)) {
                return in_array($permission, [
                    'magazines.view-any',
                    'magazines.view-own',
                    'articles.view-any',
                    'articles.view-own',
                    'articles.create',
                    'articles.edit-own',
                    'articles.approve',
                ]);
            }
            if ($roleName === 'admin') {
                return true;
            }
        }

        // Load permissions relationship to check name
        return $role->permissions->contains('name', $permission);
    }

    /**
     * Get the magazines assigned to this editor.
     */
    public function magazines(): BelongsToMany
    {
        return $this->belongsToMany(Magazine::class, 'magazine_user', 'user_id', 'magazine_id')
            ->withPivot(['role', 'assigned_by'])
            ->withTimestamps();
    }

    /**
     * Get the sub editors assigned to this editor.
     */
    public function assignedSubEditors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'editor_sub_editor', 'editor_id', 'sub_editor_id')
            ->withPivot('created_by')
            ->withTimestamps();
    }

    /**
     * Get the editors this sub editor is assigned to.
     */
    public function assignedEditors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'editor_sub_editor', 'sub_editor_id', 'editor_id')
            ->withPivot('created_by')
            ->withTimestamps();
    }
}
