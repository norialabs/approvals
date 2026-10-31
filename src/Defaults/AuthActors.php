<?php

declare(strict_types=1);

namespace Noria\Approvals\Defaults;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Noria\Approvals\Contracts\Actors;
use Noria\Approvals\Settings;

final class AuthActors implements Actors
{
    public function id(): int|string|null
    {
        $id = Auth::id();

        return is_int($id) || (is_string($id) && $id !== '') ? $id : null;
    }

    public function find(int|string $id): ?Model
    {
        $model = Settings::actorModel();

        return $model === null ? null : $model::query()->find($id);
    }
}
