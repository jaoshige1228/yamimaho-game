<?php

namespace App\Http\Middleware;

use App\Services\Player\DemoPlayerService;
use App\Services\Player\UserCharacterService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureDemoPlayer
{
    public function __construct(
        private readonly DemoPlayerService $players,
        private readonly UserCharacterService $characters,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user === null) {
            $user = $this->players->resolveOrCreateUser();
            Auth::login($user);
        } else {
            $this->characters->ensureParty($user);
        }

        return $next($request);
    }
}
