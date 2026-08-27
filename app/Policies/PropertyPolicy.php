<?php

namespace App\Policies;

use App\Models\Property;
use App\Models\User;
class PropertyPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'editor'], true);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Property $property): bool
    {
        return in_array($user->role, ['admin', 'editor'], true);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return in_array($user->role, ['admin', 'editor'], true);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Property $property): bool
    {
        return in_array($user->role, ['admin', 'editor'], true)
            && $property->origin === Property::ORIGIN_COMMERCIAL;
    }

    public function publish(User $user, Property $property): bool
    {
        return $this->update($user, $property)
            && $property->origin === Property::ORIGIN_COMMERCIAL;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Property $property): bool
    {
        return in_array($user->role, ['admin', 'editor'], true)
            && $property->origin === Property::ORIGIN_COMMERCIAL;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Property $property): bool
    {
        return in_array($user->role, ['admin', 'editor'], true)
            && $property->origin === Property::ORIGIN_COMMERCIAL;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Property $property): bool
    {
        return $user->role === 'admin'
            && $property->origin === Property::ORIGIN_COMMERCIAL
            && ! $property->valuations()->exists();
    }
}
