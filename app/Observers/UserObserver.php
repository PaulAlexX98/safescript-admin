<?php

namespace App\Observers;

use App\Models\Patient;
use App\Models\User;

class UserObserver
{
    /**
     * Keep an existing patient record in sync when staff update the related
     * user through the Patients admin form.
     */
    public function updated(User $user): void
    {
        if (! $user->wasChanged('email')) {
            return;
        }

        Patient::query()
            ->where('user_id', $user->getKey())
            ->update(['email' => $user->email]);
    }
}
